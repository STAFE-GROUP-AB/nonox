#!/bin/sh
# Validates Peppol BIS Billing 3.0 documents with the official artefacts (ADR-050):
#   1. the OASIS UBL 2.1 XSD (Invoice or CreditNote),
#   2. the CEN EN 16931 Schematron for UBL (EUPL-1.2),
#   3. the Peppol BIS Billing 3.0 Schematron (incl. the Swedish SE-R rules).
#
# Nothing is vendored: the artefacts and the tools (Saxon-HE, MPL-2.0;
# SchXslt, MIT; xmlresolver, Apache-2.0) are downloaded into a cache
# directory on first use. Needs java, curl, unzip and xmllint.
#
#   scripts/peppol-validate.sh tests/Fixtures/peppol/*.xml
#
# Exit status 1 when any document has a failed assertion (fatal or warning)
# or does not validate against the XSD.
set -eu

CACHE="${PEPPOL_VALIDATE_CACHE:-${XDG_CACHE_HOME:-$HOME/.cache}/nonox-peppol-validate}"
SAXON_VERSION=12.5
SCHXSLT_VERSION=1.10.1
XMLRESOLVER_VERSION=5.2.2
BIS=https://docs.peppol.eu/poacc/billing/3.0/files
MAVEN=https://repo1.maven.org/maven2

mkdir -p "$CACHE"

fetch() {
    [ -s "$CACHE/$2" ] || curl -sfL "$1" -o "$CACHE/$2"
}

fetch "$MAVEN/net/sf/saxon/Saxon-HE/$SAXON_VERSION/Saxon-HE-$SAXON_VERSION.jar" saxon.jar
fetch "$MAVEN/org/xmlresolver/xmlresolver/$XMLRESOLVER_VERSION/xmlresolver-$XMLRESOLVER_VERSION.jar" xmlresolver.jar
fetch "$MAVEN/name/dmaus/schxslt/schxslt/$SCHXSLT_VERSION/schxslt-$SCHXSLT_VERSION.jar" schxslt.jar
fetch "$BIS/CEN-EN16931-UBL.sch" cen-ubl.sch
fetch "$BIS/PEPPOL-EN16931-UBL.sch" peppol-ubl.sch
fetch http://docs.oasis-open.org/ubl/os-UBL-2.1/UBL-2.1.zip ubl-2.1.zip

[ -d "$CACHE/ubl" ] || unzip -oq "$CACHE/ubl-2.1.zip" 'xsd/*' -d "$CACHE/ubl"
[ -d "$CACHE/schxslt" ] || (mkdir -p "$CACHE/schxslt" && cd "$CACHE/schxslt" && unzip -oq ../schxslt.jar)

CP="$CACHE/saxon.jar:$CACHE/xmlresolver.jar"
PIPELINE=$(find "$CACHE/schxslt" -path '*2.0/pipeline-for-svrl.xsl' | head -1)

for rules in cen-ubl peppol-ubl; do
    [ -s "$CACHE/$rules.xsl" ] || java -cp "$CP" net.sf.saxon.Transform -s:"$CACHE/$rules.sch" -xsl:"$PIPELINE" -o:"$CACHE/$rules.xsl"
done

status=0

for file in "$@"; do
    case "$(head -c 2000 "$file")" in
        *CreditNote-2*) xsd="$CACHE/ubl/xsd/maindoc/UBL-CreditNote-2.1.xsd" ;;
        *) xsd="$CACHE/ubl/xsd/maindoc/UBL-Invoice-2.1.xsd" ;;
    esac

    if ! xmllint --noout --schema "$xsd" "$file" 2>"$CACHE/xsd.log"; then
        echo "$file: XSD invalid"
        cat "$CACHE/xsd.log"
        status=1
    fi

    for rules in cen-ubl peppol-ubl; do
        java -cp "$CP" net.sf.saxon.Transform -s:"$file" -xsl:"$CACHE/$rules.xsl" -o:"$CACHE/report.svrl"
        failed=$(grep -c '<svrl:failed-assert' "$CACHE/report.svrl" || true)
        echo "$file: $rules failed assertions: $failed"

        if [ "$failed" != "0" ]; then
            grep -A3 '<svrl:failed-assert' "$CACHE/report.svrl" | grep -E 'id=|<svrl:text>' | sed 's/^ *//'
            status=1
        fi
    done
done

exit "$status"
