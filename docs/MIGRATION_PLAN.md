# Bowls Buddy rebuild plan

**Goal:** rebuild Bowls Buddy on a modern, supported PHP stack. The new app **keeps the existing data structure**
(the same `bs_*` tables, columns and relationships), while being faster, safer and easy to set up for new
clubs.

**Starting point:** the current app has never been used live. There are **no users, clubs or bookings to
carry over**, so this is a clean rebuild: no data migration, no running old and new side by side, and no
rollback plan. The current code serves as the **reference for how everything should behave**.

**Decided:** the current app will **not be launched**. It is kept only as the reference, so no fixes are made to it;
the issues in section 2 are fixed in the rebuild only.

**Status:** proposal. No code has changed yet.

---

## 1. Summary

| | Current code | Rebuild |
|---|---|---|
| Framework | Zend Framework 2, abandoned since 2019 and patched by hand in `src/Zend/` | Laravel 12 on PHP 8.3+, with security fixes and long-term support |
| Member pages | jQuery 1.12.4, TinyMCE 4 | Blade templates + Livewire/Alpine.js, no build step on the server |
| Secretary / admin screens | Hand-built ZF2 forms and lists | **Filament** admin panel, generated from the models, with search, filters and forms |
| Database | `bs_*` tables, `utf8`, with key/value meta tables | **Same `bs_*` tables and columns**, created by Laravel migrations, `utf8mb4`, better indexes |
| Security | CSRF on 2 forms only, deletes via GET links, bcrypt cost 6 | CSRF on every form, deletes only via POST, bcrypt cost 12, rate-limited login |
| Tests | None | Tests for every booking rule, run automatically on each push |
| New club | Manual install + setup wizard | One command: `php artisan club:create` |

**Estimate:** roughly **5–8 weeks** of part-time work for one developer with AI assistance.

---

## 2. Why rebuild instead of patching

Found while reviewing the current code. Each issue is fixed by design in the rebuild (section 6).

**Security**
1. **CSRF protection on 2 forms only:** the green open/close form and registration. Login, booking,
   cancelling and every admin form lack it.
2. **Deletes through plain links** (`…/delete/:uid?confirmed=true` for users, bookings, events and rinks). A
   link tapped by a logged-in Secretary deletes data.
3. **Bcrypt cost 6** (`User/Manager/UserSessionManager.php`), plus an old MD5 password fallback.
4. **Unmaintained libraries:** Zend Framework 2 (vendored and hand-patched for PHP 8), jQuery 1.12.4 (known
   XSS issues), TinyMCE 4 (end of life).
5. **`unserialize()` on stored data:** player names are stored PHP-serialized in `bs_bookings_meta` and read
   back in four places.
6. **Predictable booking confirmation token:** `sha1('Quick and dirty' . floor(time() / 1800))`.

**Correctness**
7. **Time zone set to `Europe/Berlin`** (`config/init.php`). South Africa is UTC+2 all year, so part of the
   year "past slot" checks and cancel cut-offs would be an hour off.
8. **Double-booking race:** availability is checked before the booking transaction starts, without a lock.
9. **`utf8` tables** can't store emoji or some characters in names.

**Maintainability**
10. About 28,000 lines of PHP and templates, hand-built factories for every service, no tests, and many
    features the club doesn't use (pricing, products, coupons, bills, emails, repeating bookings).

Fixing all of that inside ZF2 would cost about as much as the rebuild and still leave an unsupported
framework.

---

## 3. Goals and non-goals

**Goals**
- Keep the **existing data structure**: same table names, primary keys, columns and relationships, so the
  current code stays a valid reference and nothing conceptual changes.
- Match every feature LCE needs (section 7).
- Secure by default; tested booking rules; South African time zone.
- Set up a new club in minutes, on cheap shared PHP hosting (no Node.js or Docker needed in production).

**Non-goals**
- A visual redesign. The current look and CSS are reused.
- Online payments, pricing, products, coupons, bills, emails and repeating bookings. Their tables are not
  created now; they can be added later from `data/db/ep3-bs.sql` if a paying club needs them.
