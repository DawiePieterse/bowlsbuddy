---
name: deploy-infinityfree
description: Deploy this repo (bowlsbuddy, a Zend Framework 2 PHP app) to its InfinityFree shared hosting at bowlsbuddy.42web.io. Use this whenever the user asks to deploy, redeploy, push a release, or update the live site/production, or mentions InfinityFree, ftpupload.net, htdocs, or bowlsbuddy.42web.io directly. Covers building the local config, packaging deployable zips, and the manual upload steps on InfinityFree's side.
---

# Deploying bowlsbuddy to InfinityFree

## Why this is a zip workflow, not FTP

InfinityFree only offers FTP (no SSH, no Composer, no shell), and outbound FTP
has not worked from any Claude Code cloud/web session tested so far: even with
`ftpupload.net` explicitly allowlisted in the environment's Network access
settings, a raw CONNECT tunnel to port 21 gets accepted by the egress proxy
(`200 Connection Established`) but never carries a single byte - consistent
with that egress fabric only actually carrying TLS/HTTPS traffic. If you're
running somewhere that plain FTP does work from (a local machine, a different
sandbox), just FTP the files across directly and skip the rest of this file -
in particular a real FTP client can push the whole `vendor/`/`src/` tree in
one go without hitting any of the File Manager limits described below.

Otherwise, use this as the fallback: build the deployment as zip files, hand
them to the user (they're the ones with a working browser session to
InfinityFree), and have them upload + extract through InfinityFree's
browser-based File Manager, which is plain HTTPS from their side and works
fine regardless of what the agent's own environment allows.

## The layout: everything lives inside htdocs/, nested public/

**This is the single most important fact about this deploy target, and it
directly contradicts a naive reading of the app's own bootstrap comment.**
`public/index.php` does `chdir(dirname(__DIR__))`, which on a normal host
would mean "put `vendor/`, `config/`, `module/`, `src/`, `data/`, `modulex/`
etc. one level *above* the web root, as siblings of `htdocs/`, with the
**contents** of `public/` flattened directly into `htdocs/`." An earlier
version of this skill assumed exactly that, and it is **wrong for this
specific InfinityFree account**:

- InfinityFree's File Manager (filemanager.ai-based "new3" UI) hard-refuses
  to extract a zip anywhere outside `htdocs/`, with the error "Extract path
  must be within an htdocs folder for security reasons." There is no
  known way around this from the browser file manager.
- The account root (the level containing `htdocs/`, one level above it) is
  explicitly marked off-limits by InfinityFree itself with a 0-byte file
  literally named `DO NOT UPLOAD FILES HERE`, dated to account creation.

The actual, correct, working layout - already reflected in this repo's own
root `.htaccess` (read its comment, it says this explicitly) - is:

- The **entire repository** (`module/`, `modulex/`, `src/`, `vendor/`,
  `config/`, `data/`, `public/`, `index.php`, `composer.json`,
  `composer.lock`, `.htaccess`, etc.) is extracted **inside `htdocs/`**,
  with `public/` remaining a **nested subfolder** (`htdocs/public/`), not
  flattened.
- The repo-root `.htaccess` (at `htdocs/.htaccess` once deployed) rewrites
  every request whose URI doesn't start with `/public/` into `public/$1`,
  and separately blocks direct access to `.json`/`.lock`/`.md`/`.sql`
  files. This is what lets `chdir(dirname(__DIR__))` inside
  `htdocs/public/index.php` correctly land on `htdocs/` (its real parent
  directory on disk) and find `vendor/`, `config/`, etc. right there.
- `htdocs/public/` has its **own, separate** `.htaccess` (generated from
  `public/.htaccess_original` by the build script), which does the normal
  front-controller rewrite (`RewriteRule ^.*$ index.php`) *within*
  `public/`. **Do not confuse the two `.htaccess` files** - accidentally
  putting `public/`'s `.htaccess` at `htdocs/` root (or vice versa) produces
  a site that intermittently half-works (may even render a cached page
  correctly once) before breaking on any fresh request or deeper route,
  which is a very confusing failure mode to debug after the fact. This
  exact mistake happened once already: uploading `htdocs.zip` (built to
  flatten `public/`'s contents into `htdocs/` root, per the old assumed
  layout) directly at `htdocs/` root instead of into `htdocs/public/`
  clobbered the correct root `.htaccess` with the front-controller one and
  scattered `public/`'s asset folders loose at `htdocs/` root as orphaned
  duplicates.

Build script output (`scripts/build_deploy_zips.sh`) reflects this: it
produces four zips, all meant to land inside `htdocs/`:

| Zip | Extract target | Typical size |
|---|---|---|
| `htdocs-1-vendor.zip` | `htdocs/` root (creates `vendor/`) | ~2,200 files |
| `htdocs-2-src.zip` | `htdocs/` root (creates `src/`) | ~2,000 files |
| `htdocs-3-app.zip` | `htdocs/` root (creates `module/`, `config/`, `data/`, `modulex/`, `index.php`, `.htaccess`, `composer.json`, `composer.lock`, `VERSION`) | ~600 files |
| `htdocs-4-public.zip` | **inside** `htdocs/public/` (create that folder first if missing) | ~300 files |

