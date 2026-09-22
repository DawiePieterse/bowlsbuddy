# Bowls Club Practice Booking System

**Based on ep-3 Bookingsystem** (Open Source – MIT License)

A simple, self-hosted online booking system for bowls club practice sessions.
Designed to replace the traditional paper practice sheet.

---

## Overview

This system allows club members to book rinks (greens) online for practice sessions.

**Club size:** 106 members

### Official Club Rules

| Rule | Details |
|------|---------|
| **Practice Days** | Monday, Wednesday and Friday only |
| **Booking Window** | Up to 2 weeks in advance |
| **Availability** | Live right up until just before the session starts |
| **Greens Availability** | Sometimes both greens available, **mostly only one**. Decided by the secretary |
| **Who books** | One person books the slot (they may include additional players) |
| **Limit per Person** | Maximum **one slot per day per person** |
| **Communication** | Club uses a WhatsApp group alongside the system |

---

## Features

### For Members
- Clear calendar view of all rinks and time slots
- One person books a slot (can include additional players)
- Maximum one slot per person per day (monitored)
- View personal bookings ("My Bookings")
- **Share via WhatsApp** link after booking
- Cancel own bookings (within allowed time window)
- Mobile-friendly responsive design

### For Administrators / Managers (Secretary)
- Easy management of rinks (add, rename, enable/disable)
- Control which green(s) are available each day
- Set capacity, opening hours, and time blocks per rink
- Create events to close greens or restrict days
- Control booking window (2 weeks)
- View and manage all bookings
- Assistant accounts with limited privileges

### Technical
- Fully open source (MIT License)
- Self-hosted (you control the data)
- PHP + MySQL
- No monthly fees

---

## Recommended Hosting

### Primary Recommendation: **InfinityFree**

| Reason | Details |
|--------|---------|
| Most proven free PHP + MySQL host | Longest-running and most widely used |
| PHP 8.3 + MySQL support | Fully compatible with ep-3 |
| 5 GB storage + free SSL | More than enough for 106 members |
| Custom domain support | Can later use a real club domain |
| No ads on the site | Clean experience for members |
| Good community support | Large forum for help |

**Alternatives:**
- **Byet.host** — Very similar infrastructure (good backup)
- **TinkerHost** — Solid alternative interface

**Traffic note:** The free tier limit (~50,000 hits per day) is more than sufficient for a bowls club of this size.

---

## System Requirements

- PHP 8.1 or higher (8.3 recommended)
- MySQL 5.7+ / MariaDB 10.3+
- Apache with `mod_rewrite` (or Nginx equivalent)

---

## Installation (Quick Guide)

### On InfinityFree (Recommended)
1. Create a free account at [infinityfree.com](https://www.infinityfree.com)
2. Create a MySQL database
3. Upload all ep-3 files (document root must point to the `/public` folder)
4. Rename config files and enter database credentials
5. Set write permissions on the required folders
6. Run the setup tool in the browser
7. Delete `public/setup.php` after successful setup
8. Log in as admin and configure the rinks

Detailed instructions: See [INSTALL.md](INSTALL.md) in this repository (from the upstream ep-3 project).

---

## Initial Configuration for This Club

### Recommended Square (Rink) Setup

Create 6 squares with these settings:

| Setting                    | Value          | Reason |
|---------------------------|----------------|--------|
| Capacity                  | Set as needed  | Flexible number of players per rink |
| time_start                | 12:00          | No play before 12:00 |
| time_end                  | 17:00          | Typical end time |
| time_block                | 60 minutes     | 1-hour slots |
| range_book                | 14 days        | 2 weeks in advance |
| max_active_bookings       | Monitored      | Support for one slot per day per person |

**Secretary controls green availability** by:
- Enabling / disabling individual rinks, **or**
- Creating Events that block one or both greens on specific days

---

## Admin Guide – Common Tasks

| Task | How to do it | Difficulty |
|------|--------------|----------|
| Open only one green for a day | Disable one green or create an Event | Easy |
| Open both greens | Enable both greens | Very Easy |
| Change player capacity | Edit square → Capacity | Very Easy |
| Set 2-week booking window | Edit square → range_book = 14 | Easy |
| View who has booked | Calendar (admin view) | Easy |
| Enforce one slot per person per day | Monitor via admin view | Medium |

---

## Member Booking Process

1. Member logs in
2. Sees available rinks and time slots (only Mon / Wed / Fri)
3. Clicks a free slot
4. Books the slot (can include additional players)
5. Confirms the booking
6. Booking appears immediately on the calendar and in "My Bookings"
7. **Share via WhatsApp** button is available to post the booking details to the club group

**Important:**
Only one person needs to book the slot. That person can include additional playing partners.

### Share via WhatsApp

After a successful booking, a simple "Share via WhatsApp" link/button can be shown.
This opens WhatsApp with a pre-filled message that the member (or secretary) can send to the club group.

---

## Database Overview (Simplified)

| Table              | Purpose                          |
|--------------------|----------------------------------|
| `bs_users`         | Members and admins               |
| `bs_squares`       | Rinks / Greens                   |
| `bs_bookings`      | Booking header records           |
| `bs_reservations`  | Actual date + time slots         |
| `bs_events`        | Closures and green availability  |
| `bs_*_meta`        | Flexible extra data              |
| `bs_options`       | System settings and texts        |

---

## Limitations & Notes

- Strict "one slot per calendar day per person" is not fully automatic — requires light admin monitoring.
- The system works alongside the existing WhatsApp group.
- Full automatic WhatsApp sending is not included (to keep the system free and simple).

---

## Credits & License

- Original software: **ep-3 Bookingsystem** by Tobias Krebs
- License: MIT
- Official site: https://bs.hbsys.de
- GitHub: https://github.com/tkrebs/ep3-bs

This repository vendors the ep-3 Bookingsystem source (see [UPSTREAM_README.md](UPSTREAM_README.md),
[INSTALL.md](INSTALL.md) and [UPDATE.md](UPDATE.md) for the original project's documentation) and adds
club-specific configuration notes above. No code has been forked/modified yet — configuration for this
club (rinks, hours, booking window, WhatsApp sharing) is done through the app's admin UI after install,
per the guide above.

---

**Last updated:** September 2026
**Club size:** 106 members
**Recommended hosting:** InfinityFree
**Key clarification:** One person books the slot (may include additional players). Maximum one slot per person per day.
**WhatsApp:** Share via WhatsApp link available after booking.