- Many clubs in one install (multi-tenant). One install per club for now (section 9).

---

## 4. Target stack

**Decided: Laravel 12 + Filament.**

| Option | Verdict |
|---|---|
| **Laravel 12 + Filament (chosen)** | Same language as the current app, so the booking rules port almost line by line and can be tested against it. Runs on shared PHP hosting. CSRF, auth, rate limiting, validation, migrations and testing are built in. Eloquent models map directly onto `bs_*` tables with their own primary keys (`bid`, `sid`...). Large community and long support. Filament generates the Secretary's admin screens from the models. |
| Django (Python) | Equally complete, with a strong built-in admin, but needs Python hosting and a translation of the PHP rules. Laravel + Filament gives the same admin benefit. |
| Laminas MVC (ZF2's successor) | Quickest port of the old code, but keeps the dated architecture and lack of tests. With no users to protect, there's no reason to take the shortcut. |
| Symfony | Excellent, but more setup than this app needs. |
| JavaScript stack (Next.js etc.) | Won't run on PHP-only hosting and pushes a different data model. Rejected. |

**Pieces**
- **PHP 8.3+**, **Laravel 12**, **Eloquent** models on the `bs_*` tables.
- **Blade + Livewire 3** for interactive parts (booking pop-up, calendar paging), **Alpine.js** for small
  touches. Assets are built with Vite in CI and shipped as static files.
- **Filament** for the Secretary / admin panel at `/admin`: resources for members, bookings, events, rinks and
  settings pages. It uses the same Laravel policies and privileges, and its assets are published as static
  files, so there's still no build step on the server. Needs the PHP `intl` extension, which the current app
  already requires.
- Member-facing pages (greens overview, green calendar, booking pop-up, day sheet) stay **custom Blade +
  Livewire**, so they look and behave exactly as they do now. The Secretary's green open/close and WhatsApp
  invite buttons stay on the greens page, as today.
- **Pest** (tests), **Pint** (code style), **Larastan/PHPStan** (static checks), **GitHub Actions** (CI).
- **Sessions and cache in the database**, because shared hosts can wipe file storage.
- **Time zone `Africa/Johannesburg`**; the stored times stay local wall-clock times, as today.

---

## 5. Data structure

### 5.1 Tables kept (same names, keys and columns)

| Table | Model | Key | What it holds |
|---|---|---|---|
| `bs_users` | `User` | `uid` | Login email, password hash, `status` (`enabled`, `disabled`, `admin`, `assist`...), last activity |
| `bs_users_meta` | `UserMeta` | `umid` | `firstname`, `lastname`, `locale`, `allow.*` privileges |
| `bs_squares` | `Rink` | `sid` | Rink name (`A-1`: prefix = green), capacity, times, time blocks, booking and cancel ranges |
| `bs_squares_meta` | `RinkMeta` | `smid` | Ask-for-names setting, name visibility, info and rules texts (`locale`-aware) |
| `bs_bookings` | `Booking` | `bid` | Who booked which rink, status (`single`, `cancelled`), quantity (players) |
| `bs_bookings_meta` | `BookingMeta` | `bmid` | `player-names`, notes |
| `bs_reservations` | `Reservation` | `rid` | Date and time range of each booking |
| `bs_reservations_meta` | `ReservationMeta` | `rmid` | (spare, kept for compatibility) |
| `bs_events` | `Event` | `eid` | Blocked time: one rink (`sid`), a green (meta `green`) or all rinks |
| `bs_events_meta` | `EventMeta` | `emid` | `name`, `description`, `green` (`locale`-aware) |
| `bs_options` | `Option` | `oid` | Site settings, including `service.greens.closed` (`YYYY-MM-DD:A` lines) |

Foreign keys stay as in `data/db/ep3-bs.sql`, with `ON DELETE CASCADE` for the meta tables. A shared
**`HasMeta` trait** gives every model `->meta('key')` and loads all meta for a page in one query.

### 5.2 Deliberate improvements (cheap now because there is no data)

| Change | Why |
|---|---|
| All tables `utf8mb4` / `utf8mb4_unicode_ci` | Names with any character or emoji |
| Composite indexes: `bs_reservations (date, time_start)`, `bs_bookings (sid, status)`, `bs_bookings (uid, status)`, every meta table `(owner_id, key)`, `bs_options (key, locale)`, `bs_events (datetime_start, datetime_end)` | The greens overview, calendar and "one rink per day" check become index lookups |
| `player-names` stored as **JSON** instead of PHP-serialized | No `unserialize()` on stored data |
| `status` columns get `CHECK` constraints listing the allowed values | Bad values can't be saved |
| Laravel's own tables prefixed `bb_` (`bb_sessions`, `bb_cache`, `bb_migrations`) | Kept apart from the app's tables |
| Unused tables (`bs_squares_pricing`, `_products`, `_coupons`, `bs_bookings_bills`) **not created** | Less to secure and maintain; add back later if needed |

The column names and meaning of everything else stay the same.

### 5.3 Booking rules to rebuild exactly (tests first)

The current behaviour is the specification, taken from `Square/Service/SquareValidator.php`,
`Square/Manager/GreenManager.php`, `Frontend/Controller/IndexController.php` and the `Booking` services:

1. A slot is bookable between the rink's `time_start` and `time_end`, in `time_block` steps (60 minutes
   at LCE), not in the past, and within `range_book` days / after `min_range_book` lead time.
