#!/usr/bin/env bash
#
# AutoWave release script (docs/09-devops/deployment.md).
#
#   deploy.sh [deploy [branch]]   build a new release from GitHub and switch to it (default branch: master)
#   deploy.sh rollback            switch back to the previous release (code only; migrations are not reverted)
#   deploy.sh releases            list releases and mark the live one
#
# Run as the deploy user:  sudo -iu autowave /var/www/autowave-platform/deploy.sh
# Overridable through the environment: APP_DIR, REPO, BRANCH, KEEP_RELEASES, PHP_BIN, DEPLOY_USER, NODE_MEMORY_MB.

set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/autowave-platform}"
REPO="${REPO:-git@github.com:akashwagh1703/autowave.git}"
BRANCH="${BRANCH:-master}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
PHP_BIN="${PHP_BIN:-php8.4}"
DEPLOY_USER="${DEPLOY_USER:-autowave}"
NODE_MEMORY_MB="${NODE_MEMORY_MB:-1536}"

RELEASES="$APP_DIR/releases"
SHARED="$APP_DIR/shared"
CURRENT="$APP_DIR/current"

NEW_RELEASE=""
MIGRATED=0
SWITCHED=0

log() { printf '\n==> %s\n' "$*"; }
fail() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }
artisan() { "$PHP_BIN" artisan "$@"; }

require_user() {
    [ "$(id -un)" = "$DEPLOY_USER" ] || fail "run as $DEPLOY_USER: sudo -iu $DEPLOY_USER $0"
}

acquire_lock() {
    exec 9>"$APP_DIR/.deploy.lock"
    flock -n 9 || fail "another deploy or rollback is running"
}

list_releases() {
    find "$RELEASES" -mindepth 1 -maxdepth 1 -type d | sort -r
}

live_release() {
    readlink -f "$CURRENT" 2>/dev/null || true
}

# Atomic: the new link is created beside `current`, then renamed over it.
switch_to() {
    ln -sfn "$1" "$APP_DIR/current.new"
    mv -Tf "$APP_DIR/current.new" "$CURRENT"
}

restart_services() {
    (cd "$CURRENT" && artisan queue:restart)
    if sudo -n systemctl reload php8.4-fpm 2>/dev/null; then
        echo "php8.4-fpm reloaded"
    fi
}

app_host() {
    sed -n 's/^AUTOWAVE_APP_HOST=//p' "$SHARED/.env" | tr -d '"' | tail -n 1
}

smoke_test() {
    local host
    host="$(app_host)"
    if [ -z "$host" ]; then
        echo "AUTOWAVE_APP_HOST is not set; smoke test skipped"
        return 0
    fi
    if curl -fsS -o /dev/null --max-time 20 --resolve "$host:443:127.0.0.1" "https://$host/up"; then
        echo "https://$host/up answered 200"
    else
        echo "WARNING: https://$host/up did not answer 200. Check storage/logs, or roll back: $0 rollback" >&2
        return 1
    fi
}

record() {
    printf '%s %s %s\n' "$(date '+%F %T')" "$1" "$2" >> "$APP_DIR/deployments.log"
}

prune_releases() {
    local live old
    live="$(live_release)"
    list_releases | tail -n +"$((KEEP_RELEASES + 1))" | while read -r old; do
        if [ "$old" != "$live" ]; then
            rm -rf "$old"
            echo "removed old release $(basename "$old")"
        fi
    done
}

on_deploy_error() {
    local code=$?
    trap - ERR
    cd /
    if [ -n "$NEW_RELEASE" ] && [ "$SWITCHED" = 0 ]; then
        rm -rf "$NEW_RELEASE"
        printf '\nDeploy failed (exit %s). The live site was not changed; the unfinished release was removed.\n' "$code" >&2
        if [ "$MIGRATED" = 1 ]; then
            echo "Note: migrations had already run. They must stay compatible with the live release." >&2
        fi
    else
        printf '\nDeploy failed after switching (exit %s). Check the site; roll back with: %s rollback\n' "$code" "$0" >&2
    fi
    exit "$code"
}

cmd_deploy() {
    local branch="${1:-$BRANCH}" sha

    [ -f "$SHARED/.env" ] || fail "$SHARED/.env is missing"
    trap on_deploy_error ERR

    NEW_RELEASE="$RELEASES/$(date +%Y%m%d%H%M%S)"
    log "Cloning $branch into $NEW_RELEASE"
    git clone --quiet --depth 1 --branch "$branch" "$REPO" "$NEW_RELEASE"
    cd "$NEW_RELEASE"
    sha="$(git rev-parse --short HEAD)"
    printf '%s %s\n' "$sha" "$branch" > REVISION
    ln -s "$SHARED/.env" .env
    rm -rf storage
    ln -s "$SHARED/storage" storage

    log "Installing PHP dependencies"
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

    log "Building frontend"
    NODE_OPTIONS="--max-old-space-size=$NODE_MEMORY_MB" npm ci --no-audit --no-fund
    NODE_OPTIONS="--max-old-space-size=$NODE_MEMORY_MB" npm run build
    rm -rf node_modules

    log "Migrating database"
    MIGRATED=1
    artisan migrate --force

    log "Caching and checking the new release"
    artisan storage:link
    artisan optimize
    artisan autowave:health

    log "Switching live site to $sha"
    switch_to "$NEW_RELEASE"
    SWITCHED=1
    restart_services
    record deploy "$sha $branch $(basename "$NEW_RELEASE")"

    prune_releases
    smoke_test || true

    # Replaced by rename, so the copy bash is executing right now is left untouched.
    cp "$NEW_RELEASE/scripts/deploy.sh" "$APP_DIR/deploy.sh.new"
    chmod 755 "$APP_DIR/deploy.sh.new"
    mv -f "$APP_DIR/deploy.sh.new" "$APP_DIR/deploy.sh"

    trap - ERR
    log "Deployed $sha ($branch)"
}

cmd_rollback() {
    local live previous
    live="$(live_release)"
    [ -n "$live" ] || fail "$CURRENT does not point to a release"
    previous="$(list_releases | awk -v live="$live" 'found { print; exit } $0 == live { found = 1 }')"
    [ -n "$previous" ] || fail "there is no release older than $(basename "$live")"

    log "Rolling back $(basename "$live") -> $(basename "$previous")"
    echo "Database migrations are not reverted (docs/11-runbooks/rollback.md)."
    (cd "$previous" && artisan optimize)
    switch_to "$previous"
    restart_services
    record rollback "$(basename "$live") -> $(basename "$previous")"
    smoke_test || true
    log "Live release: $(basename "$previous")"
}

cmd_releases() {
    local live release revision marker
    live="$(live_release)"
    list_releases | while read -r release; do
        revision="$(cat "$release/REVISION" 2>/dev/null || echo 'unknown')"
        marker=""
        [ "$release" = "$live" ] && marker="  <- live"
        printf '%s  %-24s%s\n' "$(basename "$release")" "$revision" "$marker"
    done
}

main() {
    local command="${1:-deploy}"
    [ $# -gt 0 ] && shift

    case "$command" in
        deploy)
            require_user
            acquire_lock
            cmd_deploy "$@"
            ;;
        rollback)
            require_user
            acquire_lock
            cmd_rollback
            ;;
        releases)
            cmd_releases
            ;;
        -h | --help | help)
            sed -n '3,10p' "$0"
            ;;
        *)
            fail "unknown command '$command' (use: deploy [branch] | rollback | releases)"
            ;;
    esac
}

main "$@"
