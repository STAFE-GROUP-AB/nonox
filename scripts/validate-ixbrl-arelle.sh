#!/usr/bin/env bash
# Validates generated iXBRL annual reports with Arelle (Apache-2.0) against the Bolagsverket taxonomy
# (ADR-058, stage E.3): Inline XBRL 1.1 document rules, XBRL 2.1 + dimensions validation,
# calculation relationships (deduplicated Calc 1.0) and the Unit Type Registry.
#
# Usage: scripts/validate-ixbrl-arelle.sh <taxonomy-package.zip> <report.xhtml>...
#   <taxonomy-package.zip> is "$(dirname "$(scripts/fetch-xbrl-taxonomy.sh)")/package.zip". Arelle needs the zip;
#   an unpacked folder does not resolve the http://xbrl.taxonomier.se/ URLs offline.
# Needs `arelleCmdLine` on PATH (pip install arelle-release==<pinned>). Works offline.
# Arelle exits 0 even when validation fails, so this script counts ERROR lines in its log and
# exits 1 if there are any. Log lines: "LEVEL [messageCode] message | file line".
# Logs are written to ${ARELLE_LOG_DIR:-./arelle-logs}/<name>.log.
set -uo pipefail

if [ "$#" -lt 2 ]; then
  echo "Usage: $0 <taxonomy-package.zip> <report.xhtml>..." >&2
  exit 2
fi

TAXONOMY="$1"
shift
LOGS="${ARELLE_LOG_DIR:-./arelle-logs}"
mkdir -p "$LOGS"
status=0

for report in "$@"; do
  name="$(basename "$report" .xhtml)"
  log="${LOGS}/${name}.log"

  arelleCmdLine --workOffline \
    --packages="$TAXONOMY" \
    --plugins=inlineXbrlDocumentSet \
    --validate --calc=c10d --utr \
    --file="$report" \
    --logFile="$log" --logFileMode=w \
    --logFormat="%(levelname)s [%(messageCode)s] %(message)s | %(file)s" || status=1

  errors="$(grep -c '^ERROR' "$log" || true)"
  echo "${name}: ${errors} error(s), log ${log}"
  if [ "$errors" -gt 0 ]; then
    grep '^ERROR' "$log" | sed 's/^/  /'
    status=1
  fi
done

exit "$status"