2. At most `capacity` players per rink (2 at LCE). With `capacity_heterogenic = 0`, one booking per slot.
3. **One rink per member per day.** Staff with the `calendar.create-single-bookings` privilege are exempt.
4. **Closed greens.** A closed green blocks all its rinks for that day.
5. **Events** block one rink, one green or all rinks.
6. **Hidden days** from `service.calendar.day-exceptions` (weekday names or dates).
7. **Cancel cut-off** `range_cancel` hours before the start.
8. **Greens overview:** next 14 playing days, with free and total slots and event names per green.
9. **Day sheet:** rinks by hour, with player names, events and closed greens.
10. **Privileges:** `admin.user`, `admin.booking`, `admin.event`, `admin.config`, `admin.see-menu`,
    `calendar.*` (see `User::$privileges`), granted by `status = admin` or meta `allow.<privilege>`.

**No double bookings:** a booking is created inside a transaction that locks that rink's reservations for the
date (`SELECT … FOR UPDATE`), re-checks rules 1–5, then inserts.

**Reference tests:** run the current app locally with a seeded set of rinks, events, closures and bookings.
Record its greens overview numbers and booking accept/refuse results, and use them as expected values in the
new app's tests.

---

## 6. Security and efficiency by design

**Security**
- CSRF token on every form. Anything that changes data is POST, PUT or DELETE, never a link.
- **Policies** on every admin action, mapped to the privileges above.
- Login **rate limiting** per email and IP. Bcrypt cost 12. New session ID at login. Secure, HttpOnly,
  SameSite=Lax cookies. HTTPS only.
- Security headers: `Content-Security-Policy`, `X-Frame-Options: DENY`, `Referrer-Policy`,
  `Permissions-Policy`.
- Output escaped by default (Blade). Rich-text info and help pages are cleaned with an HTML purifier on save.
- `APP_DEBUG=false` in production, errors logged, friendly error page.
- `composer audit` and Dependabot for vulnerable packages.
- **POPIA:** store only first name, surname and email. Members can download their data and delete their
  account. Password resets go through the Secretary (no email).

**Efficiency**
- Eager loading: one query per table per page, not one per booking.
- Options cached; cache cleared when settings are saved.
- Greens overview cached per day; cache cleared when a booking, event or closure changes.
- No jQuery or jQuery UI; small, versioned, long-cached assets.
- Target: under 150 ms server time and under 15 queries per page on shared hosting.

---

## 7. Feature checklist (definition of done)

