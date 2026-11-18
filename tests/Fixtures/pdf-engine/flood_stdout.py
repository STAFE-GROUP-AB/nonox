# Stands in for the PDF engine: writes far more to stdout than NONOX accepts, and keeps going.
import sys
import time

sys.stdin.read()
for _ in range(100000):
    sys.stdout.write('x' * 4096)
    sys.stdout.flush()
time.sleep(30)
