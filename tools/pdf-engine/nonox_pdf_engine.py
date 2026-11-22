# SPDX-License-Identifier: AGPL-3.0-or-later
#
# NONOX PDF engine (ADR-046, amendment 2026-11-18, package S5).
#
# A small, stateless helper that NONOX runs as a subprocess. It reads ONE JSON
# request on standard input and writes ONE JSON answer on standard output:
#
#   {"ok": true, "result": {...}}
#   {"ok": false, "error": {"code": "<machine code>", "message": "<English detail>"}}
#
# Exit status 0 means "ok", 2 means a handled refusal or failure (the JSON says
# which), anything else is a crash. PHP never builds a shell string: it runs
#
#   <python> -I nonox_pdf_engine.py
#
# with the request on stdin. -I (isolated mode) ignores PYTHON* variables, the
# user site directory and the current directory, so nothing beside this file is
# imported by accident. Every PDF goes through files inside one private work
# directory that PHP creates for the call; the request names that directory,
# and every path in the request must resolve inside it. Secrets (a PKCS#12 and
# its password) travel only on stdin, never on the command line or in a file.
#
# Every write to an existing PDF is an INCREMENTAL UPDATE: the bytes of the
# input are the first bytes of the output, so the original revision survives
# byte for byte (ADR-046 amendment, principle 2). The engine checks that before
# it answers.
#
# Library calls relied on (read in the installed pyHanko 0.38.1 and pypdfium2
# 5.14.0 sources, see README.md): pypdfium2.PdfDocument, PdfPage.get_size,
# get_rotation, render(scale=...).to_pil(); pyhanko.pdf_utils.reader.PdfFileReader
# (encrypted, embedded_signatures, total_revisions, input_version, root);
# IncrementalPdfFileWriter, import_page_as_xobject, insert_page, PageObject;
# pyhanko.stamp.TextStamp/TextStampStyle, StaticContentStamp/StaticStampStyle,
# pdf_utils.images.PdfImage, pdf_utils.font.opentype.GlyphAccumulatorFactory;
# sign.signers.SimpleSigner.load_pkcs12_data, PdfSignatureMetadata, sign_pdf,
# PdfTimeStamper.timestamp_pdf; sign.timestamps.HTTPTimeStamper;
# sign.validation.validate_pdf_signature, validate_pdf_timestamp;
# pyhanko_certvalidator.ValidationContext.
#
# Long-term validation (ADR-046 S11), read in the installed pyHanko 0.38.1 and
# pyhanko-certvalidator 0.33.0: sign.validation.dss.collect_validation_info,
# DocumentSecurityStore.supply_dss_in_writer, sign.general.extract_certificate_info,
# sign.validation.generic_cms.extract_tst_data_iter, sign._session_scope.run_and_release,
# PdfTimeStamper.async_timestamp_pdf(validation_context=...), and
# pyhanko_certvalidator.fetchers.aiohttp_fetchers.AIOHttpFetcherBackend(session, limits=FetchLimits).
#
# External signatures (ADR-046 S17, a signing provider), pyHanko's interrupted
# signing, read in the installed pyHanko 0.38.1: sign.signers.ExternalSigner
# (a fixed signature value), PdfSigner.async_digest_doc_for_signing (returns a
# PreparedByteRangeDigest with document_digest, reserved_region_start and
# reserved_region_end), Signer.signed_attrs(data_digest, 'sha256',
# use_pades=True), Signer.async_sign_prescribed_attributes (signs
# signed_attrs.dump()), and PdfTBSDocument.async_finish_signing(output,
# prepared_digest, signature_cms).

import base64
import hashlib
import io
import json
import os
import sys

PROTOCOL_VERSION = 1
MAX_REQUEST_BYTES = 8 * 1024 * 1024
OPERATIONS = (
    'health',
    'selftest',
    'inspect',
    'render_pages',
    'stamp',
    'append_pages',
    'seal',
    'timestamp',
    'add_validation_data',
    'archive_timestamp',
    'prepare_external_signature',
    'finish_external_signature',
    'validate',
)
FONT_FILES = {
    'sans': 'NotoSans-Regular.ttf',
    'sans-bold': 'NotoSans-Bold.ttf',
    'cjk': 'NotoSansCJKsc-Regular.otf',
    'cjk-bold': 'NotoSansCJKsc-Bold.otf',
}
SEAL_LEVELS = ('B_B', 'B_T')
EXTERNAL_MECHANISMS = ('sha256_rsa', 'sha256_ecdsa')
MAX_EXTERNAL_CERTIFICATES = 10
MAX_CERTIFICATE_BYTES = 16 * 1024
MAX_SIGNATURE_BYTES = 2048
# The placeholder signature value used to size the signature container: larger than any RSA-8192 or ECDSA value.
EXTERNAL_PLACEHOLDER_BYTES = 1100
# Actions that send data out of the document or open another file or resource.
EXTERNAL_ACTIONS = ('/SubmitForm', '/ImportData', '/GoToR', '/GoToE')
# The certificate pages appended to a finished file may take it past max_pages by this much.
APPENDED_PAGES_ALLOWANCE = 20


class EngineError(Exception):
    def __init__(self, code, message):
        super().__init__(message)
        self.code = code
        self.message = message


def answer(payload, status):
    sys.stdout.write(json.dumps(payload, separators=(',', ':')))
    sys.stdout.flush()
    sys.exit(status)


def apply_resource_limits(request):
    """Caps memory and CPU time where the operating system supports it (Linux)."""
    try:
        import resource
    except ImportError:  # pragma: no cover - not on POSIX
        return

    memory_mb = int_param(request, 'memory_mb', 2048, 128, 16384)
    cpu_seconds = int_param(request, 'cpu_seconds', 120, 5, 3600)

    for limit, value in ((getattr(resource, 'RLIMIT_AS', None), memory_mb * 1024 * 1024), (resource.RLIMIT_CPU, cpu_seconds)):
        if limit is None:
            continue
        try:
            resource.setrlimit(limit, (value, value))
        except (ValueError, OSError):
            # macOS refuses RLIMIT_AS; the PHP side still enforces the wall-clock timeout.
            pass


def int_param(request, key, default, minimum, maximum):
    value = request.get(key, default)
    if isinstance(value, bool) or not isinstance(value, int):
        raise EngineError('invalid_request', f'{key} must be an integer')
    if value < minimum or value > maximum:
        raise EngineError('invalid_request', f'{key} is out of range')
    return value


def number_param(item, key, minimum, maximum):
    value = item.get(key)
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise EngineError('invalid_request', f'{key} must be a number')
    if value < minimum or value > maximum:
        raise EngineError('invalid_request', f'{key} is out of range')
    return float(value)


def string_param(item, key, max_length, required=True):
    value = item.get(key)
    if value is None and not required:
        return None
    if not isinstance(value, str) or (required and value == ''):
        raise EngineError('invalid_request', f'{key} must be a string')
    if len(value) > max_length:
        raise EngineError('invalid_request', f'{key} is too long')
    return value


def work_dir(request):
    value = request.get('work_dir')
    if not isinstance(value, str) or not os.path.isabs(value):
        raise EngineError('invalid_request', 'work_dir must be an absolute path')
    real = os.path.realpath(value)
    if not os.path.isdir(real):
        raise EngineError('invalid_request', 'work_dir does not exist')
    return real


