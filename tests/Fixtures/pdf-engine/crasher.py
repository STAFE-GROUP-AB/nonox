# Stands in for the PDF engine in the crash test: dies with a traceback and no answer.
import sys

sys.stdin.read()
raise SystemExit('the engine crashed while reading a secret document')
