#!/usr/bin/env bash
# Runs NONOX's migrations as the database owner (ADR-079 §6, docs/drift/databasroller.md).
#
# Install as root: /usr/local/bin/nonox-migrate, owner root, mode 0755. It is run by the
# DEPLOYING user (never the user PHP-FPM, the queue or the scheduler run as) through sudo as
# the Unix user nonox-migrate, which logs in to PostgreSQL as nonox_owner by peer
# authentication. No password exists anywhere:
#   sudo -u nonox-migrate /usr/local/bin/nonox-migrate "$FORGE_RELEASE_DIRECTORY"
#
# Nothing the web user can write is loaded: the release must not be writable by it, and
# Laravel's cached config, services, packages, events and routes are read from and written to
# a fresh directory owned by nonox-migrate instead of bootstrap/cache.
#
# The grants are applied after the migrations whether they succeeded or not, so a migration
# that fails half-way never leaves a new table without its grants. The exit status is the
# migration's.
set -uo pipefail

release="${1:-}"
base="${NONOX_RELEASES_BASE:-/home/forge/nonox\.app}"
web_user="${NONOX_WEB_USER:-nonox-web}"

# Only a release directory of this site, by its plain path.
if [[ ! "$release" =~ ^${base}(/releases/[0-9A-Za-z_-]+|/current)$ ]] || [[ ! -f "$release/artisan" ]]; then
    echo "nonox-migrate: not a NONOX release directory: $release" >&2
    exit 2
fi

# The web user must not be able to change what runs here as the owner: nothing outside
# storage/ and bootstrap/cache/ (neither is loaded here) may be owned by it, writable by one
# of its groups, or writable by everyone. .env is checked through its symlink.
if ! id "$web_user" >/dev/null 2>&1; then
    echo "nonox-migrate: the web user $web_user does not exist; set NONOX_WEB_USER" >&2
    exit 3
fi

group_tests=()
for group in $(id -Gn "$web_user"); do
    group_tests+=(-o \( -group "$group" -perm -g+w \))
done

writable=$(find -L "$release" \( -path "$release/storage" -o -path "$release/bootstrap/cache" -o -path "$release/node_modules" \) -prune \
    -o \( -user "$web_user" -o -perm -o+w "${group_tests[@]}" \) -print -quit 2>/dev/null || true)

if [[ -n "$writable" ]]; then
    echo "nonox-migrate: $web_user can write $writable; refusing to migrate as the owner" >&2
    exit 3
fi

cache="$(mktemp -d)" || exit 2
trap 'rm -rf "$cache"' EXIT

export APP_CONFIG_CACHE="$cache/config.php"
export APP_SERVICES_CACHE="$cache/services.php"
export APP_PACKAGES_CACHE="$cache/packages.php"
export APP_EVENTS_CACHE="$cache/events.php"
export APP_ROUTES_CACHE="$cache/routes.php"

# Logs to stderr, so the run never creates a log file the web user cannot write.
export LOG_CHANNEL=stderr

cd "$release" || exit 2

php artisan migrate --force --database=pgsql_owner
status=$?

php artisan nonox:db-grants || echo "nonox-migrate: the grants could not be applied; run php artisan nonox:db-grants" >&2

exit "$status"
