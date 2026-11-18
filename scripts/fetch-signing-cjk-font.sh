#!/usr/bin/env bash
# Downloads the one font the signing certificate needs for Chinese, Japanese and Korean
# names (ADR-046, amendment 2026-11-18, S20): Noto Sans CJK SC, the variable TrueType
# build (36 MB; dompdf cannot embed the CFF-based OTF that the PDF engine uses), with its
# licence text. SIL Open Font License 1.1, which allows embedding and redistribution.
# Fetched, not committed, and only on an installation that needs it: without the file the
# certificate shows such text as [...] with a notice (config nonox.signing.fonts).
#
# Pinned by SHA-256. Fails closed: a mismatch aborts with a non-zero status and leaves
# nothing new in the target directory. The font is data, never executed. Exits 0 without
# downloading when the file is already there and correct.
#
# Usage: scripts/fetch-signing-cjk-font.sh [target-dir]
#   target-dir defaults to $NONOX_PDF_ENGINE_FONTS, then storage/app/pdf-engine-fonts.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TARGET="${1:-${NONOX_PDF_ENGINE_FONTS:-${ROOT}/storage/app/pdf-engine-fonts}}"

BASE="https://raw.githubusercontent.com/notofonts/noto-cjk/Sans2.004"
FILES=(
  "NotoSansCJKsc-VF.ttf|${BASE}/Sans/Variable/TTF/NotoSansCJKsc-VF.ttf|990c807e79c25662a5a9ecf7f971baeb2bf2eab9a559e5ecf15cdfdb8561d21f"
  "OFL-NotoSansCJK.txt|${BASE}/LICENSE|6a73f9541c2de74158c0e7cf6b0a58ef774f5a780bf191f2d7ec9cc53efe2bf2"
)

sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

mkdir -p "$TARGET"
WORK="$(mktemp -d "${TARGET}/.work.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

for entry in "${FILES[@]}"; do
  IFS='|' read -r name url expected <<< "$entry"

  if [ -f "${TARGET}/${name}" ] && [ "$(sha256_of "${TARGET}/${name}")" = "$expected" ]; then
    continue
  fi

  echo "Downloading ${name}" >&2
  curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 --retry 3 --max-time 600 \
    --output "${WORK}/${name}" "$url"

  actual="$(sha256_of "${WORK}/${name}")"
  if [ "$actual" != "$expected" ]; then
    echo "SHA-256 mismatch for ${name}" >&2
    echo "  expected ${expected}" >&2
    echo "  actual   ${actual}" >&2
    exit 1
  fi

  mv "${WORK}/${name}" "${TARGET}/${name}"
done

echo "Fonts in ${TARGET}" >&2