`vendor/` only changes if `composer.lock` changed; `src/` only changes if
something under `src/` changed (both tracked in git, `src/` is *not*
gitignored despite living next to `vendor/`). Check with
`git log --oneline -- composer.lock src/` before rebuilding/re-uploading
those two - skipping them when unchanged avoids the biggest timeout risk
below for no reason.

## Known File Manager failure modes (don't re-diagnose these from scratch)

- **"Extract path must be within an htdocs folder for security reasons"**:
  hard platform restriction, see above. Fix: change the extract target, not
  the zip.
- **"Invalid response from server" on a large zip's Zip & Extract**: seen
  once on a ~4,900-file zip targeted at the account root. It's ambiguous
  whether this was actually the same root-restriction above manifesting as a
  garbled error, or a genuine timeout from file count - it has not been
  conclusively reproduced for a large zip extracted *inside* `htdocs/` (a
  ~4,900-file combined deploy did successfully land inside `htdocs/` at some
  point, by means now lost to history - possibly a working FTP session from
  a non-sandboxed machine). If a large zip fails inside `htdocs/` too, split
  it further by subdirectory (e.g. split `vendor/` into a handful of smaller
  zips by package group) rather than assuming it can't work.
- **"Upload failed. Server returned status: 520"**: generic upstream/backend
  hiccup, unrelated to zip size or content - seen on a 296-file, 0.55MB zip
  well within previously-successful limits. Treat as transient: wait ~30-60s
  and retry; if it keeps failing, wait longer (possible short-lived
  rate-limiting after a burst of uploads) before assuming it's a real
  problem.
- **No chmod/permissions option in this File Manager UI**: the "More" menu
  (New File, New Folder, Edit, Download, Copy, Move, Delete) has no
  Permissions/chmod action, at least on a single folder selection. This
  turned out not to matter in practice - InfinityFree runs PHP as the
  account's own user, so uploaded/extracted folders are normally already
  writable without manual chmod. Don't chase this proactively; only revisit
  it if the live app throws an actual permission-denied error naming a
  specific folder (candidates: `data/cache/`, `data/log/`, `data/session/`,
  `public/docs-client/upload/`, `public/imgs-client/upload/`, all now nested
  under `htdocs/`).

## Deploy target facts

- Domain: `bowlsbuddy.42web.io`. `htdocs/` is the web root for this domain.
- DB credentials live in env vars `INFINITYFREE_DB_HOST` / `_NAME` / `_USER` /
  `_PASSWORD`. FTP env vars (`INFINITYFREE_FTP_*`) exist too but are currently
  unused since direct FTP doesn't work here - keep them around in case a future
  session runs somewhere that *can* reach port 21.
- InfinityFree disables PHP's `mail()`, and there typically aren't SMTP
  credentials on hand, so `config/autoload/local.php` ships with
  `mail.type = 'file'` (saves outgoing mail to `data/mails/` instead of sending
  it). Tell the user this is a placeholder - upgrading to real SMTP later is a
  one-file edit on the server.
- **`EP3_BS_DEV_TAG = false` (production mode) means a PHP fatal error shows
  as a completely blank page**, not an error message - errors go to a log
  instead of the screen. If the site goes blank after a deploy, don't guess:
  have the user temporarily flip `EP3_BS_DEV_TAG` back to `true` in
  `htdocs/config/init.php` (edit in place in the File Manager, no rebuild
  needed) and reload to see the real error, then flip it back to `false` once
  fixed. Don't leave it on `true` on a confirmed-working site.

## Steps

### 1. Confirm the env vars are actually readable in this session

```bash
for v in INFINITYFREE_DB_HOST INFINITYFREE_DB_NAME INFINITYFREE_DB_USER INFINITYFREE_DB_PASSWORD; do
  [ -n "${!v}" ] && echo "$v: SET" || echo "$v: UNSET"
done
```

If any are UNSET, stop and tell the user rather than guessing or hardcoding
values - these are per-environment secrets and won't be the same across
sessions.

**Never print or echo the raw values of these credentials** in any command
output - reference them only via `$VAR` inside commands, never `cat`/`grep`
them out, and don't quote them back to the user in chat.

### 2. Ask the user one thing: dev mode or production mode

See "Deploy target facts" above for what this flag actually does on this
host. Ask which they want for this deploy; default to `true` for a fresh
deploy that still needs verifying, `false` once the user says it's a routine
update to a site they already know works. Say clearly it should end up
`false` once confirmed working.

### 3. Check what actually needs rebuilding

```bash
git log --oneline -- composer.lock   # anything here? rebuild+reupload htdocs-1-vendor.zip
git log --oneline -- src/            # anything here since the last deploy? rebuild+reupload htdocs-2-src.zip
```

If neither changed, you can skip asking the user to re-extract those two
(biggest, slowest, most timeout-prone) zips at all and just point them at
the specific changed files instead (see step 4).

### 4. Build the zips

Run the bundled script rather than redoing these steps by hand - it also
carries the sanity checks that catch the ways this has silently broken before:

