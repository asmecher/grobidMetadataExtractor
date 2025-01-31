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

class GrobidMetadataExtractorPlugin extends GenericPlugin
{
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
//          $submission->getData('grobidded') || // FIXME Disabled for dev
            $submissionFile->getFileStage() != $submissionFile::SUBMISSION_FILE_SUBMISSION ||
            $genre->getKey() != 'SUBMISSION' ||
            !$file
        ) return Hook::CONTINUE;

        $client = Application::get()->getHttpClient();
        $response = $client->request(
            'POST',
            'http://localhost:8070/api/processHeaderDocument', // FIXME
            [
                        'headers' => [
                            'Accept' => 'application/xml',
                        ],
                'multipart' => [
                    [
                        'name' => 'input',
                        'contents' => $pkpFileService->fs->read($file->path),
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
        foreach ($xpath->query('//tei:TEI/tei:teiHeader/tei:fileDesc/tei:sourceDesc/tei:biblStruct/tei:analytic/tei:author') as $authorNode) {
            $author = app(\APP\author\Author::class);
            $author->setData('email', '');
            $author->setData('givenName', $xpath->query('tei:persName/tei:forename[@type="first"]', $authorNode)->item(0)->nodeValue, $primaryLocale);
            $author->setData('familyName', $xpath->query('tei:persName/tei:surname', $authorNode)->item(0)->nodeValue, $primaryLocale);
            $affiliation = new Affiliation();
            $name = $xpath->query('tei:affiliation/tei:orgName', $authorNode)->item(0)->nodeValue;
            $affiliation->setName($name, $primaryLocale);
            $ror = Repo::ror()->getCollector()->filterByName($name)->getMany()->first();
            if ($ror) {
                $affiliation->setRor($ror->getRor());
                $affiliation->setName(null);
            }
            $author->setData('publicationId', $submission->getCurrentPublication()->getId());
            Repo::author()->add($author);
        }

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