def inside(base, value, must_exist):
    if not isinstance(value, str) or value == '':
        raise EngineError('invalid_request', 'a path is missing')
    path = value if os.path.isabs(value) else os.path.join(base, value)
    real = os.path.realpath(path)
    if os.path.commonpath([base, real]) != base:
        raise EngineError('invalid_request', 'a path is outside the work directory')
    if must_exist and not os.path.isfile(real):
        raise EngineError('invalid_request', 'an input file does not exist')
    if not must_exist and os.path.exists(real):
        raise EngineError('invalid_request', 'an output file already exists')
    return real


def limits(request):
    return {
        'max_bytes': int_param(request, 'max_bytes', 20 * 1024 * 1024, 1024, 200 * 1024 * 1024),
        'max_pages': int_param(request, 'max_pages', 100, 1, 2000),
    }


def read_pdf_bytes(path, max_bytes):
    size = os.path.getsize(path)
    if size > max_bytes:
        raise EngineError('too_large', 'the PDF is larger than the limit')
    with open(path, 'rb') as handle:
        data = handle.read()
    if not data.startswith(b'%PDF-'):
        raise EngineError('not_pdf', 'the file does not start with %PDF-')
    return data


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def library_versions():
    import pyhanko
    import pypdfium2
    from pyhanko.version import __version__ as pyhanko_version

    return {
        'python': '.'.join(str(part) for part in sys.version_info[:3]),
        'pyhanko': pyhanko_version,
        'pypdfium2': getattr(pypdfium2, 'V_PYPDFIUM2', None) or _distribution_version('pypdfium2'),
        'pdfium': str(getattr(pypdfium2, 'V_LIBPDFIUM', '')) or None,
        'protocol': PROTOCOL_VERSION,
    }


def _distribution_version(name):
    from importlib.metadata import version

    return version(name)


def fonts(request):
    directory = request.get('fonts_dir')
    found = {}
    for key, file_name in FONT_FILES.items():
        path = None
        if isinstance(directory, str) and os.path.isabs(directory):
            candidate = os.path.join(os.path.realpath(directory), file_name)
            if os.path.isfile(candidate):
                path = candidate
        found[key] = path
    return found


# ---------------------------------------------------------------- operations


def needs_cjk(text):
    """True when the text holds Han, kana, Hangul or CJK punctuation (Noto Sans lacks them)."""
    for character in text:
        point = ord(character)
        if 0x2E80 <= point <= 0x9FFF or 0xAC00 <= point <= 0xD7AF or 0xF900 <= point <= 0xFAFF or 0xFF00 <= point <= 0xFFEF or 0x20000 <= point <= 0x3134F:
            return True
    return False


def op_health(request):
    available = fonts(request)
    return {
        'versions': library_versions(),
        'fonts': {key: path is not None for key, path in available.items()},
        'seal_levels': list(SEAL_LEVELS),
        'operations': list(OPERATIONS),
    }


def open_pdfium(data):
    import pypdfium2 as pdfium

    try:
        return pdfium.PdfDocument(data)
    except pdfium.PdfiumError as error:
        text = str(error).lower()
        if 'password' in text:
            raise EngineError('encrypted', 'the PDF is password-protected')
        raise EngineError('unreadable', 'PDFium could not read the PDF')


def active_content(reader):
    """Walks every object reachable from the trailer and reports active content.

    Looks at dictionaries only (never at stream data), with a visited set and a
    cap, so a hostile file cannot make it loop.
    """
    from pyhanko.pdf_utils import generic

    found = {'javascript': False, 'launch': False, 'embedded_files': False, 'xfa': False, 'external_actions': False}
    visited = set()
    stack = [reader.trailer_view]
    steps = 0

    while stack:
        steps += 1
        if steps > 500000:
            raise EngineError('unreadable', 'the PDF has too many objects to inspect')
        node = stack.pop()
        if isinstance(node, generic.IndirectObject):
            key = (node.idnum, node.generation)
            if key in visited:
                continue
            visited.add(key)
            try:
                node = node.get_object()
            except Exception:
                continue
        if isinstance(node, generic.DictionaryObject):
            keys = set(node.keys())
            subtype = node.get('/S') if '/S' in keys else None
            if '/JS' in keys or subtype == '/JavaScript':
                found['javascript'] = True
            if subtype == '/Launch':
                found['launch'] = True
            if '/EmbeddedFiles' in keys or '/EF' in keys or node.get('/Type') == '/EmbeddedFile' or node.get('/Subtype') == '/FileAttachment':
                found['embedded_files'] = True
            if '/XFA' in keys:
                found['xfa'] = True
            if subtype in EXTERNAL_ACTIONS or '/RichMedia' in keys or node.get('/Subtype') == '/RichMedia':
                found['external_actions'] = True
            # Every key is followed, /Parent included: an action can be reachable
            # only through a field's parent. The visited set stops cycles.
            for name in keys:
                stack.append(node.raw_get(name))
        elif isinstance(node, generic.ArrayObject):
            for index in range(len(node)):
                stack.append(node.raw_get(index))

    return found


def op_inspect(request):
    base = work_dir(request)
    caps = limits(request)
    path = inside(base, request.get('input'), True)
    data = read_pdf_bytes(path, caps['max_bytes'])

    from pyhanko.pdf_utils.reader import PdfFileReader

    try:
        reader = PdfFileReader(io.BytesIO(data), strict=False)
    except Exception:
        raise EngineError('unreadable', 'the PDF structure could not be read')

    if reader.encrypted:
        raise EngineError('encrypted', 'the PDF is encrypted')

    document = open_pdfium(data)
    try:
        page_count = len(document)
        if page_count < 1:
            raise EngineError('unreadable', 'the PDF has no pages')
        if page_count > caps['max_pages']:
            raise EngineError('too_many_pages', 'the PDF has more pages than the limit')
        pages = []
        for index in range(page_count):
            page = document[index]
            width, height = page.get_size()
            pages.append({'width': round(width, 2), 'height': round(height, 2), 'rotation': page.get_rotation()})
            page.close()
    finally:
        document.close()

    found = active_content(reader)
    version = reader.input_version

    return {
        'bytes': len(data),
        'sha256': sha256(data),
        'pages': page_count,
        'page_sizes': pages,
        'pdf_version': f'{version[0]}.{version[1]}' if version else None,
        'revisions': reader.total_revisions,
        'signatures': len(reader.embedded_signatures),
        'encrypted': False,
        'javascript': found['javascript'],
        'launch_actions': found['launch'],
        'embedded_files': found['embedded_files'],
        'xfa': found['xfa'],
        'external_actions': found['external_actions'],
    }


def op_render_pages(request):
    base = work_dir(request)
    caps = limits(request)
    path = inside(base, request.get('input'), True)
    data = read_pdf_bytes(path, caps['max_bytes'])
    output_prefix = string_param(request, 'output_prefix', 40)
    if not output_prefix.replace('-', '').isalnum():
        raise EngineError('invalid_request', 'output_prefix may hold letters, digits and hyphens only')
    max_pixels = int_param(request, 'max_pixels', 4_000_000, 10_000, 40_000_000)
    scale = number_param(request, 'scale', 0.1, 6.0)

    document = open_pdfium(data)
    try:
        page_count = len(document)
        if page_count > caps['max_pages']:
            raise EngineError('too_many_pages', 'the PDF has more pages than the limit')
        wanted = request.get('pages')
        if wanted is None:
            wanted = list(range(1, page_count + 1))
        if not isinstance(wanted, list) or any(isinstance(n, bool) or not isinstance(n, int) or n < 1 or n > page_count for n in wanted):
            raise EngineError('invalid_request', 'pages must list page numbers inside the document')

        rendered = []
        for number in wanted:
            page = document[number - 1]
            width, height = page.get_size()
            # PDFium rounds each side up, so leave one per cent for the rounding.
            effective = min(scale, 0.99 * (max_pixels / max(width * height, 1.0)) ** 0.5)
            bitmap = page.render(scale=effective)
            image = bitmap.to_pil()
            target = inside(base, f'{output_prefix}-{number:04d}.png', False)
            image.save(target, format='PNG')
            rendered.append({
                'page': number,
                'path': target,
                'width_px': image.width,
                'height_px': image.height,
                'width_pt': round(width, 2),
                'height_pt': round(height, 2),
            })
            bitmap.close()
            page.close()
    finally:
        document.close()

    return {'pages': rendered}


