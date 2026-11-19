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
    'validate',
)
FONT_FILES = {
    'sans': 'NotoSans-Regular.ttf',
    'sans-bold': 'NotoSans-Bold.ttf',
    'cjk': 'NotoSansCJKsc-Regular.otf',
    'cjk-bold': 'NotoSansCJKsc-Bold.otf',
}
SEAL_LEVELS = ('B_B', 'B_T')
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
            TextStamp(writer=writer, style=style, text_params={'value': text}, box=constraints).apply(page - 1, x, y)
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
            StaticContentStamp(writer=writer, style=style, box=layout.BoxConstraints(width=width, height=height)).apply(page - 1, x, y)
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
    """Builds a one-page PDF and runs inspect, render, stamp, append, seal and validate on it."""
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

    original = inside(base, 'selftest-original.pdf', False)
    appendix = inside(base, 'selftest-appendix.pdf', False)
    blank(original)
    blank(appendix)

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
