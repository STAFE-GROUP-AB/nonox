# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Checks the size cap of the PDF engine's revocation fetcher (ADR-046 S11) for
# tests/Feature/Signing/PdfEngineRealTest.php: a chunked answer without a
# Content-Length from a throwaway server on 127.0.0.1 must stop being read at
# 20 MB. The helper refuses loopback addresses, so this loads a copy of it with
# only that address check removed; the size cap is the helper's own code.
#
#   <python> -I fetch_cap_check.py <path to nonox_pdf_engine.py>
import asyncio, json, sys, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

src = open(sys.argv[1]).read()
# Strip only the address guard so the loopback test server can be reached; the size cap is untouched.
src = src.replace("or is_public(url.host) is False:", "or False:").replace("is_public(result['host']) is True]", "True]")
assert 'or False:' in src
mod = type(sys)('engine')
exec(compile(src, 'engine', 'exec'), mod.__dict__)


class H(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def do_GET(self):
        size = int(self.path.strip('/'))
        self.send_response(200)
        self.send_header('Transfer-Encoding', 'chunked')
        self.end_headers()
        block = b'x' * 65536
        total = 0
        try:
            while total < size:
                n = min(len(block), size - total)
                self.wfile.write(b'%x\r\n' % n + block[:n] + b'\r\n')
                total += n
            self.wfile.write(b'0\r\n\r\n')
        except (BrokenPipeError, ConnectionResetError):
            pass

    def log_message(self, *a):
        pass


srv = ThreadingHTTPServer(('127.0.0.1', 0), H)
threading.Thread(target=srv.serve_forever, daemon=True).start()
port = srv.server_address[1]


async def fetch(size):
    backend = mod.public_fetch_backend(10)
    session = backend.session.get_session()
    try:
        async with session.get(f'http://127.0.0.1:{port}/{size}') as r:
            assert r.headers.get('Content-Length') is None
            return len(await r.read())
    except Exception as e:
        return type(e).__name__ + ': ' + str(e)
    finally:
        await backend.close()

print(json.dumps({'small': asyncio.run(fetch(1024 * 1024)), 'large': asyncio.run(fetch(200 * 1024 * 1024))}))