def ensure_incremental(original, path):
    with open(path, 'rb') as handle:
        written = handle.read()
    if not written.startswith(original):
        os.remove(path)
        raise EngineError('not_incremental', 'the output does not keep the original bytes')
    return written


def writer_for(data):
    from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter

    try:
        writer = IncrementalPdfFileWriter(io.BytesIO(data), strict=False)
    except Exception:
        raise EngineError('unreadable', 'the PDF structure could not be read')
    if writer.prev.encrypted:
        raise EngineError('encrypted', 'the PDF is encrypted')
    return writer


def page_count_of(writer):
    return int(writer.root['/Pages']['/Count'])


def page_origin(writer, index):
    """The lower left corner of a page's visible area (the crop box inside the media box), in user space.

    A stamp's x and y are measured from the page as it is shown, which is the crop box (the field editor, ADR-046 S8,
    and the signer's page images both show the crop box), but PDF draws in user space, whose origin is the media box's.
    Boxes are inherited down the page tree, so the page's ancestors are searched. A page whose boxes are missing or
    malformed is taken to start at (0, 0), as before, and a warning goes to stderr so the operator can see it.
    Only the errors that reading a box can raise are caught: anything else is a bug and must be seen.
    """
    try:
        page_ref, _resources = writer.find_page_for_modification(index)
        corners = {}
        for key in ('/CropBox', '/MediaBox'):
            node = page_ref.get_object()
            for _ in range(32):
                if key in node:
                    box = [float(value) for value in node[key]]
                    if len(box) != 4:
                        raise ValueError(f'{key} does not have four numbers')
                    corners[key] = (min(box[0], box[2]), min(box[1], box[3]))
                    break
                if '/Parent' not in node:
                    break
                node = node['/Parent']
    except (KeyError, TypeError, ValueError) as error:
        sys.stderr.write(f'warning: the boxes of page {index + 1} could not be read ({error}); stamping from (0, 0)\n')
        return 0.0, 0.0

    crop = corners.get('/CropBox')
    media = corners.get('/MediaBox')
    if crop and media:
        return max(crop[0], media[0]), max(crop[1], media[1])
    return crop or media or (0.0, 0.0)


def op_stamp(request):
    base = work_dir(request)
    caps = limits(request)
    source = inside(base, request.get('input'), True)
    target = inside(base, request.get('output'), False)
    data = read_pdf_bytes(source, caps['max_bytes'])
    items = request.get('items')
    if not isinstance(items, list) or len(items) < 1 or len(items) > 500:
        raise EngineError('invalid_request', 'items must list 1 to 500 stamps')

    from pyhanko.pdf_utils import layout
    from pyhanko.pdf_utils.font.opentype import GlyphAccumulatorFactory
    from pyhanko.pdf_utils.images import PdfImage
    from pyhanko.pdf_utils.text import TextBoxStyle
    from pyhanko.stamp import StaticContentStamp, StaticStampStyle, TextStamp, TextStampStyle

    available = fonts(request)
    writer = writer_for(data)
    pages = page_count_of(writer)
    if pages > caps['max_pages']:
        raise EngineError('too_many_pages', 'the PDF has more pages than the limit')

    for item in items:
        if not isinstance(item, dict):
            raise EngineError('invalid_request', 'every stamp must be an object')
        page = item.get('page')
        if isinstance(page, bool) or not isinstance(page, int) or page < 1 or page > pages:
            raise EngineError('invalid_request', 'a stamp names a page outside the document')
        x = number_param(item, 'x', -10000, 10000)
        y = number_param(item, 'y', -10000, 10000)
        origin = page_origin(writer, page - 1)
        kind = item.get('type')

        if kind == 'text':
            text = string_param(item, 'text', 2000)
            size = int(round(number_param(item, 'font_size', 4, 72)))
            font_key = item.get('font', 'auto')
            if font_key == 'auto':
                font_key = 'cjk' if needs_cjk(text) else 'sans'
            if font_key not in FONT_FILES:
                raise EngineError('invalid_request', 'unknown font')
            font_path = available.get(font_key)
            if font_path is None:
                raise EngineError('font_missing', f'the font {font_key} is not installed')
            # A field's box (ADR-046 S6): the stamp is a form XObject whose BBox is the box, so nothing is drawn
            # outside it (PDF clips a form XObject to its BBox), and text wider than the box is shrunk to fit.
            box = item.get('box')
            constraints = None
            if box is not None:
                if not isinstance(box, dict):
                    raise EngineError('invalid_request', 'a text box must be an object')
                constraints = layout.BoxConstraints(
                    width=number_param(box, 'width', 1, 2000),
                    height=number_param(box, 'height', 1, 2000),
                )
            style = TextStampStyle(
                border_width=0,
                background=None,
                stamp_text='%(value)s',
                text_box_style=TextBoxStyle(font=GlyphAccumulatorFactory(font_path, font_size=size), font_size=size, border_width=0),
                inner_content_layout=layout.SimpleBoxLayoutRule(
                    x_align=layout.AxisAlignment.ALIGN_MIN,
                    y_align=layout.AxisAlignment.ALIGN_MIN if constraints is None else layout.AxisAlignment.ALIGN_MID,
                    margins=layout.Margins.uniform(0),
                    inner_content_scaling=layout.InnerScaling.NO_SCALING if constraints is None else layout.InnerScaling.SHRINK_TO_FIT,
                ),
            )
            TextStamp(writer=writer, style=style, text_params={'value': text}, box=constraints).apply(page - 1, x + origin[0], y + origin[1])
        elif kind == 'image':
            image_path = inside(base, item.get('path'), True)
            width = int(round(number_param(item, 'width', 1, 2000)))
            height = int(round(number_param(item, 'height', 1, 2000)))
            if os.path.getsize(image_path) > 5 * 1024 * 1024:
                raise EngineError('invalid_request', 'a stamp image is larger than 5 MB')
            from PIL import Image

            with Image.open(image_path) as opened:
                if opened.format != 'PNG':
                    raise EngineError('invalid_request', 'stamp images must be PNG')
                if opened.width * opened.height > 16_000_000:
                    raise EngineError('invalid_request', 'a stamp image has too many pixels')
                picture = opened.convert('RGBA')
            style = StaticStampStyle(
                border_width=0,
                background=PdfImage(picture),
                background_opacity=1.0,
                background_layout=layout.SimpleBoxLayoutRule(
                    x_align=layout.AxisAlignment.ALIGN_MID,
                    y_align=layout.AxisAlignment.ALIGN_MID,
                    margins=layout.Margins.uniform(0),
                ),
            )
            StaticContentStamp(writer=writer, style=style, box=layout.BoxConstraints(width=width, height=height)).apply(page - 1, x + origin[0], y + origin[1])
        else:
            raise EngineError('invalid_request', 'a stamp type must be text or image')

    with open(target, 'wb') as handle:
        writer.write(handle)

    written = ensure_incremental(data, target)
    return {'output': target, 'bytes': len(written), 'sha256': sha256(written)}


