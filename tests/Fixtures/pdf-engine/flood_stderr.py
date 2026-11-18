# Stands in for the PDF engine: writes far more to stderr than NONOX accepts, and keeps going.
import sys
import time

sys.stdin.read()
for _ in range(100000):
    sys.stderr.write('x' * 4096)
    sys.stderr.flush()
time.sleep(30)
