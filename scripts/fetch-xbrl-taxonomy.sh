#!/usr/bin/env bash
# Downloads the pinned Bolagsverket/taxonomier.se XBRL taxonomy package (ADR-058, stage E.3),
# verifies its SHA-256 and unpacks it into a cache directory OUTSIDE the repository.
# Prints the directory to use as BOLAGSVERKET_TAXONOMY_DIR (the folder that holds xbrl.taxonomier.se/).
# The verified zip is kept at "$(dirname <printed dir>)/package.zip" (for Arelle).
# Fails closed: any mismatch or unsafe archive entry aborts with a non-zero status and nothing is left in the cache.
# The package is untrusted data: it is only unzipped and read, never executed.
#
# Usage: scripts/fetch-xbrl-taxonomy.sh [cache-dir]
#   cache-dir defaults to $NONOX_TAXONOMY_CACHE, then ${XDG_CACHE_HOME:-$HOME/.cache}/nonox-xbrl-taxonomy
set -euo pipefail

VERSION="2026-08-31"
URL="https://xbrl.taxonomier.se/taxonomi-paket/taxonomi-paket-${VERSION}.zip"
SHA256="47e56bca68c052b751ed8e0759fa9b04a6fa09b1d2b47ae2df4187d0ea2fe559"
TOP="taxonomi-paket-${VERSION}"

CACHE="${1:-${NONOX_TAXONOMY_CACHE:-${XDG_CACHE_HOME:-$HOME/.cache}/nonox-xbrl-taxonomy}}"
TARGET="${CACHE}/${SHA256}"
READY="${TARGET}/.verified"

if [ -f "$READY" ] && [ -f "${TARGET}/package.zip" ] && [ -d "${TARGET}/${TOP}/xbrl.taxonomier.se" ]; then
  echo "${TARGET}/${TOP}"
  exit 0
fi

sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

mkdir -p "$CACHE"
WORK="$(mktemp -d "${CACHE}/work.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

echo "Downloading ${URL}" >&2
curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 --retry 3 --max-time 300 \
  --output "${WORK}/package.zip" "$URL"

ACTUAL="$(sha256_of "${WORK}/package.zip")"
if [ "$ACTUAL" != "$SHA256" ]; then
  echo "SHA-256 mismatch for ${URL}" >&2
  echo "  expected ${SHA256}" >&2
  echo "  actual   ${ACTUAL}" >&2
  exit 1
fi

# Refuse absolute paths and parent-directory entries before extracting (zip slip).
if unzip -Z1 "${WORK}/package.zip" | grep -E '(^/|(^|/)\.\.(/|$))' >/dev/null; then
  echo "Archive contains unsafe paths; refusing to unpack." >&2
  exit 1
fi

mkdir "${WORK}/unpacked"
unzip -q "${WORK}/package.zip" -d "${WORK}/unpacked"

if [ ! -d "${WORK}/unpacked/${TOP}/xbrl.taxonomier.se" ]; then
  echo "Unexpected package layout: ${TOP}/xbrl.taxonomier.se not found." >&2
  exit 1
fi

rm -rf "$TARGET"
mv "${WORK}/unpacked" "$TARGET"
# Keep the verified zip next to the unpacked folder: Arelle loads the package from the zip.
mv "${WORK}/package.zip" "${TARGET}/package.zip"
touch "$READY"

echo "${TARGET}/${TOP}"