def op_append_pages(request):
    base = work_dir(request)
    caps = limits(request)
    source = inside(base, request.get('input'), True)
    appendix_path = inside(base, request.get('appendix'), True)
    target = inside(base, request.get('output'), False)
    data = read_pdf_bytes(source, caps['max_bytes'])
    appendix_data = read_pdf_bytes(appendix_path, caps['max_bytes'])

    from pyhanko.pdf_utils import generic
    from pyhanko.pdf_utils.generic import pdf_name
    from pyhanko.pdf_utils.misc import get_and_apply
    from pyhanko.pdf_utils.reader import PdfFileReader
    from pyhanko.pdf_utils.writer import PageObject

    writer = writer_for(data)
    try:
        appendix = PdfFileReader(io.BytesIO(appendix_data), strict=False)
    except Exception:
        raise EngineError('unreadable', 'the appended PDF could not be read')
    if appendix.encrypted:
        raise EngineError('encrypted', 'the appended PDF is encrypted')

    appendix_pages = int(appendix.root['/Pages']['/Count'])
    if page_count_of(writer) + appendix_pages > caps['max_pages'] + APPENDED_PAGES_ALLOWANCE:
        raise EngineError('too_many_pages', 'the finished PDF would have too many pages')

    for index in range(appendix_pages):
        page_ref, _resources = appendix.find_page_for_modification(index)
        media_box = get_and_apply(page_ref.get_object(), '/MediaBox', list, default=None)
        xobject = writer.import_page_as_xobject(appendix, page_ix=index)
        paint = writer.add_object(generic.StreamObject(stream_data=b'q /NonoxPage Do Q'))
        resources = generic.DictionaryObject({pdf_name('/XObject'): generic.DictionaryObject({pdf_name('/NonoxPage'): xobject})})
        box = [float(value) for value in (media_box or [0, 0, 595.28, 841.89])]
        writer.insert_page(PageObject(contents=paint, media_box=box, resources=resources))

    with open(target, 'wb') as handle:
        writer.write(handle)

    written = ensure_incremental(data, target)
    return {'output': target, 'bytes': len(written), 'sha256': sha256(written), 'pages': page_count_of(writer)}


def secret_bytes(request, key, allow_empty=False):
    secrets = request.get('secrets')
    if not isinstance(secrets, dict):
        raise EngineError('invalid_request', 'secrets are missing')
    value = secrets.get(key)
    if not isinstance(value, str) or (value == '' and not allow_empty):
        raise EngineError('invalid_request', f'the secret {key} is missing')
    try:
        return base64.b64decode(value, validate=True)
    except ValueError:
        raise EngineError('invalid_request', f'the secret {key} is not base64')


def timestamp_urls(request):
    urls = request.get('timestamp_urls', [])
    if not isinstance(urls, list) or len(urls) > 5:
        raise EngineError('invalid_request', 'timestamp_urls must list at most 5 URLs')
    for url in urls:
        if not isinstance(url, str) or not (url.startswith('https://') or url.startswith('http://')) or len(url) > 500:
            raise EngineError('invalid_request', 'a timestamp URL must be http(s)')
    return urls


MAX_TIMESTAMP_RESPONSE_BYTES = 1024 * 1024


def timestampers(request):
    import aiohttp
    from asn1crypto import tsp
    from pyhanko.sign.timestamps import HTTPTimeStamper
    from pyhanko.sign.timestamps.common_utils import TimestampRequestError

    class NoRedirectTimeStamper(HTTPTimeStamper):
        """pyHanko's HTTPTimeStamper, but a redirect is an error (it is never followed) and the answer is size-capped.

        Mirrors HTTPTimeStamper.async_request_tsa_response in pyHanko 0.38.1.
        """

        async def async_request_tsa_response(self, req):
            timeout = aiohttp.ClientTimeout(total=self.timeout)
            headers = await self.async_request_headers()
            try:
                async with aiohttp.ClientSession() as session, session.post(
                    url=self.url,
                    headers=headers,
                    data=req.dump(),
                    auth=self.auth,
                    allow_redirects=False,
                    raise_for_status=True,
                    timeout=timeout,
                ) as response:
                    if response.status != 200:
                        raise TimestampRequestError(f'Timestamp server answered HTTP {response.status}')
                    if response.headers.get('Content-Type') != 'application/timestamp-reply':
                        raise TimestampRequestError('Timestamp server answered with the wrong content type')
                    data = await response.content.read(MAX_TIMESTAMP_RESPONSE_BYTES + 1)
                    if len(data) > MAX_TIMESTAMP_RESPONSE_BYTES:
                        raise TimestampRequestError('Timestamp server answer is too large')
            except (aiohttp.ClientError, OSError) as error:
                raise TimestampRequestError('Error while contacting timestamp service') from error
            return tsp.TimeStampResp.load(data)

    timeout = int_param(request, 'timestamp_timeout', 10, 1, 60)
    return [NoRedirectTimeStamper(url, timeout=timeout) for url in timestamp_urls(request)]


def signer_from(request):
    from pyhanko.sign import signers

    pkcs12 = secret_bytes(request, 'pkcs12')
    password = secret_bytes(request, 'password', allow_empty=True)
    try:
        return signers.SimpleSigner.load_pkcs12_data(pkcs12, other_certs=[], passphrase=password or None)
    except ValueError:
        raise EngineError('bad_credentials', 'the seal certificate or its password could not be read')


def certificate_fingerprint(signer):
    return hashlib.sha256(signer.signing_cert.dump()).hexdigest()


def op_seal(request):
    base = work_dir(request)
    caps = limits(request)
    source = inside(base, request.get('input'), True)
    target = inside(base, request.get('output'), False)
    data = read_pdf_bytes(source, caps['max_bytes'] + 10 * 1024 * 1024)
    level = request.get('level')
    if level not in SEAL_LEVELS:
        raise EngineError('unsupported_level', 'this engine seals at B_B or B_T only')

    from pyhanko.sign import fields, signers

    pages = page_count_of(writer_for(data))
    if pages > caps['max_pages'] + APPENDED_PAGES_ALLOWANCE:
        raise EngineError('too_many_pages', 'the PDF has more pages than the limit')

    signer = signer_from(request)
    meta = signers.PdfSignatureMetadata(
        field_name=string_param(request, 'field_name', 60),
        md_algorithm='sha256',
        subfilter=fields.SigSeedSubFilter.PADES,
        reason=string_param(request, 'reason', 200, required=False),
        location=string_param(request, 'location', 200, required=False),
    )

    candidates = [None] if level == 'B_B' else timestampers(request)
    if level == 'B_T' and not candidates:
        raise EngineError('invalid_request', 'B_T needs at least one timestamp URL')

    last_error = None
    for timestamper in candidates:
        writer = writer_for(data)
        try:
            with open(target, 'wb') as handle:
                signers.sign_pdf(writer, meta, signer=signer, timestamper=timestamper, output=handle)
        except EngineError:
            raise
        except Exception as error:  # a timestamp server that does not answer, or answers badly
            last_error = error
            if os.path.exists(target):
                os.remove(target)
            if timestamper is None:
                raise EngineError('failed', 'the PDF could not be sealed')
            continue

        written = ensure_incremental(data, target)
        return {
            'output': target,
            'bytes': len(written),
            'sha256': sha256(written),
            'level': level,
            'certificate_sha256': certificate_fingerprint(signer),
            'timestamp_url': getattr(timestamper, 'url', None),
        }

    raise EngineError('timestamp_unavailable', f'no timestamp server answered ({type(last_error).__name__})')


