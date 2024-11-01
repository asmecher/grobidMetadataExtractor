# grobidMetadataExtractor
An experimental plugin integrating Grobid for early automated metadata extraction from OJS submission files.

This is a work in progress (!) and should not be used in production.

Known issues:
- Does not have good coverage of Grobid-produced metadata -- just authors (with limited data) at the moment.
- Does not refresh the client-side state after file upload, before the author list is presented.
