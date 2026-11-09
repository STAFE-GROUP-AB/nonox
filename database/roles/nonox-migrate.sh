#!/usr/bin/env bash
# Runs NONOX's migrations as the database owner (ADR-079 §6, docs/drift/databasroller.md).
#
# Install as root: /usr/local/bin/nonox-migrate, owner root, mode 0755. It is run by the
# DEPLOYING user (never the user PHP-FPM, the queue or the scheduler run as) through sudo as
# the Unix user nonox-migrate, which logs in to PostgreSQL as nonox_owner by peer
# authentication. No password exists anywhere:
#   sudo -u nonox-migrate /usr/local/bin/nonox-migrate "$FORGE_RELEASE_DIRECTORY"
#
# Defaults: NONOX_SITE_DIR=/home/forge/nonox.app, NONOX_WEB_USER=nonox-web. sudo does not pass
# the environment through, so change them here in the installed copy if they differ.
#
# Nothing the web user can write is loaded: the release and every directory above it up to
# the site must not be writable by it, and Laravel's cached config, services, packages,
# events, routes and compiled views are read from and written to a fresh directory owned by
# nonox-migrate instead of bootstrap/cache and storage.
#
# The grants are applied after the migrations whether they succeeded or not, so a migration
# that fails half-way never leaves a new table without its grants. The exit status is the
# migration's.
set -uo pipefail

requested="${1:-}"
site="${NONOX_SITE_DIR:-/home/forge/nonox.app}"
web_user="${NONOX_WEB_USER:-nonox-web}"

if ! id "$web_user" >/dev/null 2>&1; then
    echo "nonox-migrate: the web user $web_user does not exist; set NONOX_WEB_USER" >&2
    exit 3
fi

# Resolve every symlink once (current -> releases/<id>) and use only the resolved path from
# here on, so nothing can be swapped between the checks and the run.
site_real="$(realpath -- "$site" 2>/dev/null)" && [[ -d "$site_real" ]] || { echo "nonox-migrate: no site directory $site" >&2; exit 2; }
release="$(realpath -- "$requested" 2>/dev/null)" && [[ -d "$release" ]] || { echo "nonox-migrate: no such directory: $requested" >&2; exit 2; }

# Only a release directory of this site.
case "$release" in
    "$site_real"/releases/*) ;;
    *) echo "nonox-migrate: not a NONOX release directory: $requested" >&2; exit 2 ;;
esac

name="${release#"$site_real"/releases/}"

if [[ ! "$name" =~ ^[0-9A-Za-z_-]+$ ]] || [[ ! -f "$release/artisan" ]]; then
    echo "nonox-migrate: not a NONOX release directory: $requested" >&2
    exit 2
fi

# What the web user could change: owned by it, writable by one of its groups, or by everyone.
group_tests=()
for group in $(id -Gn "$web_user"); do
    group_tests+=(-o \( -group "$group" -perm -g+w \))
done

writable_test=(\( -user "$web_user" -o -perm -o+w "${group_tests[@]}" \))

# Every directory from the release up to and including the site directory.
dir="$release"
while :; do
    dir="$(dirname -- "$dir")"

    if [[ -n "$(find "$dir" -maxdepth 0 "${writable_test[@]}" -print 2>/dev/null)" ]]; then
        echo "nonox-migrate: $web_user can write $dir; refusing to migrate as the owner" >&2
        exit 3
    fi

    [[ "$dir" == "$site_real" || "$dir" == "/" ]] && break
done

# Nothing in the release outside storage/ (which holds no code that runs here: views are
# compiled into the private cache below) may be writable by the web user; .env, vendor and
# bootstrap/cache included. Symlinks are followed.
writable=$(find -L "$release" -path "$release/storage" -prune -o "${writable_test[@]}" -print -quit 2>/dev/null || true)

if [[ -n "$writable" ]]; then
    echo "nonox-migrate: $web_user can write $writable; refusing to migrate as the owner" >&2
    exit 3
fi

cache="$(mktemp -d)" || exit 2
trap 'rm -rf "$cache"' EXIT
mkdir -p "$cache/views"

# Laravel's caches and compiled views live in this private directory, never in the release.
export APP_CONFIG_CACHE="$cache/config.php"
export APP_SERVICES_CACHE="$cache/services.php"
export APP_PACKAGES_CACHE="$cache/packages.php"
export APP_EVENTS_CACHE="$cache/events.php"
export APP_ROUTES_CACHE="$cache/routes.php"
export VIEW_COMPILED_PATH="$cache/views"

# Logs to stderr, so the run never creates a log file the web user cannot write.
export LOG_CHANNEL=stderr

cd "$release" || exit 2

php artisan migrate --force --database=pgsql_owner
status=$?

php artisan nonox:db-grants || echo "nonox-migrate: the grants could not be applied; run php artisan nonox:db-grants" >&2

exit "$status"