def op_timestamp(request):
    base = work_dir(request)
    caps = limits(request)
    source = inside(base, request.get('input'), True)
    target = inside(base, request.get('output'), False)
    data = read_pdf_bytes(source, caps['max_bytes'] + 10 * 1024 * 1024)

    if page_count_of(writer_for(data)) > caps['max_pages'] + APPENDED_PAGES_ALLOWANCE:
        raise EngineError('too_many_pages', 'the PDF has more pages than the limit')

    from pyhanko.sign import signers

    candidates = timestampers(request)
    if not candidates:
        raise EngineError('invalid_request', 'a document timestamp needs at least one timestamp URL')

    last_error = None
    for timestamper in candidates:
        writer = writer_for(data)
        try:
            with open(target, 'wb') as handle:
                signers.PdfTimeStamper(timestamper).timestamp_pdf(writer, 'sha256', output=handle)
        except Exception as error:
            last_error = error
            if os.path.exists(target):
                os.remove(target)
            continue

        written = ensure_incremental(data, target)
        return {'output': target, 'bytes': len(written), 'sha256': sha256(written), 'timestamp_url': timestamper.url}

    raise EngineError('timestamp_unavailable', f'no timestamp server answered ({type(last_error).__name__})')


# --- Long-term validation (ADR-046 S11) ---------------------------------------
#
# B-LT adds a DSS (document security store) with VRI entries: the certificates,
# OCSP answers and CRLs needed to validate every signature and timestamp in the
# file later, when the certificates may have expired or been revoked. B-LTA adds
# a document timestamp over the file with that DSS; before each new one, the
# validation data of everything in the file (the previous document timestamp
# included) is added first, the order pyHanko's update_archival_timestamp_chain
# uses.
#
# Validation data is COLLECTED, not judged: the self-issued certificates found
# in the file's own CMS structures are accepted as path ends besides the
# operating system's trust list, so a self-signed seal gets its certificate in
# the DSS. Revocation checking is hard-fail: when an OCSP or CRL address that a
# certificate names does not answer, nothing is written and the answer is
# validation_data_unavailable, so NONOX never claims B-LT for incomplete data.
#
# Revocation addresses come from certificates, and a company may upload its own
# certificate: so only http(s) to public addresses is fetched (the resolver
# drops loopback, private, link-local and other non-global addresses, and an
# address written as a number is checked before connecting), redirects are
# never followed, an answer larger than 20 MB is refused (when it announces it,
# and in any case while it is read), and pyhanko-certvalidator's FetchLimits
# bound the number of requests.

MAX_FETCH_RESPONSE_BYTES = 20 * 1024 * 1024
MD_ALGORITHMS = ('sha256', 'sha384', 'sha512')


def public_fetch_backend(timeout):
    import ipaddress
    import socket
    import ssl

    import aiohttp
    import certifi
    from aiohttp.resolver import ThreadedResolver
    from pyhanko_certvalidator.fetchers.aiohttp_fetchers import AIOHttpFetcherBackend
    from pyhanko_certvalidator.fetchers.aiohttp_fetchers.util import LazySession
    from pyhanko_certvalidator.fetchers.api import FetchLimits

    def is_public(host):
        """True or False for an address, None for a name (the resolver checks what it resolves to)."""
        try:
            return ipaddress.ip_address(host.strip('[]')).is_global
        except ValueError:
            return None

    class PublicResolver(ThreadedResolver):
        async def resolve(self, host, port=0, family=socket.AF_INET):
            results = await super().resolve(host, port, family)
            public = [result for result in results if is_public(result['host']) is True]
            if not public:
                raise OSError('a revocation address is not public')
            return public

    async def on_request_start(session, context, params):
        url = params.url
        if url.scheme not in ('http', 'https') or not url.host or is_public(url.host) is False:
            raise aiohttp.ClientError('a revocation address is not public')

    async def on_request_redirect(session, context, params):
        raise aiohttp.ClientError('redirects are not followed')

    async def on_request_end(session, context, params):
        length = params.response.content_length
        if length is not None and length > MAX_FETCH_RESPONSE_BYTES:
            raise aiohttp.ClientError('a revocation answer is too large')

    class CappedResponse(aiohttp.ClientResponse):
        """aiohttp's ClientResponse.read() (3.14.5) reads the whole body before any trace fires, so the body is read
        here in chunks and the read stops as soon as it passes the cap, whatever the headers say (chunked, no length).
        The bytes are then handed to the parent's read(), which finds _body set and only releases the connection."""

        async def read(self):
            if self._body is None:
                received = bytearray()
                async for chunk in self.content.iter_chunked(64 * 1024):
                    received.extend(chunk)
                    if len(received) > MAX_FETCH_RESPONSE_BYTES:
                        self.close()
                        raise aiohttp.ClientError('a revocation answer is too large')
                self._body = bytes(received)
            return await super().read()

    class PublicSession(LazySession):
        def get_session(self):
            if self._session is None:
                trace = aiohttp.TraceConfig()
                trace.on_request_start.append(on_request_start)
                trace.on_request_redirect.append(on_request_redirect)
                trace.on_request_end.append(on_request_end)
                self._session = aiohttp.ClientSession(
                    response_class=CappedResponse,
                    connector=aiohttp.TCPConnector(ssl=ssl.create_default_context(cafile=certifi.where()), resolver=PublicResolver()),
                    trace_configs=[trace],
                )
            return self._session

    return AIOHttpFetcherBackend(
        session=PublicSession(),
        per_request_timeout=timeout,
        limits=FetchLimits(max_requests=40, max_cert_requests=10, max_aia_urls_per_object=2),
    )


def cms_tokens(reader):
    """Every CMS structure in the file: each signature and its timestamp tokens, and each document timestamp."""
    from pyhanko.sign.validation.generic_cms import extract_tst_data_iter

    tokens = []
    for embedded in reader.embedded_signatures:
        if embedded.sig_object_type == '/DocTimeStamp':
            tokens.append(('document', embedded.signed_data))
            continue
        tokens.append(('signature_cms', embedded.signed_data))
        for signed in (False, True):
            for token in extract_tst_data_iter(embedded.signer_info, signed=signed):
                tokens.append(('content' if signed else 'signature', token))
    return tokens


def timestamp_facts(reader):
    """When each timestamp in the file was made, until when its TSA certificate is valid, and its hash algorithm; oldest first."""
    from pyhanko.sign.general import extract_certificate_info

    facts = []
    for kind, token in cms_tokens(reader):
        if kind not in ('signature', 'document'):
            continue
        tst_info = token['encap_content_info']['content'].parsed
        certificate = extract_certificate_info(token).signer_cert
        facts.append({
            'kind': kind,
            'at': tst_info['gen_time'].native.isoformat(),
            'certificate_not_after': certificate['tbs_certificate']['validity']['not_after'].native.isoformat(),
            'md_algorithm': tst_info['message_imprint']['hash_algorithm']['algorithm'].native,
        })
    facts.sort(key=lambda fact: fact['at'])
    return facts


