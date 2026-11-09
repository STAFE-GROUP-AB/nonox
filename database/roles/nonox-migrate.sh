#!/usr/bin/env bash
# Runs NONOX's migrations as the database owner (ADR-079 §6, docs/drift/databasroller.md).
#
# Install as root: /usr/local/bin/nonox-migrate, owner root, mode 0755. It is run by the
# deploy user through sudo as the Unix user nonox-migrate, which logs in to PostgreSQL as
# nonox_owner by peer authentication. No password exists anywhere:
#   sudo -u nonox-migrate /usr/local/bin/nonox-migrate "$FORGE_RELEASE_DIRECTORY"
#
# The grants are applied after the migrations whether they succeeded or not, so a migration
# that fails half-way never leaves a new table without its grants. The exit status is the
# migration's.
set -uo pipefail

release="${1:-}"
base="${NONOX_RELEASES_BASE:-/home/forge/nonox.app}"

# Only a release directory of this site, by its plain path.
if [[ ! "$release" =~ ^${base}(/releases/[0-9A-Za-z_-]+|/current)?$ ]] || [[ ! -f "$release/artisan" ]]; then
    echo "nonox-migrate: not a NONOX release directory: $release" >&2
    exit 2
fi

cd "$release" || exit 2

# Logs to stderr, so the run never creates a log file the web user cannot write.
export LOG_CHANNEL=stderr

php artisan migrate --force --database=pgsql_owner
status=$?

php artisan nonox:db-grants || echo "nonox-migrate: the grants could not be applied; run php artisan nonox:db-grants" >&2

exit "$status"
