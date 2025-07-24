# grobidMetadataExtractor
An experimental plugin integrating Grobid for early automated metadata extraction from OJS submission files.

This is a work in progress (!) and should not be used in production.

Known issues:
- Does not refresh the client-side state after file upload, before the author list is presented.

# Configuration additions

The `config.inc.php` configuration file should be configured with details for both Unoconv (or equivalent) and Grobid.

```
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
; Grobid Metadata Extractor ;
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;

[grobidMetadataExtractor]

; Path to the Unoconv binary that can be used to convert documents to PDF for Grobid ingestion.
unoconv = /usr/bin/unoconv

; When set to On, submissions will be re-grobidded every time a submission file is edited.
; (Otherwise, they are stamped the first time a conversion is completed, and will not be touched subsequently.)
repeat = On

; URL to the Grobid web service.
grobid_api_url = "http://localhost:8070/api/processHeaderDocument"
```
