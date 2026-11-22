# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Checks how the PDF engine's validation-data collection (ADR-046 S11) treats a
# revoked certificate, for tests/Feature/Signing/PdfEngineRealTest.php: a seal
# certificate from a throwaway CA whose CRL is served on 127.0.0.1, revoked or
# not. The helper refuses loopback addresses, so a copy of it with only that
# address check removed is run; collection is the helper's own code.
#
#   <python> -I revocation_check.py <path to nonox_pdf_engine.py> revoked|good
import base64, json, shutil, subprocess, sys, tempfile, threading
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, HTTPServer

from cryptography import x509 as cx509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.hazmat.primitives.serialization import pkcs12
from cryptography.x509.oid import NameOID

src = open(sys.argv[1]).read()
src = src.replace("or is_public(url.host) is False:", "or False:").replace("is_public(result['host']) is True]", "True]")
work = tempfile.mkdtemp()
HELPER = work + '/engine-without-address-check.py'
open(HELPER, 'w').write(src)
now = datetime.now(timezone.utc)
cakey = ec.generate_private_key(ec.SECP256R1()); caname = cx509.Name([cx509.NameAttribute(NameOID.COMMON_NAME, 'Test CA')])
ca = cx509.CertificateBuilder().subject_name(caname).issuer_name(caname).public_key(cakey.public_key()).serial_number(1).not_valid_before(now - timedelta(days=2)).not_valid_after(now + timedelta(days=30)).add_extension(cx509.BasicConstraints(True, None), critical=True).add_extension(cx509.KeyUsage(True, False, False, False, False, True, True, False, False), critical=True).sign(cakey, hashes.SHA256())
REVOKE = sys.argv[2] == 'revoked'
state = {}


class H(BaseHTTPRequestHandler):
    def do_GET(self):
        b = cx509.CertificateRevocationListBuilder().issuer_name(caname).last_update(now - timedelta(hours=1)).next_update(now + timedelta(days=1))
        if REVOKE:
            b = b.add_revoked_certificate(cx509.RevokedCertificateBuilder().serial_number(2).revocation_date(now - timedelta(hours=1)).build())
        body = b.sign(cakey, hashes.SHA256()).public_bytes(serialization.Encoding.DER)
        self.send_response(200); self.send_header('Content-Type', 'application/pkix-crl'); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
    def log_message(self, *a): pass


srv = HTTPServer(('127.0.0.1', 0), H); threading.Thread(target=srv.serve_forever, daemon=True).start()
crl = f'http://127.0.0.1:{srv.server_address[1]}/ca.crl'
key = ec.generate_private_key(ec.SECP256R1())
leaf = cx509.CertificateBuilder().subject_name(cx509.Name([cx509.NameAttribute(NameOID.COMMON_NAME, 'leaf')])).issuer_name(caname).public_key(key.public_key()).serial_number(2).not_valid_before(now - timedelta(days=1)).not_valid_after(now + timedelta(days=10)).add_extension(cx509.CRLDistributionPoints([cx509.DistributionPoint([cx509.UniformResourceIdentifier(crl)], None, None, None)]), critical=False).sign(cakey, hashes.SHA256())
bundle = pkcs12.serialize_key_and_certificates(b's', key, leaf, [ca], serialization.BestAvailableEncryption(b'pw'))

from pyhanko.pdf_utils import generic
from pyhanko.pdf_utils.writer import PageObject, PdfFileWriter
w = PdfFileWriter(); s = w.add_object(generic.StreamObject(stream_data=b'0 g')); w.insert_page(PageObject(contents=s, media_box=[0, 0, 100, 100]))
with open(work + '/in.pdf', 'wb') as h: w.write(h)


def call(op, **params):
    req = {'protocol': 1, 'op': op, 'work_dir': work, 'fonts_dir': '/x', 'max_bytes': 20971520, 'max_pages': 100, 'memory_mb': 2048, 'cpu_seconds': 65, **params}
    p = subprocess.run([sys.executable, '-I', HELPER], input=json.dumps(req).encode(), capture_output=True)
    return json.loads(p.stdout)

try:
    call('seal', input='in.pdf', output='sealed.pdf', level='B_B', field_name='NonoxSeal', secrets={'pkcs12': base64.b64encode(bundle).decode(), 'password': base64.b64encode(b'pw').decode()})
    r = call('add_validation_data', input='sealed.pdf', output='lt.pdf')
    print(json.dumps({'ok': r['ok'], 'code': (r.get('error') or {}).get('code')}))
finally:
    shutil.rmtree(work)
