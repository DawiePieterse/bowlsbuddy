#!/usr/bin/env bash
# Builds the two zip archives needed to deploy this repo to InfinityFree
# (see ../SKILL.md for why this is zip-based instead of FTP).
#
# Usage: build_deploy_zips.sh [output-dir] [dev-tag]
#   output-dir  where to write htdocs.zip and approot.zip (default: /tmp/bowlsbuddy-deploy)
#   dev-tag     "true" or "false" for EP3_BS_DEV_TAG in config/init.php (default: true)
#
# Requires INFINITYFREE_DB_HOST, INFINITYFREE_DB_NAME, INFINITYFREE_DB_USER,
# INFINITYFREE_DB_PASSWORD to be set in the environment. Never echoes their values.

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
OUT_DIR="${1:-/tmp/bowlsbuddy-deploy}"
DEV_TAG="${2:-true}"

if [[ "$DEV_TAG" != "true" && "$DEV_TAG" != "false" ]]; then
    echo "dev-tag must be 'true' or 'false', got: $DEV_TAG" >&2
    exit 1
fi

for v in INFINITYFREE_DB_HOST INFINITYFREE_DB_NAME INFINITYFREE_DB_USER INFINITYFREE_DB_PASSWORD; do
    if [[ -z "${!v:-}" ]]; then
        echo "Missing required env var: $v (not printing values of the others either)" >&2
        exit 1
    fi
done

cd "$REPO_ROOT"
mkdir -p "$OUT_DIR"

echo "==> composer install"
composer install --ignore-platform-reqs --no-interaction

echo "==> config/init.php (EP3_BS_DEV_TAG = $DEV_TAG)"
cp config/init.php.dist config/init.php
if [[ "$DEV_TAG" == "false" ]]; then
    sed -i 's/const EP3_BS_DEV_TAG = true;/const EP3_BS_DEV_TAG = false;/' config/init.php
fi

echo "==> config/autoload/local.php"
php "$(dirname "$0")/generate_local_php.php" "$REPO_ROOT/config/autoload/local.php"

echo "==> public/.htaccess"
cp public/.htaccess_original public/.htaccess

echo "==> building htdocs.zip (contents of public/)"
rm -f "$OUT_DIR/htdocs.zip"
(cd public && zip -r -q -9 "$OUT_DIR/htdocs.zip" . \
    -x ".htaccess_original" -x ".htaccess_alternative")

echo "==> building approot.zip (siblings of htdocs/)"
rm -f "$OUT_DIR/approot.zip"
zip -r -q -9 "$OUT_DIR/approot.zip" \
    module config data src vendor modulex index.php composer.json composer.lock VERSION \
    -x "config/init.php.dist" -x "config/autoload/local.php.dist" \
    -x "*/.git/*" -x "*/.git"

echo "==> sanity checks"
fail=0

# Captured into variables (not piped straight into grep -q) because grep -q
# closes its input as soon as it finds a match, which sends SIGPIPE back to
# unzip - and under `set -o pipefail` that reads as a failed check even when
# the match was found. Command substitution sidesteps that.
approot_listing="$(unzip -l "$OUT_DIR/approot.zip")"
htdocs_listing="$(unzip -l "$OUT_DIR/htdocs.zip")"

if grep -q '\.git/' <<< "$approot_listing"; then
    echo "FAIL: approot.zip still contains .git/ entries (vendor packages were probably git-cloned by composer - check composer config)" >&2
    fail=1
fi

if ! grep -q 'config/autoload/local\.php$' <<< "$approot_listing"; then
    echo "FAIL: approot.zip is missing config/autoload/local.php" >&2
    fail=1
fi

if ! grep -q 'config/init\.php$' <<< "$approot_listing"; then
    echo "FAIL: approot.zip is missing config/init.php" >&2
    fail=1
fi

if ! grep -qE '[[:space:]]\.htaccess$' <<< "$htdocs_listing"; then
    echo "FAIL: htdocs.zip is missing .htaccess at its root" >&2
    fail=1
fi

if grep -qE '\.htaccess_(original|alternative)$' <<< "$htdocs_listing"; then
    echo "FAIL: htdocs.zip leaked .htaccess_original or .htaccess_alternative" >&2
    fail=1
fi

approot_size=$(du -h "$OUT_DIR/approot.zip" | cut -f1)
htdocs_size=$(du -h "$OUT_DIR/htdocs.zip" | cut -f1)
echo "approot.zip: $approot_size"
echo "htdocs.zip:  $htdocs_size"

if [[ $fail -ne 0 ]]; then
    echo "==> sanity checks FAILED - do not send these zips to the user, investigate first" >&2
    exit 1
fi

echo "==> OK: $OUT_DIR/approot.zip and $OUT_DIR/htdocs.zip are ready"
