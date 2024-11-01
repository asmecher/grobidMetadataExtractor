# grobidMetadataExtractor
An experimental plugin integrating Grobid for early automated metadata extraction from OJS submission files.

This is a work in progress (!) and should not be used in production.

Known issues:
- Needs a configuration form rather than a hard-coded localhost URL for Grobid
- Does not have good coverage of Grobid-produced metadata -- just authors (with limited data) at the moment.
- Does not refresh the client-side state after file upload, before the author list is presented.
- Needs to support a unoconv converter, as almost nobody is going to directly provide PDFs, until/unless Grobid supports more formats.
