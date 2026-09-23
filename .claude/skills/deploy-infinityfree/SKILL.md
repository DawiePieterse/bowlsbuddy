---
name: deploy-infinityfree
description: Deploy this repo (bowlsbuddy, a Zend Framework 2 PHP app) to its InfinityFree shared hosting at bowlsbuddy.42web.io. Use this whenever the user asks to deploy, redeploy, push a release, or update the live site/production, or mentions InfinityFree, ftpupload.net, htdocs, or bowlsbuddy.42web.io directly. Covers building the local config, packaging deployable zips, and the manual upload steps on InfinityFree's side.
---

# Deploying bowlsbuddy to InfinityFree

## Why this is a zip workflow, not FTP

InfinityFree only offers FTP (no SSH, no Composer, no shell) - and from a Claude
Code cloud/web session, outbound FTP does not work. This was confirmed by testing
directly: even with `ftpupload.net` explicitly allowlisted in the environment's
Network access settings, a raw CONNECT tunnel to port 21 gets accepted by the
egress proxy (`200 Connection Established`) but never carries a single byte - the
underlying egress fabric only actually carries TLS/HTTPS traffic. No Network
access setting fixes this; don't spend time re-testing it per deploy.

The workaround: build the deployment as two zip files, hand them to the user
(they're the ones with a working browser session to InfinityFree), and have them
upload + extract through InfinityFree's browser-based File Manager, which is
plain HTTPS from their side and works fine.

## Deploy target facts

- Domain: `bowlsbuddy.42web.io`. `htdocs/` is the web root for this domain.
- `public/index.php` does `chdir(dirname(__DIR__))`, so it expects `vendor/`,
  `config/`, `module/`, `src/`, `data/`, `modulex/` etc. to be its parent's
  *siblings* - not nested inside `htdocs/`. Concretely: the **contents** of
  `public/` become `htdocs/`, and everything else the app needs goes into the
  account root as siblings of `htdocs/`, not inside it.
- DB credentials live in env vars `INFINITYFREE_DB_HOST` / `_NAME` / `_USER` /
  `_PASSWORD`. FTP env vars (`INFINITYFREE_FTP_*`) exist too but are currently
  unused since direct FTP doesn't work here - keep them around in case a future
  session runs somewhere that *can* reach port 21.
- InfinityFree disables PHP's `mail()`, and there typically aren't SMTP
  credentials on hand, so `config/autoload/local.php` ships with
  `mail.type = 'file'` (saves outgoing mail to `data/mails/` instead of sending
  it). Tell the user this is a placeholder - upgrading to real SMTP later is a
  one-file edit on the server.

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

`config/init.php`'s `EP3_BS_DEV_TAG` controls whether errors are displayed
on-screen (`true`, useful right after a fresh deploy while verifying it works)
or only logged (`false`, safer once the site is confirmed working and could see
real traffic). Ask which they want for this deploy; default to `true` if
they don't have a preference, since a fresh deploy usually needs debugging
first - but say clearly that it should be flipped to `false` once confirmed.

### 3. Build the zips

Run the bundled script rather than redoing these steps by hand - it also
carries the sanity checks that catch the ways this has silently broken before:

```bash
.claude/skills/deploy-infinityfree/scripts/build_deploy_zips.sh /tmp/bowlsbuddy-deploy true   # or false for prod mode
```

This does, in order: `composer install --ignore-platform-reqs --no-interaction`,
builds `config/init.php` from the `.dist` with the chosen dev-tag value,
generates `config/autoload/local.php` from the DB env vars (via a small PHP
helper that never echoes secret values), renames `public/.htaccess_original` to
`public/.htaccess`, and zips everything into `approot.zip` (siblings) and
`htdocs.zip` (webroot contents).

**The one bug worth knowing about ahead of time**: this repo's `composer
install` clones each vendor package with full git history (`.git/` and all),
which bloats a naive `vendor/` zip from ~6MB to over 100MB. The script already
excludes `*/.git/*` and checks the built zip has zero `.git/` entries before
declaring success - if you ever rebuild this some other way, don't skip that
exclusion, and don't trust a zip's size at a glance; verify it.

The script exits non-zero and refuses to declare success if any sanity check
fails (missing `.htaccess`, leaked `.git/` entries, missing `local.php`/
`init.php`). Don't send zips to the user if it failed - read the error and fix
the underlying cause first.

### 4. Hand the zips to the user

Use `SendUserFile` for both `approot.zip` and `htdocs.zip`. Mention that
`approot.zip` contains their DB password in `config/autoload/local.php`, so
they should treat it like a credential file rather than sharing it further.

### 5. Give them the manual upload steps

They need to do this part themselves - I have no way to drive their browser
through InfinityFree's control panel from here. Give them (adapt wording, keep
the substance):

1. Log into the InfinityFree control panel -> open File Manager for
   `bowlsbuddy.42web.io`.
2. Upload `approot.zip` **at the account root** (the level that contains
   `htdocs/`, not inside it). Extract it in place (File Manager's
   Extract/Unzip action), then delete the zip.
3. Upload `htdocs.zip` **inside `htdocs/`**, extract it there, then delete
   the zip.
4. Check/set these five directories writable (755, escalate to 777 only if
   the app throws a permission error): `data/cache/`, `data/log/`,
   `data/session/` (siblings, outside htdocs), and
   `htdocs/docs-client/upload/`, `htdocs/imgs-client/upload/`.
5. Visit `https://bowlsbuddy.42web.io/setup.php` in a browser to create the
   DB schema.
6. **Delete `htdocs/setup.php` immediately afterward.** This isn't optional -
   leaving it up lets anyone rebuild/reset the schema. Flag this clearly, don't
   bury it.
7. Confirm PHP 8.1+ is selected for the domain in the control panel - this
   can't be checked or set from this workflow, it's a browser-only setting on
   their end.

### 6. Standing reminders, every time this runs

- The InfinityFree account password (used for both FTP and MySQL) has been
  pasted in plaintext across chat sessions before. Once a deploy is confirmed
  working, recommend rotating it.
- If `EP3_BS_DEV_TAG` was left `true` for a fresh-deploy debugging pass, remind
  the user to flip it to `false` in `config/init.php` (rerun step 3 with
  `false`, or edit the file directly on the server) once they've confirmed
  everything works.
