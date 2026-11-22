# SPDX-License-Identifier: AGPL-3.0-or-later
#
# A throwaway RFC 3161 timestamp server for the real PDF engine tests
# (ADR-046 S11, tests/Feature/Signing/PdfEngineRealTest.php). It listens on
# 127.0.0.1 only, on a port the operating system picks, prints that port on
# its first line of output, and signs every request with a self-signed TSA
# certificate (RSA: pyHanko's DummyTimeStamper signs with RSA only) made when it starts. Never used
# outside the tests.
#
#   <python> -I test_tsa.py [days the TSA certificate is valid, default 30]

import sys
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, HTTPServer

from asn1crypto import keys, tsp, x509
from cryptography import x509 as cx509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import ExtendedKeyUsageOID, NameOID
from pyhanko.sign.timestamps.dummy_client import DummyTimeStamper

days = int(sys.argv[1]) if len(sys.argv) > 1 else 30
key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
name = cx509.Name([cx509.NameAttribute(NameOID.COMMON_NAME, 'NONOX test TSA')])
now = datetime.now(timezone.utc)
certificate = (
    cx509.CertificateBuilder()
    .subject_name(name)
    .issuer_name(name)
    .public_key(key.public_key())
    .serial_number(cx509.random_serial_number())
    .not_valid_before(now - timedelta(minutes=5))
    .not_valid_after(now + timedelta(days=days))
    .add_extension(cx509.ExtendedKeyUsage([ExtendedKeyUsageOID.TIME_STAMPING]), critical=True)
    .sign(key, hashes.SHA256())
)
stamper = DummyTimeStamper(
    tsa_cert=x509.Certificate.load(certificate.public_bytes(serialization.Encoding.DER)),
    tsa_key=keys.PrivateKeyInfo.load(key.private_bytes(serialization.Encoding.DER, serialization.PrivateFormat.PKCS8, serialization.NoEncryption())),
)


class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        length = int(self.headers.get('Content-Length', '0'))
        request = tsp.TimeStampReq.load(self.rfile.read(length))
        body = stamper.request_tsa_response(request).dump()
        self.send_response(200)
        self.send_header('Content-Type', 'application/timestamp-reply')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


server = HTTPServer(('127.0.0.1', 0), Handler)
print(server.server_address[1], flush=True)
server.serve_forever()
