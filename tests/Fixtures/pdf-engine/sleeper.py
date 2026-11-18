# Stands in for the PDF engine in the timeout test: reads the request and never answers in time.
import sys
import time

sys.stdin.read()
time.sleep(30)
