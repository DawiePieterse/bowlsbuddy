# Bowls Buddy migration plan

**Goal:** move Bowls Buddy from its 2010s code base (Zend Framework 2, jQuery 1.x) to a modern, supported
PHP stack. The new app keeps working on **the same MySQL tables and data**, while making it faster and safer
and easier to run for more than one club.

**Status:** proposal. No code has changed yet.

---

## 1. Summary

| | Today | After migration |
|---|---|---|
| Framework | Zend Framework 2, abandoned since 2019 and patched by hand in `src/Zend/` | Laravel 12 on PHP 8.3+, with security fixes and long-term support |
| Front end | jQuery 1.12.4, TinyMCE 4, server-rendered pages | Blade templates + Livewire/Alpine.js, no build step needed on the server |
| Database | `bs_*` tables (MySQL/MariaDB, `utf8`) | **Same `bs_*` tables**, plus extra indexes and a `utf8mb4` conversion |
| Security | CSRF on 2 forms only, deletes via GET links, bcrypt cost 6 | CSRF on every form, deletes only via POST, bcrypt cost 12, rate-limited login |
| Hosting | InfinityFree (free, FTP only, no SSH/cron/mail) | Works on the same kind of host; paid PHP hosting recommended for paying clubs |
| Tests | None | Feature tests for every booking rule, run automatically on each push |

**Approach:** a **side-by-side rebuild**. The new Laravel app reads and writes the existing tables, so old and
new versions can run against the same database while we check the new one. We switch over when the new app
does everything the club uses, and we can switch back instantly if something goes wrong.

---

## 2. Why migrate: what the current code base looks like

Found while reviewing the repository:

### Security
1. **Most forms have no CSRF protection.** Only the green open/close form
   (`module/Frontend/src/Frontend/Controller/IndexController.php`) and registration
   (`module/User/src/User/Form/RegistrationForm.php`) carry a token. Login, booking, cancelling and every admin form
   can be submitted from another website while the Secretary is logged in.
2. **Deletes happen through plain links.** For example `backend/user/delete/:uid?confirmed=true`
   (`module/Backend/view/backend/user/delete.phtml`) and the same pattern for bookings, events and rinks. A link
   in a WhatsApp message could delete a member if the Secretary taps it while logged in.
3. **Weak password hashing settings.** Bcrypt cost is 6 (`UserSessionManager.php`), well below today's
   recommended 12, and there is still a fallback for old MD5 passwords (`legacy-pw` meta).
4. **Unmaintained libraries.** Zend Framework 2 gets no security fixes; the framework code is vendored and
   hand-patched in `src/Zend/` to run on PHP 8. jQuery 1.12.4 has known XSS issues and TinyMCE 4 is end of life.
5. **PHP `unserialize()` on stored data.** Player names are stored as PHP-serialized strings in
   `bs_bookings_meta.player-names` and read back with `@unserialize()` in four places. They are written by the
   server so the risk is low, but JSON is the safe format.
6. **Weak booking confirmation token.** `sha1('Quick and dirty' . floor(time() / 1800))` in
   `Square/Controller/BookingController.php` is the same for every user and predictable.
7. **Debug mode is easy to leave on.** `EP3_BS_DEV_TAG = true` shows full error messages, including file paths,
   to visitors.

### Correctness
8. **Wrong time zone.** `config/init.php` sets `Europe/Berlin`. South Africa is UTC+2 all year, while Berlin is
   UTC+1 in winter, so for part of the year "is this slot in the past?" and cancel cut-offs are an hour off.
9. **Double-booking race.** Availability is checked in `SquareValidator` *before* the booking transaction in
   `BookingService` starts, with no row lock. Two members tapping the same free slot at the same moment can both
   succeed. This is rare at club scale, but possible.
10. **`utf8` (3-byte) tables.** Names with emoji or some special characters can't be saved.

### Efficiency and maintainability
11. **Settings stored as key/value rows.** Every `*_meta` table and `bs_options` stores values as `text`, with
    single-column indexes only. Pages load entities and then fetch their meta in a second pass
    (`getByReservations`, `getByBookings`). That's fine for one club, but the query count grows with every
    booking shown.