def collection_context(reader, timeout):
    from pyhanko.sign.general import extract_certificate_info
    from pyhanko_certvalidator import ValidationContext

    certificates = []
    for _kind, token in cms_tokens(reader):
        info = extract_certificate_info(token)
        certificates.append(info.signer_cert)
        certificates.extend(info.other_certs)
    self_issued = [certificate for certificate in certificates if certificate.self_signed != 'no']

    return ValidationContext(
        extra_trust_roots=self_issued or None,
        other_certs=certificates,
        allow_fetching=True,
        revocation_mode='hard-fail',
        fetcher_backend=public_fetch_backend(timeout),
    )


def with_validation_data(data, timeout):
    """The file with a DSS (and a VRI entry per signature) for everything in it, as one incremental update."""
    import asyncio

    from pyhanko.pdf_utils.reader import PdfFileReader
    from pyhanko.sign._session_scope import run_and_release
    from pyhanko.sign.validation.dss import DocumentSecurityStore, collect_validation_info

    try:
        reader = PdfFileReader(io.BytesIO(data), strict=False)
        embedded = list(reader.embedded_signatures)
    except Exception:
        raise EngineError('unreadable', 'the PDF structure could not be read')
    if not embedded:
        raise EngineError('invalid_request', 'the PDF has no signature or timestamp to add validation data for')

    context = collection_context(reader, timeout)

    async def collect():
        return [(signature, await collect_validation_info(signature, context)) for signature in embedded]

    try:
        collected = asyncio.run(run_and_release(collect(), context))
    except MemoryError:
        raise
    except Exception as error:  # a revocation address that does not answer, or a path that cannot be built
        from pyhanko_certvalidator.errors import RevokedError

        if isinstance(error, RevokedError):
            # Hard-fail collection meets a certificate its issuer has revoked: never written as validation data.
            raise EngineError('certificate_revoked', 'a certificate in the file is revoked')
        raise EngineError('validation_data_unavailable', f'validation data could not be collected ({type(error).__name__})')

    writer = writer_for(data)
    for signature, paths in collected:
        DocumentSecurityStore.supply_dss_in_writer(
            writer,
            signature.pkcs7_content.hex().encode('ascii'),
            paths=paths,
            validation_context=context,
        )
    output = io.BytesIO()
    writer.write(output)
    return output.getvalue()


def facts_of(written):
    from pyhanko.pdf_utils.reader import PdfFileReader

    return timestamp_facts(PdfFileReader(io.BytesIO(written), strict=False))


def op_add_validation_data(request):
    base = work_dir(request)
    caps = limits(request)
    source = inside(base, request.get('input'), True)
    target = inside(base, request.get('output'), False)
    data = read_pdf_bytes(source, caps['max_bytes'] + 10 * 1024 * 1024)

    if page_count_of(writer_for(data)) > caps['max_pages'] + APPENDED_PAGES_ALLOWANCE:
        raise EngineError('too_many_pages', 'the PDF has more pages than the limit')

    updated = with_validation_data(data, int_param(request, 'fetch_timeout', 10, 1, 60))
    with open(target, 'wb') as handle:
        handle.write(updated)

    written = ensure_incremental(data, target)
    return {'output': target, 'bytes': len(written), 'sha256': sha256(written), 'timestamps': facts_of(written)}


def op_archive_timestamp(request):
    base = work_dir(request)
    caps = limits(request)
    source = inside(base, request.get('input'), True)
    target = inside(base, request.get('output'), False)
    data = read_pdf_bytes(source, caps['max_bytes'] + 10 * 1024 * 1024)

    if page_count_of(writer_for(data)) > caps['max_pages'] + APPENDED_PAGES_ALLOWANCE:
        raise EngineError('too_many_pages', 'the PDF has more pages than the limit')

    md_algorithm = request.get('md_algorithm', 'sha256')
    if md_algorithm not in MD_ALGORITHMS:
        raise EngineError('invalid_request', 'md_algorithm must be sha256, sha384 or sha512')

    candidates = timestampers(request)
    if not candidates:
        raise EngineError('invalid_request', 'a document timestamp needs at least one timestamp URL')

    # First the validation data of everything already in the file, the last timestamp included.
    with_dss = with_validation_data(data, int_param(request, 'fetch_timeout', 10, 1, 60))

    from pyhanko.sign import signers

    last_error = None
    for timestamper in candidates:
        writer = writer_for(with_dss)
        try:
            with open(target, 'wb') as handle:
                signers.PdfTimeStamper(timestamper).timestamp_pdf(writer, md_algorithm, output=handle)
        except Exception as error:
            last_error = error
            if os.path.exists(target):
                os.remove(target)
            continue

        written = ensure_incremental(data, target)
        return {
            'output': target,
            'bytes': len(written),
            'sha256': sha256(written),
            'timestamp_url': timestamper.url,
            'timestamps': facts_of(written),
        }

    raise EngineError('timestamp_unavailable', f'no timestamp server answered ({type(last_error).__name__})')


def external_certificates(request):
    from asn1crypto import x509

    values = request.get('certificates')
    if not isinstance(values, list) or not 1 <= len(values) <= MAX_EXTERNAL_CERTIFICATES:
        raise EngineError('invalid_request', 'certificates must list one to ten certificates')
    certificates = []
    for value in values:
        if not isinstance(value, str):
            raise EngineError('invalid_request', 'a certificate must be base64')
        try:
            der = base64.b64decode(value, validate=True)
        except ValueError:
            raise EngineError('invalid_request', 'a certificate is not base64')
        if not der or len(der) > MAX_CERTIFICATE_BYTES:
            raise EngineError('invalid_request', 'a certificate is too large')
        try:
            certificate = x509.Certificate.load(der)
            certificate.native  # parses the whole structure now, not later
        except Exception:
            raise EngineError('bad_credentials', 'a certificate could not be read')
        certificates.append(certificate)
    return certificates


def external_signer(request, signature_value):
    from asn1crypto import algos
    from pyhanko.sign import signers
    from pyhanko_certvalidator.registry import SimpleCertificateStore

    mechanism = request.get('signature_mechanism')
    if mechanism not in EXTERNAL_MECHANISMS:
        raise EngineError('invalid_request', 'unsupported signature mechanism')
    certificates = external_certificates(request)
    signer = signers.ExternalSigner(
        certificates[0],
        SimpleCertificateStore.from_certs(certificates),
        signature_value=signature_value,
        signature_mechanism=algos.SignedDigestAlgorithm({'algorithm': mechanism}),
    )
    return signer, certificates


def op_prepare_external_signature(request):
    """ADR-046 S17, step one: an empty PAdES signature for the signer's certificate, and the digest to be signed."""
    import asyncio

    from pyhanko.sign import fields, signers

    base = work_dir(request)
    caps = limits(request)
    source = inside(base, request.get('input'), True)
    target = inside(base, request.get('output'), False)
    data = read_pdf_bytes(source, caps['max_bytes'] + 10 * 1024 * 1024)

    if page_count_of(writer_for(data)) > caps['max_pages'] + APPENDED_PAGES_ALLOWANCE:
        raise EngineError('too_many_pages', 'the PDF has more pages than the limit')

    signer, certificates = external_signer(request, EXTERNAL_PLACEHOLDER_BYTES)
    meta = signers.PdfSignatureMetadata(
        field_name=string_param(request, 'field_name', 60),
        md_algorithm='sha256',
        subfilter=fields.SigSeedSubFilter.PADES,
        reason=string_param(request, 'reason', 200, required=False),
    )

    async def prepare():
        output = io.BytesIO()
        prepared, _, output = await signers.PdfSigner(meta, signer=signer).async_digest_doc_for_signing(writer_for(data), output=output)
        attributes = await signer.signed_attrs(prepared.document_digest, 'sha256', use_pades=True)
        return prepared, output.getvalue(), attributes.dump()

    try:
        prepared, written, attributes = asyncio.run(prepare())
    except EngineError:
        raise
    except Exception:
        raise EngineError('failed', 'the PDF could not be prepared for signing')

    with open(target, 'wb') as handle:
        handle.write(written)
    written = ensure_incremental(data, target)

    return {
        'output': target,
        'bytes': len(written),
        'sha256': sha256(written),
        'certificate_sha256': hashlib.sha256(certificates[0].dump()).hexdigest(),
        'prepared': {
            'digest': base64.b64encode(hashlib.sha256(attributes).digest()).decode(),
            'document_digest': prepared.document_digest.hex(),
            'reserved_start': prepared.reserved_region_start,
            'reserved_end': prepared.reserved_region_end,
            'signed_attributes': base64.b64encode(attributes).decode(),
        },
    }


