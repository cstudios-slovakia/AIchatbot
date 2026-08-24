# Release Notes for Interactive AI Assistant

## Unreleased

- A trained index can now be moved between installs. **Training → Transfer**
  (and `rag/export` / `rag/import`) writes every trained source, its vectors and
  the documents behind them to one gzipped bundle, and reads one back — so a
  site can be trained on a local copy and go live already trained, instead of
  re-embedding everything on a site that is already serving visitors. Content is
  matched by element UID and site handle rather than by the ids it had where it
  was trained, and anything the target does not have is reported instead of
  being attached to the wrong page. Bundles from a different embedding model are
  refused unless imported with `--reembed`, which embeds the content on the
  target instead.
- Encrypted PDFs whose own permissions allow text extraction are now indexed,
  via `pdftotext` or `qpdf` when either is installed, instead of failing with
  "Secured pdf file are currently not supported". A PDF that needs a password to
  open still reports that it cannot be read.
- Running headers, footers and watermarks repeated on every page of a PDF are
  dropped before indexing, so they stop matching every query.
- A failed file upload no longer reloads the page out from under its own error
  message. Failures stay on screen until the next upload run, and a batch where
  some files worked says which ones did not.
- Upload errors say what actually went wrong: the file's real size against the
  real limit, PHP's own `upload_max_filesize`/`post_max_size` when either is
  stricter, a web server 413, an unwritable upload directory, or a response that
  was not JSON — which previously stalled the queue on "Uploading…" forever.

## 1.0.0

- Initial release.
