#!/usr/bin/env bash
# Builds the zip archives needed to deploy this repo to InfinityFree
# (see ../SKILL.md for why this is zip-based instead of FTP, and for why
# everything below targets *inside* htdocs/ rather than splitting siblings
# out to the account root).
#
# Usage: build_deploy_zips.sh [output-dir] [dev-tag]
#   output-dir  where to write the zips (default: /tmp/bowlsbuddy-deploy)
#   dev-tag     "true" or "false" for EP3_BS_DEV_TAG in config/init.php (default: true)
#
# Requires INFINITYFREE_DB_HOST, INFINITYFREE_DB_NAME, INFINITYFREE_DB_USER,
# INFINITYFREE_DB_PASSWORD to be set in the environment. Never echoes their values.
#
# Produces four zips, all meant to be extracted INSIDE htdocs/ on the server
# (never at the account root - InfinityFree's file manager refuses to extract
# there anyway):
#   htdocs-1-vendor.zip  -> vendor/            (skip re-uploading if composer.lock unchanged)
#   htdocs-2-src.zip     -> src/               (skip re-uploading if untouched since last deploy)
#   htdocs-3-app.zip     -> module/, config/, data/, modulex/, index.php, .htaccess,
#                           composer.json, composer.lock, VERSION  (extract at htdocs/ root)
#   htdocs-4-public.zip  -> contents of public/ (extract INSIDE htdocs/public/, not at htdocs/ root)

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
# --prefer-dist asks composer to download plain source archives instead of git
# clones. In this sandbox it doesn't actually help - GitHub's zipball dist
# endpoint returns 403 through the environment's proxy, so composer silently
# falls back to a full git clone per package regardless of this flag - but
# it's still correct to ask for it explicitly rather than relying on whatever
# preferred-install a given machine's global composer config happens to have.
# The `*/.git/*` exclusion below is what actually keeps the .git history out
# of htdocs-1-vendor.zip; don't remove it even if this flag starts working here.
composer install --ignore-platform-reqs --prefer-dist --no-interaction

echo "==> config/init.php (EP3_BS_DEV_TAG = $DEV_TAG)"
cp config/init.php.dist config/init.php
if [[ "$DEV_TAG" == "false" ]]; then
    sed -i 's/const EP3_BS_DEV_TAG = true;/const EP3_BS_DEV_TAG = false;/' config/init.php
fi

echo "==> config/autoload/local.php"
php "$(dirname "$0")/generate_local_php.php" "$REPO_ROOT/config/autoload/local.php"

echo "==> public/.htaccess"
cp public/.htaccess_original public/.htaccess

echo "==> building htdocs-1-vendor.zip (vendor/)"
rm -f "$OUT_DIR/htdocs-1-vendor.zip"
zip -r -q -9 "$OUT_DIR/htdocs-1-vendor.zip" vendor -x "*/.git/*" -x "*/.git"

echo "==> building htdocs-2-src.zip (src/)"
rm -f "$OUT_DIR/htdocs-2-src.zip"
zip -r -q -9 "$OUT_DIR/htdocs-2-src.zip" src -x "*/.git/*" -x "*/.git"

echo "==> building htdocs-3-app.zip (module, config, data, modulex, root files)"
rm -f "$OUT_DIR/htdocs-3-app.zip"
zip -r -q -9 "$OUT_DIR/htdocs-3-app.zip" \
    module config data modulex index.php composer.json composer.lock VERSION .htaccess \
    -x "config/init.php.dist" -x "config/autoload/local.php.dist" \
    -x "*/.git/*" -x "*/.git"

echo "==> building htdocs-4-public.zip (contents of public/, for htdocs/public/)"
rm -f "$OUT_DIR/htdocs-4-public.zip"
(cd public && zip -r -q -9 "$OUT_DIR/htdocs-4-public.zip" . \
    -x ".htaccess_original" -x ".htaccess_alternative")

echo "==> sanity checks"
fail=0

# Captured into variables (not piped straight into grep -q) because grep -q
# closes its input as soon as it finds a match, which sends SIGPIPE back to
# unzip - and under `set -o pipefail` that reads as a failed check even when
# the match was found. Command substitution sidesteps that.
app_listing="$(unzip -l "$OUT_DIR/htdocs-3-app.zip")"
public_listing="$(unzip -l "$OUT_DIR/htdocs-4-public.zip")"
vendor_listing="$(unzip -l "$OUT_DIR/htdocs-1-vendor.zip")"

# check_zip <listing> present|absent <regex> <failure message>
check_zip() {
    local listing="$1" expect="$2" pattern="$3" message="$4"
    local matched=0
    grep -qE "$pattern" <<< "$listing" && matched=1

    if [[ ("$expect" == "present" && $matched -eq 0) || ("$expect" == "absent" && $matched -eq 1) ]]; then
        echo "FAIL: $message" >&2
        fail=1
    fi
}

check_zip "$vendor_listing" absent '\.git/' \
    "htdocs-1-vendor.zip still contains .git/ entries (vendor packages were probably git-cloned by composer - check composer config)"
check_zip "$app_listing" present 'config/autoload/local\.php$' \
    "htdocs-3-app.zip is missing config/autoload/local.php"
check_zip "$app_listing" present 'config/init\.php$' \
    "htdocs-3-app.zip is missing config/init.php"
check_zip "$app_listing" present '^\.htaccess$' \
    "htdocs-3-app.zip is missing the root .htaccess (the one that routes everything into public/ and blocks .json/.lock/.md/.sql - NOT the same file as public/.htaccess)"
check_zip "$public_listing" present '[[:space:]]\.htaccess$' \
    "htdocs-4-public.zip is missing .htaccess at its root"
check_zip "$public_listing" absent '\.htaccess_(original|alternative)$' \
    "htdocs-4-public.zip leaked .htaccess_original or .htaccess_alternative"

for f in htdocs-1-vendor.zip htdocs-2-src.zip htdocs-3-app.zip htdocs-4-public.zip; do
    size=$(du -h "$OUT_DIR/$f" | cut -f1)
    count=$(unzip -l "$OUT_DIR/$f" | tail -1 | awk '{print $2}')
    echo "$f: $size, $count files"
done

if [[ $fail -ne 0 ]]; then
    echo "==> sanity checks FAILED - do not send these zips to the user, investigate first" >&2
    exit 1
fi

echo "==> OK: zips are ready in $OUT_DIR"
echo "==> Reminder: htdocs-1-vendor.zip and htdocs-2-src.zip only need re-uploading if"
echo "    composer.lock or src/ actually changed since the last deploy - check with:"
echo "    git log --oneline -- composer.lock src/"
