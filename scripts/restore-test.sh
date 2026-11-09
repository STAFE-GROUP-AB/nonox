#!/usr/bin/env bash
# Restore test (docs/gdpr/sakerhetskopiering.md).
#
# Restores a database backup into a throwaway PostgreSQL cluster and a files
# backup into a temporary directory, runs `artisan backup:verify-restore`
# against them and removes everything afterwards. The live database and the
# live files are never touched.
#
#   scripts/restore-test.sh <db-backup> <files-backup>
#   scripts/restore-test.sh <backup-directory>      newest pair in the directory
#
# db-backup     pg_dump in custom format, optionally gpg-encrypted (*.gpg)
# files-backup  tar.gz of storage/app/private, optionally gpg-encrypted (*.gpg)
#
# NONOX_BACKUP_PASSPHRASE_FILE  passphrase for *.gpg backups
# NONOX_APP                     installation to run artisan in (default: this checkout)
# NONOX_RESTORE_LOG             file to append the one-line result to (optional)
# PG_BIN                        directory with initdb, pg_ctl and pg_restore (default: found)
set -euo pipefail
umask 077

fail() { echo "restore-test: $*" >&2; exit 2; }

if [[ $# -eq 1 && -d "$1" ]]; then
    DB_BACKUP=$(ls -t "$1"/nonox-db-*.dump* 2>/dev/null | head -1 || true)
    FILES_BACKUP=$(ls -t "$1"/nonox-files-*.tar.gz* 2>/dev/null | head -1 || true)
elif [[ $# -eq 2 ]]; then
    DB_BACKUP=$1
    FILES_BACKUP=$2
else
    fail "usage: $0 <db-backup> <files-backup> | $0 <backup-directory>"
fi

[[ -f "${DB_BACKUP:-}" ]] || fail "no database backup found"
[[ -f "${FILES_BACKUP:-}" ]] || fail "no files backup found"

APP=${NONOX_APP:-$(cd "$(dirname "$0")/.." && pwd)}
[[ -f "$APP/artisan" ]] || fail "no artisan in $APP"

if [[ -z "${PG_BIN:-}" ]]; then
    if command -v pg_config >/dev/null; then
        PG_BIN=$(pg_config --bindir)
    elif [[ -d /usr/lib/postgresql ]]; then
        PG_BIN=$(ls -d /usr/lib/postgresql/*/bin | sort -V | tail -1)
    else
        PG_BIN=$(dirname "$(command -v pg_restore || command -v psql || echo /nonexistent)")
    fi
fi
[[ -x "$PG_BIN/initdb" && -x "$PG_BIN/pg_restore" ]] || fail "initdb and pg_restore not found, set PG_BIN"

WORK=$(mktemp -d "${TMPDIR:-/tmp}/nonox-restore.XXXXXX")
# A socket path may be at most ~100 bytes, so the socket gets a short directory of its own.
SOCK=$(mktemp -d /tmp/nxr.XXXXXX)
PORT=${NONOX_RESTORE_PORT:-54329}
USER_NAME=$(id -un)

cleanup() {
    "$PG_BIN/pg_ctl" -D "$WORK/pg" -m immediate stop >/dev/null 2>&1 || true
    rm -rf "$WORK" "$SOCK"
}
trap cleanup EXIT

# Decrypted copies hold personal data: they live only in $WORK (mode 700).
unpack() {
    if [[ "$1" == *.gpg ]]; then
        [[ -r "${NONOX_BACKUP_PASSPHRASE_FILE:-}" ]] || fail "set NONOX_BACKUP_PASSPHRASE_FILE for $1"
        gpg --batch --quiet --decrypt --passphrase-file "$NONOX_BACKUP_PASSPHRASE_FILE" -o "$2" "$1"
    else
        cp "$1" "$2"
    fi
}

started=$(date +%s)
echo "Återställer $(basename "$DB_BACKUP") och $(basename "$FILES_BACKUP")"

unpack "$DB_BACKUP" "$WORK/db.dump"
unpack "$FILES_BACKUP" "$WORK/files.tar.gz"
mkdir "$WORK/files"
tar -C "$WORK/files" -xzf "$WORK/files.tar.gz"
mkdir -p "$WORK/files/private"

"$PG_BIN/initdb" -D "$WORK/pg" -U "$USER_NAME" --auth=trust -E UTF8 --locale=C >/dev/null
"$PG_BIN/pg_ctl" -D "$WORK/pg" -w -l "$WORK/pg.log" \
    -o "-k $SOCK -p $PORT -c listen_addresses=''" start >/dev/null \
    || { tail -20 "$WORK/pg.log" >&2; fail "PostgreSQL did not start"; }
"$PG_BIN/createdb" -h "$SOCK" -p "$PORT" -U "$USER_NAME" nonox_restore
"$PG_BIN/pg_restore" -h "$SOCK" -p "$PORT" -U "$USER_NAME" -d nonox_restore \
    --no-owner --no-acl --exit-on-error "$WORK/db.dump"

status=0
php "$APP/artisan" backup:verify-restore \
    --host="$SOCK" --port="$PORT" --database=nonox_restore --username="$USER_NAME" \
    --files="$WORK/files/private" || status=$?

# Tells the public status page (ADR-078) that the test ran, when this runs on the live installation.
if [[ "${NONOX_STATUS_HEARTBEAT:-}" == "1" ]]; then
    php "$APP/artisan" status:heartbeat restore-test $([[ $status -eq 0 ]] || echo --failed) || true
fi

result=$([[ $status -eq 0 ]] && echo GODKÄND || echo UNDERKÄND)
line="$(date '+%F %T') $result $(basename "$DB_BACKUP") $(basename "$FILES_BACKUP") $(($(date +%s) - started)) s"
echo "$line"

if [[ -n "${NONOX_RESTORE_LOG:-}" ]]; then
    echo "$line" >> "$NONOX_RESTORE_LOG"
fi

exit $status
