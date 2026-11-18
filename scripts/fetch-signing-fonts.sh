#!/usr/bin/env bash
# Downloads the fonts the PDF engine stamps with (ADR-046, amendment 2026-11-18, S5):
# Noto Sans (Latin, Greek, Cyrillic) and Noto Sans CJK (Chinese, Japanese, Korean, and
# Latin, Greek and Cyrillic too), with their licence texts. Both are under the SIL Open
# Font License 1.1, which allows embedding and redistribution; the fonts are fetched,
# not committed, and embedded as subsets.
#
# Every file is pinned by SHA-256. Fails closed: a mismatch aborts with a non-zero status
# and leaves nothing new in the target directory. The fonts are data, never executed.
#
# Usage: scripts/fetch-signing-fonts.sh [target-dir]
#   target-dir defaults to $NONOX_PDF_ENGINE_FONTS, then storage/app/pdf-engine-fonts
#   (the default of nonox.signing.pdf_engine.fonts_dir).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TARGET="${1:-${NONOX_PDF_ENGINE_FONTS:-${ROOT}/storage/app/pdf-engine-fonts}}"

# Noto Sans 2.015 (notofonts/latin-greek-cyrillic, built files published in the
# notofonts website repository at the commit below); Noto Sans CJK 2.004 (notofonts/noto-cjk, tag Sans2.004).
LATIN="https://raw.githubusercontent.com/notofonts/notofonts.github.io/28b15b4b43b7bed62b5cf6e6b0b5ff5846270535/fonts/NotoSans/unhinted/ttf"
CJK="https://raw.githubusercontent.com/notofonts/noto-cjk/Sans2.004/Sans/OTF/SimplifiedChinese"

FILES=(
  "NotoSans-Regular.ttf|${LATIN}/NotoSans-Regular.ttf|f3961a9cde016d41a4879aecda1474d3a36d6bf54fa0e4643de029cc2248b0e8"
  "NotoSans-Bold.ttf|${LATIN}/NotoSans-Bold.ttf|87cb2d84472a7d66da659ee47b6cdb9552326e8c128245231f191b6ac72529d9"
  "NotoSansCJKsc-Regular.otf|${CJK}/NotoSansCJKsc-Regular.otf|2c76254f6fc379fddfce0a7e84fb5385bb135d3e399294f6eeb6680d0365b74b"
  "NotoSansCJKsc-Bold.otf|${CJK}/NotoSansCJKsc-Bold.otf|b5f0d1a190a7f9b43c310a8850630af12553df32c4c050543f9059732d9b4c0a"
  "OFL-NotoSans.txt|https://raw.githubusercontent.com/notofonts/latin-greek-cyrillic/NotoSans-v2.015/OFL.txt|cee9892f9f0cc8fe882c9e9537ee6a89621d86ee7ceaf70b02e2b2b1c25c061a"
  "OFL-NotoSansCJK.txt|https://raw.githubusercontent.com/notofonts/noto-cjk/Sans2.004/LICENSE|6a73f9541c2de74158c0e7cf6b0a58ef774f5a780bf191f2d7ec9cc53efe2bf2"
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
  curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 --retry 3 --max-time 300 \
    --output "${WORK}/${name}" "$url"

  actual="$(sha256_of "${WORK}/${name}")"
  if [ "$actual" != "$expected" ]; then
    echo "SHA-256 mismatch for ${name}" >&2
    echo "  expected ${expected}" >&2
    echo "  actual   ${actual}" >&2
    exit 1
  fi
done

for entry in "${FILES[@]}"; do
  IFS='|' read -r name _url _expected <<< "$entry"
  if [ -f "${WORK}/${name}" ]; then
    mv "${WORK}/${name}" "${TARGET}/${name}"
  fi
done

echo "$TARGET"