```bash
.claude/skills/deploy-infinityfree/scripts/build_deploy_zips.sh /tmp/bowlsbuddy-deploy true   # or false for prod mode
```

This does, in order: `composer install --ignore-platform-reqs --prefer-dist
--no-interaction`, builds `config/init.php` from the `.dist` with the chosen
dev-tag value, generates `config/autoload/local.php` by filling in the DB
placeholders in the repo's own `config/autoload/local.php.dist` (via a small
PHP helper that never echoes secret values and stays in sync with the dist
file instead of carrying its own copy), renames `public/.htaccess_original` to
`public/.htaccess`, and zips into the four `htdocs-*.zip` files described
above.

**The one bug worth knowing about ahead of time**: this repo's `composer
install` clones each vendor package with full git history (`.git/` and all),
which bloats a naive `vendor/` zip from ~6MB to over 100MB. `--prefer-dist`
is there in case it ever helps, but as of writing it doesn't fix this in a
Claude Code sandbox: GitHub's zipball dist endpoint returns `403 Could not
authenticate against github.com` through this environment's proxy, so
composer falls back to a full git clone per package regardless of the flag.
The `*/.git/*` exclusion in the zip commands is what actually keeps that
history out - don't remove it, and don't trust a zip's size at a glance;
the script verifies it for you, but if you ever rebuild this some other way,
verify it yourself too.

The script exits non-zero and refuses to declare success if any sanity check
fails. Don't send zips to the user if it failed - read the error and fix
the underlying cause first.

### 5. Hand the zips to the user

Use `SendUserFile`. Only send `htdocs-1-vendor.zip`/`htdocs-2-src.zip` if
step 3 said they actually changed - otherwise just send `htdocs-3-app.zip`
and `htdocs-4-public.zip`, which covers the vast majority of ordinary code
changes (all of `module/`, `config/`, `data/`, `modulex/`, and everything
under `public/`).

Mention that `htdocs-3-app.zip` contains the DB password in
`config/autoload/local.php`, so it should be treated like a credential file
rather than shared further.

### 6. Give them the manual upload steps

They need to do this part themselves - no way to drive their browser through
InfinityFree's control panel from here. Adapt wording, keep the substance -
**a fresh/from-scratch deploy**:

1. Log into the InfinityFree control panel -> open File Manager for
   `bowlsbuddy.42web.io`, navigate into `htdocs/`.
2. If starting clean: select everything inside `htdocs/` and delete it (the
   `htdocs` folder itself can't be deleted, that's fine).
3. Create a subfolder inside `htdocs/` named `public`.
4. At `htdocs/` root, upload + Zip & Extract `htdocs-1-vendor.zip`,
   `htdocs-2-src.zip`, `htdocs-3-app.zip` one at a time, each with "Extract
   to" left empty (extracts to the current directory). If a large one fails,
   see "Known File Manager failure modes" above before assuming it's
   unfixable.
5. Go into the `htdocs/public/` folder just created, upload
   `htdocs-4-public.zip` there, and extract it **inside that folder** - not
   at `htdocs/` root.
6. Folder permissions: normally nothing to do (see "Known File Manager
   failure modes"). Only chase this if the live app later throws a
   permission-denied error.
7. Visit `https://bowlsbuddy.42web.io/setup.php` (no `/public/` prefix
   needed - the root `.htaccess` routes it there automatically) to create
   the DB schema.
8. **If setup.php throws `RuntimeException: System has already been
   setup`**: this is `Setup\Controller\Plugin\ValidateSetup` refusing to
   run because a plain `SHOW TABLES` on the configured database returned at
   least one table - it does *not* check any file or config marker, purely
   live DB state. To force a genuinely clean setup: open phpMyAdmin from the
   InfinityFree control panel (MySQL Databases -> phpMyAdmin), select the
   bowlsbuddy database, "Check all" on the table list, choose **Drop** from
   the "With selected:" dropdown, confirm. Once `SHOW TABLES` comes back
   empty, re-run setup.php.
9. **Delete `htdocs/public/setup.php` immediately afterward.** This isn't
   optional - leaving it up lets anyone rebuild/reset the schema (including
   re-triggering the DB-wipe path above). Flag this clearly, don't bury it.
10. Confirm PHP 8.1+ is selected for the domain in the control panel - this
    can't be checked or set from this workflow, it's a browser-only setting
    on their end.

**For an ordinary code-update redeploy** (the common case): skip straight to
step 4/5 for whichever zips actually changed (see step 3 above), overwriting
the existing files in place - no need to delete/recreate anything, and no
need to touch setup.php or the database at all.

### 7. Standing reminders, every time this runs

- The InfinityFree account password (used for both FTP and MySQL) has been
  pasted in plaintext across chat sessions before. Once a deploy is confirmed
  working, recommend rotating it.
- If `EP3_BS_DEV_TAG` was left `true` for a fresh-deploy debugging pass, remind
  the user to flip it to `false` in `htdocs/config/init.php` (rerun step 4
  with `false`, or edit the file directly on the server) once they've
  confirmed everything works. A blank page after that flip, with no other
  changes, almost always means a real error is now being silently logged
  instead of shown - see "Deploy target facts" above.