**Members**
- [ ] Registration: first name, surname, email, password, terms and privacy acceptance, anti-bot delay
- [ ] Log in and log out; "forgot password" page pointing to the Secretary
- [ ] Greens overview: 14 playing days, free slots, closed (red), events (purple)
- [ ] Green calendar: rinks by hourly slots, player names for logged-in members, own bookings in green
- [ ] Book a slot: 1–2 players, partner's name, rules acceptance, one-rink-per-day message
- [ ] WhatsApp share after booking and from the booking pop-up
- [ ] Cancel own booking before the cut-off
- [ ] My bookings; My account (change email or password, delete account, download my data)
- [ ] Info page, help page, Business Terms and Privacy Policy PDFs

**Secretary / admin**
- [ ] Open or close a green per day
- [ ] Invite members via WhatsApp
- [ ] Printable day sheet with QR code to live bookings
- [ ] Members: search, create, edit, activate, set a temporary password, privileges
- [ ] Bookings: list, create for a member, edit, cancel, delete
- [ ] Events: for a rink, a green or all rinks; list, edit, delete
- [ ] Settings: names and text, info and help pages, rinks, behaviour, terms and privacy uploads

**New club setup**
- [ ] `php artisan club:create` asks for the club name, admin email, greens, rinks per green, playing times,
  slot length and players per rink, then creates everything

---

## 8. Phases

### Phase 1: foundation (≈ 1 week)
- [ ] New GitHub repository for the Laravel 12 + Filament app, private, with this plan copied in as
  `docs/PLAN.md`. This repository (`bowlsbuddy`) stays as the read-only reference.
- [ ] Migrations creating the tables in 5.1 with the improvements in 5.2.
- [ ] Models, `HasMeta` trait, relationships, seeders (LCE: greens A and B, 6 rinks each, 12:00–17:00,
  60-minute slots, 2 players).
- [ ] Auth on `bs_users` (email + `pw`, status checks). CI with Pint, PHPStan and Pest.
- [ ] Test deploy of a "hello world" build to **InfinityFree** (section 9.1): zip upload, `public/` into
  `htdocs/`, database sessions, uploads without a storage symlink, and the maintenance page running
  migrations.

**Done when:** CI is green, and the seeded app runs on InfinityFree.

### Phase 2: booking rules (≈ 1–2 weeks)
- [ ] Reference values captured from the current app (5.3).
- [ ] `BookingRules`, `GreenService` and greens-overview query, each with Pest tests, including the
  concurrent-booking test.

**Done when:** all rule tests pass and match the reference values.

### Phase 3: member pages (≈ 2 weeks)
- [ ] Layout and CSS carried over (`public/css`, `public/css-client/default.css`).
- [ ] Every "Members" item in section 7.

**Done when:** Playwright tests pass for the booking flow at phone (390 px) and desktop (1200 px) widths.

### Phase 4: Secretary, admin and setup (≈ 1 week)
- [ ] Filament panel with access limited by privileges (`admin.see-menu` to enter, then per resource).
- [ ] Filament resources: **Members** (with "activate" and "set temporary password" actions), **Bookings**,
  **Events** (rink / Green A / Green B / all rinks selector), **Rinks**.
- [ ] Filament settings pages: names and text, info and help pages, behaviour, terms and privacy uploads.
- [ ] Green open/close, WhatsApp invite and day sheet on the greens page (custom, as today).
- [ ] `club:create` command (for paid hosting), plus a **first-run setup page** asking the same questions,
  shown only while there are no users (for InfinityFree, which has no command line).
- [ ] Every "Secretary / admin" and "New club setup" item in section 7.

**Done when:** a new club can be created with one command, and the Secretary's whole day (close a green, add
an event, print the day sheet, reset a password) passes as a browser test.

### Phase 5: launch at LCE (≈ 1 week, plus a short trial)
- [ ] Security check: go through section 6 point by point and run `composer audit`.
- [ ] Deploy to InfinityFree with the zip build; set up LCE on the first-run page; upload Business Terms and
  Privacy Policy.
- [ ] Trial with the Secretary and a few members for 1–2 weeks, then invite everyone (WhatsApp invite
  button).