12. **Busy pages recompute everything.** The greens overview (`IndexController::greensOverview`) loads 14 days
    of reservations and events on every request, with no caching.
13. **Hard to change safely.** About 28,000 lines across 290 PHP and 83 template files, CRLF line endings, no
    automated tests, and hand-built factories for every service.

---

## 3. Goals and non-goals

**Goals**
- Keep **all existing data and table names**, so the new app can be switched on and off against the live
  database.
- Match every feature the club uses today (see section 7) before switching over.
- Fix the security and correctness issues above.
- Make adding a new club a scripted 10-minute job (one install per club at first; see section 9).
- Keep it runnable on cheap shared PHP hosting, with no Node.js, Docker or background workers needed in
  production.

**Non-goals (for this migration)**
- Redesigning the look of the site. The current pages and CSS are reused as closely as possible.
- Online payments, pricing, products, coupons and bills. These features exist in the old code and tables but
  are switched off for LCE. The tables stay untouched so they could be added back later.
- Emails. The club has chosen not to send any.

---

## 4. Target stack and why

| Option | Verdict |
|---|---|
| **Laravel 12 (recommended)** | Runs on shared PHP hosting by uploading `vendor/`. It has CSRF, auth, rate limiting, validation, database migrations and testing built in, and a huge community. Eloquent models can point at existing tables and primary keys (`bs_bookings`, `bid`), so no data has to move. |
| Laminas MVC (ZF2's official successor) | The fastest port: a migration tool renames `Zend\` to `Laminas\`. But it keeps the same dated architecture, factories and lack of tests, and the ecosystem is shrinking. Worth it only as a stop-gap. |
| Symfony | Technically excellent, but more setup and configuration than this app needs. |
| Rewrite in JavaScript (Next.js etc.) | Won't run on PHP-only hosts, and would push us to change the data structure. Rejected. |

**Pieces:**
- **PHP 8.3+**, **Laravel 12**, **Eloquent** models on the `bs_*` tables.
- **Blade + Livewire 3** for the interactive parts (booking pop-up, calendar paging) and **Alpine.js** for
  small bits. Assets are built once with Vite on a developer machine or in CI and committed as static files,
  so the server needs nothing but PHP.
- **Pest** for tests, **Laravel Pint** for code style, **PHPStan (Larastan)** for static checks, and
  **GitHub Actions** to run them on every push.
- **Sessions and cache** in the database (`sessions` and `cache` tables), because shared hosts often wipe file
  storage.
- **QR code:** keep the current client-side library, or use `chillerlan/php-qrcode` server-side.

---

## 5. Working with the existing data

### 5.1 Table to model mapping (names and keys unchanged)

| Table | Model | Primary key | Notes |
|---|---|---|---|
| `bs_users` | `User` | `uid` | `status` covers role and state (`enabled`, `disabled`, `admin`, `assist`...). `pw` column read by the auth guard. |
| `bs_users_meta` | `UserMeta` | `umid` | `firstname`, `lastname`, `locale`, `allow.*` privileges... |
| `bs_squares` | `Rink` | `sid` | Name prefix gives the green (`A-1` means green A), as in `GreenManager` today. |
| `bs_squares_meta` | `RinkMeta` | `smid` | `capacity-ask-names`, `private_names`, info and rule texts, `locale`-aware. |
| `bs_bookings` | `Booking` | `bid` | `status`: `single`, `subscription`, `cancelled`. |
| `bs_bookings_meta` | `BookingMeta` | `bmid` | `player-names` (PHP-serialized today, see 5.4). |
| `bs_reservations` | `Reservation` | `rid` | One row per date and time range of a booking. |
| `bs_reservations_meta` | `ReservationMeta` | `rmid` | |
| `bs_events` | `Event` | `eid` | `sid` NULL means all rinks, or a whole green via meta `green`. |
| `bs_events_meta` | `EventMeta` | `emid` | `name`, `description`, `green`, `locale`-aware. |
| `bs_options` | `Option` | `oid` | Site settings incl. `service.greens.closed`; `locale`-aware. |
| `bs_squares_pricing`, `_products`, `_coupons`, `bs_bookings_bills` | *not mapped at first* | | Left untouched. |

The meta tables get a small reusable **`HasMeta` trait**, so code reads `$booking->meta('player-names')` and
all meta for a page is loaded in **one query** with eager loading (`Booking::with('meta')`).

The key/value meta tables stay as they are. They work, and changing them would break the side-by-side switch.
Once the old app is retired, frequently read meta such as user first name and last name *may* move into real
columns; that is a separate, optional step.

### 5.2 Additive database changes only

These don't break the old app, so they can be applied before the new one goes live (each as a Laravel
migration with a `down()` method):

