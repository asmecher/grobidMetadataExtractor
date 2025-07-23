<?php

/**
 * @file GrobidMetadataExtractorPlugin.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Plugin to invoke an external Grobid service to extract submission metadata.
 */

namespace APP\plugins\generic\grobidMetadataExtractor;

use APP\core\Application;
use PKP\affiliation\Affiliation;
use PKP\config\Config;
use PKP\core\Registry;
use PKP\facades\Locale;
use PKP\plugins\GenericPlugin;
use PKP\db\DAORegistry;
use PKP\plugins\Hook;
use APP\facades\Repo;
use PKP\userGroup\UserGroup;
use PKP\security\Role;

class GrobidMetadataExtractorPlugin extends GenericPlugin
{
    var $supportedMimeTypes = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
        'application/vnd.oasis.opendocument.text',
        'application/pdf',
    ];

    var $convertMimeTypes = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
        'application/vnd.oasis.opendocument.text',
    ];

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled($mainContextId)) {
                Hook::add('Schema::get::submission', $this->augmentSubmissionSchema(...));
                Hook::add('SubmissionFile::edit', $this->editSubmissionFile(...));
            }
            return true;
        }
        return false;
    }

    /**
     * Add properties to the submission object.
     */
    public function augmentSubmissionSchema(string $hookName, array $params): bool
    {
        $schema =& $params[0];

        $schema->properties->grobidded = (object) [
            'type' => 'boolean',
            'description' => 'True if Grobid has already been invoked to pre-fill submission metadata',
            'validation' => ['nullable'],
        ];

        return Hook::CONTINUE;
    }

    function editSubmissionFile(string $hookName, array $args) : bool
    {
        $submissionFile =& $args[0];

        $submission = Repo::submission()->get($submissionFile->getData('submissionId'));

        $genreDao = DAORegistry::getDAO('GenreDAO');
        $genre = $genreDao->getById((int) $submissionFile->getGenreId());

        $pkpFileService = app()->get('file');
        $file = $pkpFileService->get((int) $submissionFile->getData('fileId'));

        // Do not submit to Grobid if the submission file does not look like the sort we want
        if (
            $submission->getData('submissionProgress') != 'start' ||
            ($submission->getData('grobidded') && !Config::getVar('grobidMetadataExtractor', 'repeat')) ||
            $submissionFile->getFileStage() != $submissionFile::SUBMISSION_FILE_SUBMISSION ||
            $genre->getKey() != 'SUBMISSION' ||
            !$file ||
            !in_array($file->mimetype, $this->supportedMimeTypes)
        ) return Hook::CONTINUE;

        // Some files must be converted to PDF before Grobid can be executed on them.
        if (in_array($file->mimetype, $this->convertMimeTypes)) {
            // Copy the uploaded file into the temp directory (in case flysystem is using e.g. a remote filesystem)
            $inputFilePath = tempnam(sys_get_temp_dir(), 'unoconv');
            file_put_contents($inputFilePath, $pkpFileService->fs->read($file->path));

            // Create an output filename for unoconv to use
            $convertedFilePath = tempnam(sys_get_temp_dir(), 'unoconv');
            unlink($convertedFilePath);
            $convertedFilePath .= '.pdf';

            $output = $result_code = null;
            exec(
                Config::getVar('grobidMetadataExtractor', 'unoconv', '/usr/bin/unoconv') . ' -o ' . escapeshellarg($convertedFilePath) . ' ' .
                escapeshellarg($inputFilePath),
                $output, $result_code
            );
            if ($result_code != 0) {
                error_log('Grobid metadata extraction: unoconv failed to convert ' . $file->path . ' to PDF.');
                return Hook::CONTINUE;
            }
            $inputFileContents = file_get_contents($convertedFilePath);
            unlink($inputFilePath);
            unlink($convertedFilePath);
        } else {
            $inputFileContents = $pkpFileService->fs->read($file->path);
        }

        // Invoke the Grobid client.
        $client = Application::get()->getHttpClient();
        $response = $client->request(
            'POST',
            Config::getVar('grobidMetadataExtractor', 'grobid_api_url', 'http://localhost:8070/api/processHeaderDocument'),
            [
                'headers' => [
                    'Accept' => 'application/xml',
                ],
                'multipart' => [
                    [
                        'name' => 'input',
                        'contents' => $inputFileContents,
                    ],
                ],
            ],
        );

        $doc = new \DOMDocument();
        $doc->loadXML((string) $response->getBody());
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('tei', 'http://www.tei-c.org/ns/1.0');
        $xpath->registerNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');

        $primaryLocale = $xpath->query('//tei:TEI/tei:teiHeader')->item(0)->getAttribute('xml:lang');
        $currentPublication = $submission->getCurrentPublication();

        // Extract the title & abstract
        foreach ($xpath->query('//tei:TEI/tei:teiHeader/tei:fileDesc/tei:titleStmt/tei:title') as $titleNode) {
            $currentPublication->setData('title', [$primaryLocale => htmlspecialchars($titleNode->nodeValue)]);
        }
        foreach ($xpath->query('//tei:TEI/tei:teiHeader/tei:profileDesc/tei:abstract') as $abstractNode) {
            $currentPublication->setData('abstract', [$primaryLocale => htmlspecialchars($abstractNode->nodeValue)]);
        }

        // Extract author data
        foreach ($xpath->query('//tei:TEI/tei:teiHeader/tei:fileDesc/tei:sourceDesc/tei:biblStruct/tei:analytic/tei:author') as $authorNode) {
            $author = app(\APP\author\Author::class);

            $submitAsUserGroup = UserGroup::withContextIds($submission->getData('contextId'))->withRoleIds(Role::ROLE_ID_AUTHOR)->first();
            if (!$submitAsUserGroup) {
                error_log('Grobid metadata extraction: aborting; no available author submitter user group.');
                return Hook::CONTINUE;
            }
            $author->setData('userGroupId', $submitAsUserGroup->id);

            $author->setData('email', ''); // This should be removed when author emails are made optional
            $author->setData('givenName', $xpath->query('tei:persName/tei:forename[@type="first"]', $authorNode)->item(0)->nodeValue, $primaryLocale);
            $author->setData('familyName', $xpath->query('tei:persName/tei:surname', $authorNode)->item(0)->nodeValue, $primaryLocale);
            $author->setData('publicationId', $currentPublication->getId());
            Repo::author()->add($author);

            foreach ($xpath->query('tei:affiliation/tei:orgName[@type="institution"]', $authorNode) as $institutionNode) {
                $institutionName = $institutionNode->nodeValue;
                $affiliation = new Affiliation();
                $rorMatches = Repo::ror()->getCollector()->filterByName($institutionName)->getMany();
                if ($rorMatches->count() == 1) {
                    $ror = $rorMatches->first();
                    $affiliation->setRor($ror->getRor());
                    $affiliation->setName(null);
                } else {
                    $affiliation->setName($institutionName, $primaryLocale);
                }
                $affiliation->setAuthorId($author->getId());
                Repo::affiliation()->add($affiliation);
            }
        }

        Repo::publication()->edit($currentPublication, ['title', 'abstract']);

        // Stamp that the submission has been "grobidded"; we only do this once per submission.
        Repo::submission()->edit($submission, ['grobidded' => true]);

        return Hook::CONTINUE;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.grobidMetadataExtractor.name');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.grobidMetadataExtractor.description');
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\grobidMetadataExtractor\GrobidMetadataExtractorPlugin', '\GrobidMetadataExtractorPlugin');
}