def verify_external_value(certificate, mechanism, digest, signature):
    """The provider's value must verify against the certificate's key over exactly the prepared digest."""
    from cryptography import x509 as cx509
    from cryptography.exceptions import InvalidSignature
    from cryptography.hazmat.primitives import hashes
    from cryptography.hazmat.primitives.asymmetric import ec, padding, rsa
    from cryptography.hazmat.primitives.asymmetric.utils import Prehashed

    key = cx509.load_der_x509_certificate(certificate.dump()).public_key()
    try:
        if mechanism == 'sha256_rsa' and isinstance(key, rsa.RSAPublicKey):
            key.verify(signature, digest, padding.PKCS1v15(), Prehashed(hashes.SHA256()))
        elif mechanism == 'sha256_ecdsa' and isinstance(key, ec.EllipticCurvePublicKey):
            key.verify(signature, digest, ec.ECDSA(Prehashed(hashes.SHA256())))
        else:
            raise EngineError('signature_invalid', 'the key does not match the signature mechanism')
    except InvalidSignature:
        raise EngineError('signature_invalid', 'the signature does not verify')


def op_finish_external_signature(request):
    """ADR-046 S17, step two: the provider's value, checked, put into the placeholder of the prepared file."""
    import asyncio

    from asn1crypto import cms
    from pyhanko.sign.signers.pdf_byterange import PreparedByteRangeDigest
    from pyhanko.sign.signers.pdf_signer import PdfTBSDocument

    base = work_dir(request)
    caps = limits(request)
    source = inside(base, request.get('input'), True)
    prepared_path = inside(base, request.get('prepared'), True)
    target = inside(base, request.get('output'), False)
    original = read_pdf_bytes(source, caps['max_bytes'] + 10 * 1024 * 1024)
    prepared = read_pdf_bytes(prepared_path, caps['max_bytes'] + 10 * 1024 * 1024)

    if request.get('prepared_sha256') != sha256(prepared) or not prepared.startswith(original):
        raise EngineError('not_incremental', 'the prepared file is not the one that was prepared')

    try:
        signature = base64.b64decode(str(request.get('signature', '')), validate=True)
        attributes_der = base64.b64decode(str(request.get('signed_attributes', '')), validate=True)
        document_digest = bytes.fromhex(str(request.get('document_digest', '')))
    except ValueError:
        raise EngineError('invalid_request', 'the prepared state is not valid')
    if not signature or len(signature) > MAX_SIGNATURE_BYTES or not attributes_der or len(document_digest) != 32:
        raise EngineError('invalid_request', 'the prepared state is not valid')

    start = request.get('reserved_start')
    end = request.get('reserved_end')
    if not isinstance(start, int) or not isinstance(end, int) or not len(original) <= start < end <= len(prepared):
        raise EngineError('invalid_request', 'the placeholder is outside the prepared revision')
    if prepared[start:start + 1] != b'<' or prepared[end - 1:end] != b'>':
        raise EngineError('invalid_request', 'the placeholder is not where the state says')
    if hashlib.sha256(prepared[:start] + prepared[end:]).digest() != document_digest:
        raise EngineError('not_incremental', 'the prepared file does not match its digest')

    signer, certificates = external_signer(request, signature)
    attributes = cms.CMSAttributes.load(attributes_der)
    message_digest = [attribute['values'][0].native for attribute in attributes if attribute['type'].native == 'message_digest']
    if message_digest != [document_digest]:
        raise EngineError('invalid_request', 'the signed attributes do not name the prepared digest')

    verify_external_value(certificates[0], request.get('signature_mechanism'), hashlib.sha256(attributes_der).digest(), signature)

    async def finish():
        content = await signer.async_sign_prescribed_attributes('sha256', signed_attrs=attributes)
        output = io.BytesIO(prepared)
        await PdfTBSDocument.async_finish_signing(output, PreparedByteRangeDigest(document_digest, start, end), content)
        return output.getvalue()

    try:
        written = asyncio.run(finish())
    except EngineError:
        raise
    except Exception:
        raise EngineError('failed', 'the signature could not be put into the PDF')

    with open(target, 'wb') as handle:
        handle.write(written)
    written = ensure_incremental(original, target)

    return {
        'output': target,
        'bytes': len(written),
        'sha256': sha256(written),
        'certificate_sha256': hashlib.sha256(certificates[0].dump()).hexdigest(),
    }


def op_validate(request):
    base = work_dir(request)
    caps = limits(request)
    source = inside(base, request.get('input'), True)
    data = read_pdf_bytes(source, caps['max_bytes'] + 10 * 1024 * 1024)

    from pyhanko.pdf_utils.reader import PdfFileReader
    from pyhanko.sign.validation import validate_pdf_signature, validate_pdf_timestamp
    from pyhanko_certvalidator import ValidationContext

    try:
        reader = PdfFileReader(io.BytesIO(data), strict=False)
    except Exception:
        raise EngineError('unreadable', 'the PDF structure could not be read')

    checks = []
    for embedded in reader.embedded_signatures:
        # No trust roots and no fetching: this checks integrity and coverage,
        # never whether a reader would trust the certificate, and makes no
        # network call.
        context = ValidationContext(trust_roots=[], allow_fetching=False)
        is_timestamp = embedded.sig_object_type == '/DocTimeStamp'
        try:
            status = validate_pdf_timestamp(embedded, validation_context=context) if is_timestamp else validate_pdf_signature(embedded, context)
        except Exception as error:
            checks.append({'field': embedded.field_name, 'type': 'timestamp' if is_timestamp else 'signature', 'intact': False, 'valid': False, 'coverage': None, 'error': type(error).__name__})
            continue

        coverage = getattr(status, 'coverage', None)
        checks.append({
            'field': embedded.field_name,
            'type': 'timestamp' if is_timestamp else 'signature',
            'intact': bool(status.intact),
            'valid': bool(status.valid),
            'coverage': coverage.name if coverage is not None else None,
            'has_signature_timestamp': getattr(status, 'timestamp_validity', None) is not None,
            'certificate_sha256': hashlib.sha256(status.signing_cert.dump()).hexdigest(),
        })

    return {'sha256': sha256(data), 'revisions': reader.total_revisions, 'signatures': checks}