- **Composite indexes** for the queries the app actually runs:
  - `bs_reservations (date, time_start)`
  - `bs_bookings (sid, status)`, `bs_bookings (uid, status)`
  - `bs_bookings_meta (bid, key)`, `bs_users_meta (uid, key)`, `bs_squares_meta (sid, key)`, `bs_events_meta (eid, key)`
  - `bs_options (key, locale)`
  - `bs_events (datetime_start, datetime_end)`
- **Charset:** convert all `bs_*` tables to `utf8mb4` / `utf8mb4_unicode_ci`. This must be tested on a copy
  first; `varchar(256)` index lengths are fine.
- **New tables** Laravel needs, all prefixed so they can't clash: `bb_sessions`, `bb_cache`, `bb_jobs`
  (optional), `bb_migrations`, `bb_password_reset_tokens` (unused while there's no email).
- **Do not** rename, drop or change types of existing columns while the old app can still run.

### 5.3 Passwords: no reset needed

ZF2's `Bcrypt` writes standard `$2y$` hashes, which PHP's `password_verify()` (and Laravel's `Hash::check`)
reads directly. On each successful login the new app calls `Hash::needsRehash()` and re-saves the hash at
cost 12. Members won't notice anything. The MD5 `legacy-pw` fallback is dropped after a check that no user
still has it.

### 5.4 Player names: PHP-serialized to JSON

- **Read** both formats: if the value starts with `a:` use `unserialize($value, ['allowed_classes' => false])`,
  otherwise `json_decode`.
- **Write** JSON only in the new app.
- After switch-over, a one-off command converts the remaining rows to JSON.

### 5.5 Business rules to port exactly (with tests)

These live in `Square/Service/SquareValidator.php`, `Square/Manager/GreenManager.php`,
`Frontend/Controller/IndexController.php` and the `Booking` services. Each gets a test first, written against
the current behaviour:

1. A slot is bookable only between `time_start` and `time_end`, in `time_block` steps, and not in the past.
2. `capacity` players per rink (2 at LCE). `capacity_heterogenic = 0` means one booking per slot.
3. **One rink per member per day.** Staff with booking rights are exempt.
4. **Closed greens.** The `service.greens.closed` option holds `YYYY-MM-DD:A` lines, and a closed green blocks
   all its rinks.
5. **Events block rinks.** A single rink (`sid`), a green (meta `green`) or all rinks (`sid` NULL, no green).
6. Hidden days: `service.calendar.day-exceptions`, by weekday name or date.
7. Booking range (`range_book` days ahead), lead time (`min_range_book`), cancel cut-off (`range_cancel`).
8. Greens overview: next 14 playing days, with free slots and event names per green.
9. The day sheet grid: rinks by hour, names, events, closed greens.
10. Privileges: `admin.user`, `admin.booking`, `admin.event`, `admin.config`, `admin.see-menu`,
    `calendar.*` (from `User::$privileges` and meta `allow.*`).

**Double-booking fix:** create the booking inside a transaction that first runs
`SELECT … FOR UPDATE` on the rink's reservations for that date, then checks the rules again, then inserts.

---

## 6. Security and efficiency changes (built in from day one)

**Security**
- CSRF tokens on every form (Laravel default). All state-changing actions (book, cancel, delete, open/close
  green) are POST, PUT or DELETE only.
- **Policies** for every admin action, mapped to the existing privileges.
- Login **rate limiting** per email and per IP (replaces `login_attempts` / `login_detent`, which can keep being
  written for the old app).
- Bcrypt cost 12 with rehash on login. Session ID regenerated at login. Secure, HttpOnly, SameSite=Lax cookies.
  HTTPS forced.
- Security headers: `Content-Security-Policy`, `X-Frame-Options: DENY`, `Referrer-Policy`,
  `Permissions-Policy`.
- Blade escapes output by default. Info and help pages edited in TinyMCE are cleaned with an HTML purifier
  before saving.
- `APP_DEBUG=false` in production, errors to a log file, and a friendly error page.
- `composer audit` and GitHub Dependabot to flag vulnerable packages.
- **POPIA:** only first name, surname and email are stored. Add an "export my data" button and keep the
  existing "delete my account".

**Efficiency**
- Eager-load meta and users (one query per table per page, not one per booking).
- Cache the options (`bs_options`) for 10 minutes, cleared whenever a setting is saved.
- Cache the greens overview per day, cleared whenever a booking, event or closure changes.
- Composite indexes from 5.2.
- Asset versioning and long browser caching. jQuery and jQuery UI are no longer loaded.
- Target: every page renders in under 150 ms server time on shared hosting, using under 15 queries.

---

## 7. Feature parity checklist

These are the features in use at LCE, all of which must work before switch-over.

**Members**
- [ ] Registration (first name, surname, email, password, terms and privacy acceptance, anti-bot delay)
- [ ] Log in / log out; "forgot password" page pointing to the Secretary
- [ ] Greens overview: 14 playing days, free slots, closed (red), events (purple)
- [ ] Green calendar: rinks by hourly slots, player names for logged-in users, own bookings in green
- [ ] Book a slot: 1–2 players, partner's name, rules acceptance, one-rink-per-day message
- [ ] WhatsApp share after booking and from the booking pop-up
- [ ] Cancel own booking within the cut-off
- [ ] My bookings; My account (change email or password, delete account)
- [ ] Info page, help page, Business Terms and Privacy Policy PDFs

**Secretary / admin**
- [ ] Open or close a green per day
- [ ] Invite members via WhatsApp
- [ ] Printable day sheet with QR code
- [ ] Users: search, create, edit, activate, set a temporary password, privileges
- [ ] Bookings: list, create for a member, edit, cancel, delete
- [ ] Events: create for a rink, a green or all rinks; list, edit, delete
- [ ] Configuration: names and text, info and help pages, rinks, behaviour, terms and privacy uploads
- [ ] Setup wizard for a new club (replaces `module/Setup`)

**Dropped or deferred:** pricing, products, coupons, bills, email notifications, subscription (repeating)
bookings. The tables are kept; we'll decide later if a paying club needs them.

---

## 8. Phased plan

Effort figures are rough, for one developer working part-time with AI assistance.

### Phase 0: quick fixes in the current app (≈ 1–2 days, do now)
Protects the live site while the rebuild happens.
- [ ] Set the time zone to `Africa/Johannesburg`.
- [ ] Make delete links POST forms with a CSRF token (users, bookings, events, rinks).
- [ ] Raise bcrypt cost to 12.
- [ ] Deploy with `EP3_BS_DEV_TAG = false`.
- [ ] Start weekly database backups (phpMyAdmin export, or a scripted dump if the host allows it).

**Done when:** the fixes are deployed and a backup has been restored successfully to a test database.

### Phase 1: foundation (≈ 1 week)
- [ ] New Laravel 12 app in `/next` of this repo (or a new repo), with CI running Pint, PHPStan and Pest.
- [ ] Models, `HasMeta` trait and relationships for the tables in 5.1.
- [ ] Additive migrations from 5.2, tested on a copy of the live database.
- [ ] Auth guard on `bs_users` (email + `pw`, status checks, rehash on login).
- [ ] Test fixtures built from an anonymised copy of the live data.

**Done when:** a test logs in an existing member with their current password and lists their bookings.

### Phase 2: booking rules (≈ 1–2 weeks)
- [ ] `BookingRules` service porting 5.5, one Pest test per rule, including the race-condition test.
- [ ] `GreenService` (greens, closures, event coverage) and the greens overview query.
- [ ] Player-names reader and writer (serialized and JSON).

**Done when:** all rule tests pass, and the greens overview shows the same numbers for the same data as the old
app (checked with a script comparing both outputs for the next 14 days).

### Phase 3: member pages (≈ 2 weeks)
- [ ] Layout and CSS carried over (`public/css`, `public/css-client/default.css`), same look on phone and
  desktop.
- [ ] Greens overview, green calendar, booking pop-up (Livewire), WhatsApp share, cancel, my bookings, my
  account, registration, info, help and legal pages.

**Done when:** every "Members" item in section 7 is ticked, checked by Playwright browser tests at phone and
desktop sizes.

### Phase 4: Secretary and admin (≈ 2 weeks)
- [ ] Green open/close, WhatsApp invite, day sheet.
- [ ] Users, bookings, events, configuration screens with policies.
- [ ] Setup wizard and an `artisan club:create` command for new clubs.

**Done when:** every "Secretary / admin" item in section 7 is ticked.

### Phase 5: side-by-side trial (≈ 2–4 weeks, mostly waiting)
- [ ] Deploy the new app to a test subdomain (e.g. `next.` or `beta.`) pointing at a **copy** of the live
  database, and have the Secretary and 2–3 members try it.
- [ ] Then point it at the **live** database while the old app stays the main site. Both apps read and write
  the same tables.
- [ ] Fix issues; compare day sheets from both apps daily.

**Done when:** two weeks with no data differences and no open bugs rated high.

### Phase 6: switch-over (≈ 1 day) and clean-up
- [ ] Take a backup, point the main address at the new app, keep the old app on a hidden URL for 30 days.
- [ ] **Rollback:** point the address back. The data is shared, so nothing is lost.
- [ ] After 30 days: remove the old app; convert player names to JSON; drop the MD5 fallback; optionally move
  first name and surname into real columns.

**Total:** roughly **8–12 weeks** of part-time work, plus the trial period.

---

## 9. Hosting and running more than one club

- **For LCE alone:** the new app runs on InfinityFree like today (upload a zip). Laravel's scheduler isn't
  needed. Cache clearing happens on writes, not on a timer.
- **For paying clubs:** move to paid South African or EU PHP hosting with SSH, daily backups, PHP 8.3 and
  MySQL 8, or a small VPS with Laravel Forge or Ploi. Budget roughly R100–R300 per month.
- **One install per club** at first: its own database and subdomain, all running the same code. The
  `club:create` command sets up the database, admin user, greens and rinks from a few questions.
- **Later, if there are more than ~10 clubs:** consider one shared install with a `club_id` on each table
  (multi-tenant). That *is* a data-structure change, so it is deliberately left out of this plan.

---

## 10. Testing and quality gates

- **Unit and feature tests (Pest)** for every rule in 5.5 and every policy. Target: over 80% coverage of
  `app/Services`.
- **Browser tests (Playwright)** for the member booking flow and the Secretary's day, at 390 px and 1200 px wide.
- **Data comparison script** (Phase 5): for each of the next 14 days, compare the old and new apps' greens
  counts and day sheets.
- **CI must pass before merge:** Pint, PHPStan level 6+, Pest, `composer audit`.

---

## 11. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| A booking rule behaves slightly differently | Medium | Write tests against the *old* behaviour first; daily comparison during the trial |
| `utf8mb4` conversion fails on the host | Low | Test on a copy; the conversion is optional for switch-over |
| Shared host blocks something Laravel needs (e.g. `proc_open`, symlinks) | Medium | Check in Phase 1 with a "hello world" deploy; set `storage` paths without symlinks |
| Both apps writing at once causes confusion | Low | Keep the trial short; the old app is the main site until switch-over |
| The work takes longer than planned | Medium | Phase 0 makes the current app safe enough to keep running meanwhile |

---

## 12. Decisions needed

1. **Laravel (recommended) or Laminas** as a quicker stop-gap?
2. **Same repository** (`/next` folder) or a new repository?
3. **Hosting:** stay on InfinityFree for LCE, or move now to paid hosting ahead of selling to other clubs?
4. **Dropped features:** confirm pricing, products, coupons, bills, emails and repeating bookings can be left
   out.
5. **Phase 0:** go ahead with the quick fixes on the live site now?
