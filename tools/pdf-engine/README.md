# NONOX PDF engine

An optional helper that lets NONOX read, render, stamp, extend, seal and check
**existing** PDFs: the fields a signer fills in, the certificate pages appended
to a signed document, and the PAdES seal with an RFC 3161 timestamp. dompdf
still makes NONOX's own new PDFs (ADR-002). Decision and scope:
[ADR-046, amendment 2026-11-18](../../docs/adr/ADR-046-crm-signing.md), gap 5,
owner decisions 3, 4 and 22, package S5.

Without the helper NONOX works as before; the screens that need it say
"Kräver PDF-motorn". Install steps for self-hosters (Swedish):
[NONOX i egen drift](../../docs/juridik/egen-drift.md#pdf-motorn-för-signering-valfri).
Operator runbook: [docs/drift/pdf-motorn.md](../../docs/drift/pdf-motorn.md).

## Files

| File | What |
|---|---|
| `nonox_pdf_engine.py` | The helper (AGPL-3.0-or-later, NONOX's own code). One JSON request on stdin, one JSON answer on stdout. |
| `requirements.in` | The two direct dependencies. |
| `requirements.txt` | The lock: every package pinned to one version with its SHA-256 hashes. Install only with `--require-hashes`. |
| `../../scripts/fetch-signing-fonts.sh` | Fetches the Noto fonts the stamps use, pinned by SHA-256. |
| `../../app/Domain/Signing/Pdf/` | The PHP side: the `PdfEngine` contract, `SidecarPdfEngine`, `NullPdfEngine`, `FakePdfEngine`. |

## Install (development)

```bash
python3 -m venv tools/pdf-engine/.venv           # Python 3.11 or newer; the folder is gitignored
tools/pdf-engine/.venv/bin/pip install --require-hashes --only-binary=:all: -r tools/pdf-engine/requirements.txt
scripts/fetch-signing-fonts.sh                    # into storage/app/pdf-engine-fonts
php artisan signing:engine-doctor                 # versions, fonts and a self-test
```

`NONOX_PDF_ENGINE=auto` (the default) picks the engine up when
`NONOX_PDF_ENGINE_PYTHON` (default `tools/pdf-engine/.venv/bin/python`) exists.
The test suite sets `NONOX_PDF_ENGINE=none`; `tests/Feature/Signing/PdfEngineRealTest.php`
runs the real helper and skips when it is not installed.

## Protocol

PHP runs `[<python>, "-I", "nonox_pdf_engine.py"]` as an argument list (never a
shell string) with `Illuminate\Support\Facades\Process`, a wall-clock timeout
(default 60 s), an emptied environment (no `APP_KEY`, no database password),
and the working directory, `HOME` and `TMPDIR` set to a private work directory
(`0700`) made for that one call and removed after it. Inputs are checked in PHP
(regular file, not a link, at most 20 MB, `%PDF-` or the PNG signature) and
copied into the work directory; the helper refuses any path outside it.

Request (stdin): `{"protocol": 1, "op": "...", "work_dir": "...", "fonts_dir": "...",
"max_bytes": 20971520, "max_pages": 100, "memory_mb": 2048, "cpu_seconds": 65, ...}`
plus the operation's fields; a seal's PKCS#12 and password are base64 in
`"secrets"` and travel nowhere else.

Answer (stdout): `{"ok": true, "result": {...}}` with exit 0, or
`{"ok": false, "error": {"code": "...", "message": "..."}}` with exit 2. Any other
exit is a crash. stderr is not part of the protocol and is never logged.

| Operation | What it does |
|---|---|
| `health` | Python, pyHanko, pypdfium2 and protocol versions; which fonts are present. |
| `selftest` | Builds a one-page PDF and runs inspect, render, stamp, append, seal (B-B with a throwaway key), validate and add_validation_data on it. |
| `inspect` | Size, SHA-256, pages and sizes, PDF version, revisions, signatures; JavaScript, launch actions, embedded files, XFA and external actions (`SubmitForm`, `ImportData`, `GoToR`, `GoToE`, `RichMedia`), found by walking every object reachable from the trailer, `/Parent` included. Refuses encrypted files and files over the limits. |
| `render_pages` | PNG per page with PDFium, at most 4 000 000 pixels per page. |
| `stamp` | Text (Noto Sans, or Noto Sans CJK for Chinese, Japanese and Korean, shaped by HarfBuzz and embedded as a subset) and PNG images, as an incremental update. |
| `append_pages` | Appends another PDF's pages (the certificate) as an incremental update. |
| `seal` | PAdES signature (`/ETSI.CAdES.detached`) at B-B, or B-T with a timestamp from the first configured server that answers. B-LT and B-LTA are the next two operations (S11). |
| `timestamp` | A document timestamp (RFC 3161) as an incremental update. |
| `add_validation_data` | Since S11 (protocol 1, additive). Long-term validation, B-LT: a DSS with a VRI entry per signature, holding the certificates, OCSP answers and CRLs for every signature and timestamp in the file, as one incremental update. Optional `fetch_timeout` (1–60 s, default 10). Answers `timestamps`: every timestamp in the written file, oldest first, as `{kind: "signature"\|"document", at, certificate_not_after, md_algorithm}`. Refuses a file without a signature (`invalid_request`); answers `validation_data_unavailable`, or `certificate_revoked` for a certificate its issuer has revoked, and writes nothing when the data cannot be collected. |
| `archive_timestamp` | Since S11. B-LTA: the validation data of everything in the file (the newest timestamp included), then a document timestamp from the first configured server that answers, as incremental updates. Takes `timestamp_urls`, `timestamp_timeout`, `fetch_timeout` and `md_algorithm` (`sha256`, `sha384` or `sha512`); answers like `add_validation_data`, plus `timestamp_url`. `validation_data_unavailable` or `timestamp_unavailable` when a step cannot be done. |
| `validate` | Integrity, cryptographic validity and coverage of every signature and document timestamp, with no trust roots and no network. |

Every writing operation checks before it answers that the output starts with
the input's exact bytes; otherwise it deletes the output and answers
`not_incremental`.

The helper caps its own memory (`RLIMIT_AS`) and CPU time (`RLIMIT_CPU`) where
the operating system allows it (Linux; macOS ignores `RLIMIT_AS`). It makes no
network calls except to the timestamp servers named in a `seal`, `timestamp`
or `archive_timestamp` request, which NONOX takes from instance configuration
only (`NONOX_SIGNING_TIMESTAMP_URLS`; any other URL is refused in PHP), and,
for `add_validation_data` and `archive_timestamp`, to the OCSP, CRL and
issuer-certificate addresses that the certificates in the file name. Those
addresses come from certificates a company may upload, so only http(s) to a
public address is fetched: the resolver drops loopback, private, link-local and
other non-global addresses (Python's `ipaddress.is_global`), an address written
as a number is checked before connecting, redirects are never followed, an
answer larger than 20 MB is refused (when announced, and in any case while it is read), and pyhanko-certvalidator's
`FetchLimits` cap the requests (40 in all, 10 for issuer certificates, 2 URLs
per object). Redirects from a timestamp server are never followed either, and
a timestamp answer is capped at 1 MB.

Validation data is collected, not judged: the self-issued certificates found in
the file's own CMS structures are accepted as path ends besides the operating
system's trust list, so a self-signed seal or test TSA gets its certificate in
the DSS. Revocation checking is hard-fail, so nothing is written when an
address does not answer. A chain whose root is neither in the file nor in the
operating system's trust list cannot be collected, and the file stays at B-T.

## Versions and licences (verified 2026-11-18)

Read from each package's PyPI metadata (`https://pypi.org/pypi/<name>/<version>/json`)
and from the metadata of the installed wheels (`importlib.metadata`). The lock
was compiled with pip-tools and `pip --uploaded-prior-to=2026-11-04`, so no
release younger than two weeks was chosen.

| Package | Version | Licence | Why it is there |
|---|---|---|---|
| pyHanko | 0.38.1 | MIT | Incremental writer, stamps, PAdES signing, timestamps, validation |
| pyhanko-certvalidator | 0.33.0 | MIT | Certificate path handling for pyHanko |
| pypdfium2 | 5.14.0 | Apache-2.0 or BSD-3-Clause (binding); bundled PDFium BSD-3-Clause, and its bundled libraries under their own permissive licences, listed in the wheel's `licenses/` folder | Page geometry and rendering |
| asn1crypto | 1.5.1 | MIT | ASN.1 (pyHanko) |
| cryptography | 50.0.2 | Apache-2.0 or BSD-3-Clause | Keys, PKCS#12, hashes |
| cffi | 2.1.1 | MIT-0 | cryptography |
| pycparser | 3.12 | BSD-3-Clause | cffi |
| oscrypto | 1.3.0 | MIT | certvalidator |
| uritools | 6.1.3 | MIT | certvalidator |
| tzlocal | 5.4.5 | MIT | pyHanko |
| lxml | 6.1.3 | BSD-3-Clause | pyHanko (XMP metadata) |
| aiohttp | 3.14.5 | Apache-2.0 and MIT | pyHanko's HTTP timestamp client |
| aiohappyeyeballs | 2.7.1 | PSF-2.0 | aiohttp |
| aiosignal | 1.4.0 | Apache-2.0 | aiohttp |
| attrs | 26.1.0 | MIT | aiohttp |
| frozenlist | 1.8.0 | Apache-2.0 | aiohttp |
| multidict | 7.2.1 | Apache-2.0 | aiohttp |
| propcache | 0.5.4 | Apache-2.0 | aiohttp |
| yarl | 1.25.1 | Apache-2.0 | aiohttp |
| idna | 3.20 | BSD-3-Clause | yarl |
| certifi | 2026.10.23 | MPL-2.0 | Mozilla's CA bundle for HTTPS to timestamp servers |
| fonttools | 4.68.0 | MIT | Font subsetting (pyHanko `opentype` extra) |
| uharfbuzz | 0.57.0 | Apache-2.0 | Text shaping (pyHanko `opentype` extra) |
| pillow | 13.0.0 | MIT-CMU (HPND) | PNG stamps (pyHanko `image-support` extra) |
| python-barcode | 0.16.1 | MIT | Pulled in by the `image-support` extra |

Fonts (fetched by `scripts/fetch-signing-fonts.sh`, never committed): Noto Sans
2.015 and Noto Sans CJK 2.004, SIL Open Font License 1.1, which allows
embedding and redistribution.

All of these are compatible with AGPL-3.0: MIT, MIT-0, MIT-CMU, BSD-3-Clause,
Apache-2.0 (compatible with GPL-3.0 and so with AGPL-3.0), PSF-2.0 (GPL-compatible),
MPL-2.0 (its section 3.3 allows combination with the GNU licences) and OFL-1.1
for the fonts. The helper is also a separate program that NONOX runs as a
subprocess; nothing of it is linked into PHP, and none of the packages is in
the repository.

### Library calls relied on

Read in the installed sources (`site-packages/pyhanko`, `site-packages/pypdfium2`)
before use, and exercised by `PdfEngineRealTest`:

- `pypdfium2.PdfDocument(bytes)`, `len()`, `PdfPage.get_size()`, `get_rotation()`,
  `render(scale=...)`, `PdfBitmap.to_pil()`; `PdfiumError` for a password.
- `pyhanko.pdf_utils.reader.PdfFileReader(stream, strict=False)`: `encrypted`,
  `embedded_signatures`, `total_revisions`, `input_version`, `trailer_view`, `root`,
  `find_page_for_modification`.
- `pyhanko.pdf_utils.incremental_writer.IncrementalPdfFileWriter(stream, strict=False)`;
  `import_page_as_xobject`, `insert_page`, `add_object`; `writer.PageObject`,
  `writer.PdfFileWriter` (self-test only).
- `pyhanko.stamp.TextStamp`/`TextStampStyle` and `StaticContentStamp`/`StaticStampStyle`
  with `.apply(page_index, x, y)` (the same path as `pyhanko.stamp.text_stamp_file`,
  which writes through `IncrementalPdfFileWriter`); `pdf_utils.text.TextBoxStyle`,
  `pdf_utils.font.opentype.GlyphAccumulatorFactory(font_file, font_size)`,
  `pdf_utils.images.PdfImage`, `pdf_utils.layout`.
- Since ADR-046 S6, a text stamp may carry a `box` (`width`, `height` in points):
  `TextStamp(writer, style, text_params, box=layout.BoxConstraints(width, height))`
  with `SimpleBoxLayoutRule(..., inner_content_scaling=layout.InnerScaling.SHRINK_TO_FIT)`.
  The stamp is a form XObject whose BBox is the box, and PDF clips a form XObject
  to its BBox. **Not yet read in the installed sources or exercised by
  `PdfEngineRealTest`**: the S6 builder had no engine installation (and may not
  install one); the next installation must confirm the `box` keyword of
  `TextStamp` and `InnerScaling.SHRINK_TO_FIT` in pyHanko 0.38.1 and add a real test.
- Since ADR-046 S8, `op_stamp` adds the page's crop-box origin to every stamp's x and y
  (`page_origin`: `/CropBox` and `/MediaBox`, searched up the page tree through
  `writer.find_page_for_modification(index)` and `/Parent`, the larger corner of the two;
  (0, 0) with a warning on stderr when a box is missing or malformed; only KeyError, TypeError and
  ValueError are caught). Fields are measured from the page as the field editor and the
  page images show it, the crop box, and PDF draws in user space, whose origin is the media box's.
  Pages whose crop box starts at (0, 0), which is nearly every page, are unchanged. **Not exercised
  here**: no engine installation was available. The self-test (`crop_origin`: a page with
  `/CropBox [50 50 400 600]` gives the origin (50, 50) and a stamp at (0, 0) is made) and
  `PdfEngineRealTest` ("stamps from the crop box": renders the stamped page and looks for ink in its
  lower left corner) were written but have never been run; the next installation must run them and
  confirm `writer.root['/Pages']['/Kids'][0]` in the self-test's page builder.
- `pyhanko.sign.signers.SimpleSigner.load_pkcs12_data(bytes, other_certs, passphrase)`,
  `PdfSignatureMetadata(field_name, md_algorithm, subfilter=fields.SigSeedSubFilter.PADES, reason, location)`,
  `signers.sign_pdf(writer, meta, signer=, timestamper=, output=)`,
  `PdfTimeStamper(timestamper).timestamp_pdf(writer, "sha256", output=)`.
- `pyhanko.sign.timestamps.HTTPTimeStamper(url, timeout=)` (aiohttp since pyHanko 0.37).
- `pyhanko.sign.validation.validate_pdf_signature(embedded, context)` and
  `validate_pdf_timestamp(embedded, validation_context=)`; status fields `intact`,
  `valid`, `coverage`, `timestamp_validity`, `signing_cert`;
  `pyhanko_certvalidator.ValidationContext(trust_roots=[], allow_fetching=False)`.

- Since ADR-046 S11 (read in the installed pyHanko 0.38.1 and pyhanko-certvalidator
  0.33.0, and exercised by `PdfEngineRealTest` with a local test TSA,
  `tests/Support/pdf-engine/test_tsa.py`): `pyhanko.sign.validation.dss.collect_validation_info(embedded, context)`
  (async) and `DocumentSecurityStore.supply_dss_in_writer(writer, sig_contents, paths=, validation_context=)`
  (the two halves of `add_validation_info`, so that every signature's data goes into one
  revision); `pyhanko.sign._session_scope.run_and_release` (releases the fetchers' session);
  `pyhanko.sign.general.extract_certificate_info`, `pyhanko.sign.validation.generic_cms.extract_tst_data_iter`;
  `ValidationContext(extra_trust_roots=, other_certs=, allow_fetching=True, revocation_mode="hard-fail", fetcher_backend=)`;
  `pyhanko_certvalidator.fetchers.aiohttp_fetchers.AIOHttpFetcherBackend(session=, per_request_timeout=, limits=FetchLimits(...))`
  with a `LazySession` subclass whose aiohttp session has a filtering `ThreadedResolver` and a
  `TraceConfig` (`on_request_start`, `on_request_redirect`, `on_request_end`), and
  `ClientSession(response_class=)` with a `ClientResponse` subclass whose `read()` reads
  `content.iter_chunked()` and stops at 20 MB: aiohttp 3.14.5's own `ClientResponse.read` takes the
  whole body before `on_response_chunk_received` fires, so a trace cannot cap it. Exercised by
  `PdfEngineRealTest` with a chunked answer without `Content-Length`
  (`tests/Support/pdf-engine/fetch_cap_check.py`).
- `pyhanko_certvalidator.errors.RevokedError` from hard-fail collection is answered as
  `certificate_revoked` (exercised with a CRL from a throwaway CA,
  `tests/Support/pdf-engine/revocation_check.py`); any other failure is
  `validation_data_unavailable`.
  `PdfTimeStamper.timestamp_pdf(writer, md_algorithm, output=)` is called without a validation
  context; the new timestamp's own validation data is added at the next renewal, the order
  pyHanko's `update_archival_timestamp_chain` uses.

### Updating a pin

Install pip-tools in a throwaway venv, then:

```bash
pip-compile --generate-hashes --strip-extras --no-header \
  --pip-args "--uploaded-prior-to=<UTC date two weeks ago>" \
  -o tools/pdf-engine/requirements.txt tools/pdf-engine/requirements.in
```

Read the new versions' changelogs, update the table above, run
`PdfEngineRealTest`, and say in the PR what changed. `signing:engine-doctor`
warns an operator whose installed pyHanko or pypdfium2 differs from the lock.