def op_selftest(request):
    """Builds a one-page PDF and runs inspect, render, stamp, append, seal, validate and add_validation_data on it."""
    base = work_dir(request)

    from datetime import datetime, timedelta, timezone

    from cryptography import x509 as cx509
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import ec
    from cryptography.hazmat.primitives.serialization import pkcs12
    from cryptography.x509.oid import NameOID
    from pyhanko.pdf_utils import generic
    from pyhanko.pdf_utils.writer import PageObject, PdfFileWriter

    def blank(path):
        writer = PdfFileWriter()
        stream = writer.add_object(generic.StreamObject(stream_data=b'0.9 g 50 50 100 100 re f'))
        writer.insert_page(PageObject(contents=stream, media_box=[0, 0, 595.28, 841.89]))
        with open(path, 'wb') as handle:
            writer.write(handle)

    def cropped(path):
        writer = PdfFileWriter()
        stream = writer.add_object(generic.StreamObject(stream_data=b'0.9 g 100 100 100 100 re f'))
        writer.insert_page(PageObject(contents=stream, media_box=[0, 0, 595.28, 841.89]))
        page = writer.root['/Pages']['/Kids'][0].get_object()
        page['/CropBox'] = generic.ArrayObject([generic.NumberObject(value) for value in (50, 50, 400, 600)])
        with open(path, 'wb') as handle:
            writer.write(handle)

    original = inside(base, 'selftest-original.pdf', False)
    appendix = inside(base, 'selftest-appendix.pdf', False)
    blank(original)
    blank(appendix)
    cropped_path = inside(base, 'selftest-cropped.pdf', False)
    cropped(cropped_path)

    key = ec.generate_private_key(ec.SECP256R1())
    name = cx509.Name([cx509.NameAttribute(NameOID.COMMON_NAME, 'NONOX engine self-test')])
    now = datetime.now(timezone.utc)
    certificate = (
        cx509.CertificateBuilder()
        .subject_name(name)
        .issuer_name(name)
        .public_key(key.public_key())
        .serial_number(cx509.random_serial_number())
        .not_valid_before(now - timedelta(minutes=5))
        .not_valid_after(now + timedelta(days=1))
        .add_extension(cx509.KeyUsage(True, True, False, False, False, False, False, False, False), critical=True)
        .sign(key, hashes.SHA256())
    )
    password = os.urandom(16).hex().encode()
    bundle = pkcs12.serialize_key_and_certificates(b'selftest', key, certificate, None, serialization.BestAvailableEncryption(password))

    common = {key_: request[key_] for key_ in ('work_dir', 'fonts_dir') if key_ in request}
    checks = {}

    checks['inspect'] = op_inspect({**common, 'input': original})['pages'] == 1
    checks['render'] = len(op_render_pages({**common, 'input': original, 'output_prefix': 'selftest', 'scale': 0.5})['pages']) == 1

    # ADR-046 S8: fields are measured from the crop box, so a stamp at (0, 0) on a page cropped to
    # [50 50 400 600] is drawn at (50, 50) in user space, inside the visible area.
    with open(cropped_path, 'rb') as handle:
        origin = page_origin(writer_for(handle.read()), 0)
    checks['crop_origin'] = origin == (50.0, 50.0)
    if fonts(request).get('sans'):
        cropped_stamped = inside(base, 'selftest-cropped-stamped.pdf', False)
        op_stamp({**common, 'input': cropped_path, 'output': cropped_stamped, 'items': [
            {'type': 'text', 'page': 1, 'x': 0, 'y': 0, 'text': 'X', 'font_size': 10, 'box': {'width': 20, 'height': 14}},
        ]})

    stamped = inside(base, 'selftest-stamped.pdf', False)
    if fonts(request).get('sans'):
        items = [
            {'type': 'text', 'page': 1, 'x': 60, 'y': 700, 'text': 'Åsa Ελένη Дарья', 'font_size': 10},
            # A field's box (ADR-046 S6): far too wide text, shrunk to fit and clipped to the box.
            {'type': 'text', 'page': 1, 'x': 60, 'y': 660, 'text': 'Västerhaninge sjöstad och omnejd', 'font_size': 11, 'box': {'width': 40, 'height': 14}},
        ]
        if fonts(request).get('cjk'):
            items.append({'type': 'text', 'page': 1, 'x': 60, 'y': 680, 'text': '李明 さくら 김민준', 'font_size': 10})
        op_stamp({**common, 'input': original, 'output': stamped, 'items': items})
        checks['stamp'] = True
    else:
        with open(original, 'rb') as source, open(stamped, 'wb') as copy:
            copy.write(source.read())
        checks['stamp'] = None

    appended = inside(base, 'selftest-appended.pdf', False)
    checks['append'] = op_append_pages({**common, 'input': stamped, 'appendix': appendix, 'output': appended})['pages'] == 2

    sealed = inside(base, 'selftest-sealed.pdf', False)
    op_seal({
        **common,
        'input': appended,
        'output': sealed,
        'level': 'B_B',
        'field_name': 'NonoxSelfTest',
        'secrets': {'pkcs12': base64.b64encode(bundle).decode(), 'password': base64.b64encode(password).decode()},
    })
    report = op_validate({**common, 'input': sealed})['signatures']
    checks['seal'] = len(report) == 1 and report[0]['intact'] and report[0]['valid']

    # ADR-046 S11: validation data for the throwaway self-signed seal (its own root: nothing is fetched).
    with_dss = inside(base, 'selftest-validation-data.pdf', False)
    added = op_add_validation_data({**common, 'input': sealed, 'output': with_dss})
    with open(with_dss, 'rb') as handle:
        checks['validation_data'] = added['bytes'] > 0 and b'/DSS' in handle.read()

    return {'checks': checks, 'passed': all(value is not False for value in checks.values())}


def main():
    if os.environ.get('NONOX_PDF_ENGINE_DEBUG') != '1':
        import logging

        # pyHanko logs expected outcomes (an untrusted certificate when no trust
        # roots are given) at warning level; stderr is not part of the protocol.
        logging.disable(logging.CRITICAL)

    raw =sys.stdin.buffer.read(MAX_REQUEST_BYTES + 1)
    if len(raw) > MAX_REQUEST_BYTES:
        answer({'ok': False, 'error': {'code': 'invalid_request', 'message': 'the request is too large'}}, 2)
    try:
        request = json.loads(raw.decode('utf-8'))
    except (UnicodeDecodeError, ValueError):
        answer({'ok': False, 'error': {'code': 'invalid_request', 'message': 'the request is not JSON'}}, 2)
    if not isinstance(request, dict):
        answer({'ok': False, 'error': {'code': 'invalid_request', 'message': 'the request must be an object'}}, 2)

    operation = request.get('op')
    if operation not in OPERATIONS:
        answer({'ok': False, 'error': {'code': 'invalid_request', 'message': 'unknown operation'}}, 2)
    if request.get('protocol') != PROTOCOL_VERSION:
        answer({'ok': False, 'error': {'code': 'protocol_mismatch', 'message': f'this engine speaks protocol {PROTOCOL_VERSION}'}}, 2)

    try:
        apply_resource_limits(request)
        result = globals()[f'op_{operation}'](request)
    except EngineError as error:
        answer({'ok': False, 'error': {'code': error.code, 'message': error.message}}, 2)
    except MemoryError:
        answer({'ok': False, 'error': {'code': 'memory', 'message': 'the engine ran out of memory'}}, 2)
    except Exception as error:  # never echo the file's content or a secret
        if os.environ.get('NONOX_PDF_ENGINE_DEBUG') == '1':
            import traceback

            traceback.print_exc(file=sys.stderr)
        answer({'ok': False, 'error': {'code': 'failed', 'message': type(error).__name__}}, 2)

    answer({'ok': True, 'result': result}, 0)


if __name__ == '__main__':
    main()