- [ ] Backups: the Secretary or you download a backup from the admin panel each week (InfinityFree has no
  scheduled jobs), and one restore is tested on a local copy.
- [ ] Archive the `bowlsbuddy` repository on GitHub (read-only). It stays available as the reference and for
  its history.

**Total: roughly 5–8 weeks part-time.**

---

## 9. Hosting and more clubs

**Decided: InfinityFree until the first club signs up**, then move to paid hosting before that club goes live.

### 9.1 Working within InfinityFree's limits

| InfinityFree limit | How the rebuild handles it |
|---|---|
| No SSH, Composer or command line; files go up through the browser File Manager | A build script (GitHub Actions or local) makes a ready-to-upload zip with `vendor/` and built assets. Contents of `public/` go into `htdocs/`, everything else next to it, as with the current deploy. |
| Can't run `php artisan migrate` | Admin-only **Maintenance** page in Filament (`admin.config` + confirmation) runs pending migrations and clears caches. |
| Can't run `club:create` | First-run setup page, shown only while there are no users. |
| No cron / scheduled jobs; automated requests are blocked by its bot protection | Nothing depends on a schedule: caches clear when data changes. Backups are a **Download backup** button (SQL dump) in the admin panel, done weekly. |
| `symlink()` may be unavailable | Uploaded PDFs are served through a route instead of the `storage:link` symlink. |
| `mail()` disabled | Not needed; the app sends no email. |
| No remote database access | Database changes only through migrations (Maintenance page) or phpMyAdmin. |
| No uptime guarantee, resource limits | Acceptable for LCE while it's free; the reason to move before charging anyone. |

Laravel is set up for this from Phase 1, so moving hosts later needs no code changes.

### 9.2 When the first club signs up

- Move to paid PHP hosting with SSH, automatic daily backups, PHP 8.3 and MySQL 8, or a small VPS with
  Laravel Forge or Ploi. Budget roughly R100–R300 per month; add it to that club's price.
- Move LCE over at the same time: download a backup, import it on the new host, upload the same build, and
  point the address at it. About an hour, with no code changes.
- Switch backups to the host's automatic daily backups, and use `club:create` and SSH deploys from then on.

### 9.3 More clubs

- **One install per club:** its own database and subdomain, all running the same code and updated together
  by a deploy script.
- **More than ~10 clubs:** consider one shared install with a `club_id` on each table. That is a
  data-structure change, so it is left out of this plan on purpose.

---

## 10. Quality gates

- **Pest** tests for every rule in 5.3 and every policy; over 80% coverage of `app/Services`.
- **Playwright** browser tests for the member booking flow and the Secretary's day.
- **CI must pass before merging:** Pint, PHPStan level 6+, Pest, `composer audit`.

---

## 11. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| A rule behaves slightly differently from the current app | Medium | Reference values from the current app (5.3) used as test expectations |
| Shared hosting blocks something Laravel needs | Medium | Test deploy in Phase 1, before any feature work |
| The rebuild takes longer than planned | Medium | Phases end in something that runs; features the club doesn't need stay out |
| A Filament or Livewire major upgrade changes things | Low | Pin major versions in `composer.json`; upgrade deliberately with the test suite |
| The first club wants a dropped feature (e.g. payments) | Low | The old schema and code show how it worked; add it as its own phase |

---

## 12. Decisions needed

1. ~~**Framework?**~~ **Decided: Laravel 12 + Filament.**
2. ~~**Repository?**~~ **Decided: a new repository.** The current `bowlsbuddy` repository is kept unchanged as
   the reference, and archived once the rebuild is live.
3. ~~**Hosting?**~~ **Decided: InfinityFree** until the first club signs up, then paid hosting (section 9).
4. **Dropped features:** confirm pricing, products, coupons, bills, emails and repeating bookings can stay
   out.
5. ~~**The current app:** keep it only as a reference, or launch it at LCE?~~ **Decided: reference only.** It
   won't be launched or fixed; LCE's first live system will be the rebuild.
