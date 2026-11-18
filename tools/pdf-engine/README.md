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
| `selftest` | Builds a one-page PDF and runs inspect, render, stamp, append, seal (B-B with a throwaway key) and validate on it. |
| `inspect` | Size, SHA-256, pages and sizes, PDF version, revisions, signatures; JavaScript, launch actions, embedded files, XFA and external actions (`SubmitForm`, `ImportData`, `GoToR`, `GoToE`, `RichMedia`), found by walking every object reachable from the trailer, `/Parent` included. Refuses encrypted files and files over the limits. |
| `render_pages` | PNG per page with PDFium, at most 4 000 000 pixels per page. |
| `stamp` | Text (Noto Sans, or Noto Sans CJK for Chinese, Japanese and Korean, shaped by HarfBuzz and embedded as a subset) and PNG images, as an incremental update. |
| `append_pages` | Appends another PDF's pages (the certificate) as an incremental update. |
| `seal` | PAdES signature (`/ETSI.CAdES.detached`) at B-B, or B-T with a timestamp from the first configured server that answers. B-LT and B-LTA come with S11. |
| `timestamp` | A document timestamp (RFC 3161) as an incremental update. |
| `validate` | Integrity, cryptographic validity and coverage of every signature and document timestamp, with no trust roots and no network. |

Every writing operation checks before it answers that the output starts with
the input's exact bytes; otherwise it deletes the output and answers
`not_incremental`.

The helper caps its own memory (`RLIMIT_AS`) and CPU time (`RLIMIT_CPU`) where
the operating system allows it (Linux; macOS ignores `RLIMIT_AS`). It makes no
network calls except to the timestamp servers named in a `seal` or `timestamp`
request, which NONOX takes from instance configuration only
(`NONOX_SIGNING_TIMESTAMP_URLS`; any other URL is refused in PHP). Redirects
are never followed, and a timestamp answer is capped at 1 MB.

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
- `pyhanko.sign.signers.SimpleSigner.load_pkcs12_data(bytes, other_certs, passphrase)`,
  `PdfSignatureMetadata(field_name, md_algorithm, subfilter=fields.SigSeedSubFilter.PADES, reason, location)`,
  `signers.sign_pdf(writer, meta, signer=, timestamper=, output=)`,
  `PdfTimeStamper(timestamper).timestamp_pdf(writer, "sha256", output=)`.
- `pyhanko.sign.timestamps.HTTPTimeStamper(url, timeout=)` (aiohttp since pyHanko 0.37).
- `pyhanko.sign.validation.validate_pdf_signature(embedded, context)` and
  `validate_pdf_timestamp(embedded, validation_context=)`; status fields `intact`,
  `valid`, `coverage`, `timestamp_validity`, `signing_cert`;
  `pyhanko_certvalidator.ValidationContext(trust_roots=[], allow_fetching=False)`.

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
