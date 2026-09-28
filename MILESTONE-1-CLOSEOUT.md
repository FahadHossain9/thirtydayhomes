# Project state — read this first

**Purpose of this file:** so anyone picking this project up — a new
developer, or a new AI session with no memory of the work — can see
exactly how far it has got, what is half-finished, and what the rules
are, without being re-briefed.

**Keep it current.** When a piece of work lands, move it in this file.
A stale tracker is worse than none, because it is believed.

Last updated: 16 September 2026.

**Milestone 2 is open.** Rob gave the go-ahead in writing on 15 September
2026 ("should be good to get milestone 2 started"). The Milestone 2 plan,
task order and hand-over format live in `MILESTONE-2-PLAN.md`; this file
stays the record of rules, architecture, environment and Milestone 1.

---

## 0. Rules — read these first, follow them without being reminded

**These are not preferences. They have each been asked for more than
once, which is a failure of this file, not of the person asking.**

1. **Do only what was asked.** Nothing extra, no bonus improvements, no
   "while I was in there". Anything else that turns out to be needed
   will be requested later.
2. **One thing at a time.** Finish it, hand it over, stop. Do not start
   the next item because it seems obvious.
3. **Wait for the words "100% OK".** No commit before it, and no new
   task before it. A screenshot that looks right is not the signal.
4. **Never `git push`.** Pushing deploys to the live client site. The
   push is the user's decision, always.
5. **Current milestone only.** Milestone 2 from 17 September 2026
   (scope: `MILESTONE-2-PLAN.md` §1). Anything outside it is written into
   the requests register there (§6), never into the code. Milestone 3
   waits for written acceptance and payment of Milestone 2.
6. **Verify on localhost, not live.** New work is not on the live site
   until the user pushes.
7. **Anything the client will read must be short.** No reasoning, no
   justification, no test counts, no internal detail. What was done,
   how to check it, what is not included. Nothing else.
8. **Keep replies brief.** Report the result and stop.
9. Mirror every changed file to `New folder\thirtydayhomes`, never
   `.github/`.
10. Scope every CSS selector to its own section; child combinators, no
    bare `span` / `small` / `b` descendants.

---

## 0b. The three standards every piece of work must meet

> The current, fuller wording — including the pillar plan written before
> any code — is `AGENTS.md` §4. This section is kept as the original.

**Not optional, and not only when asked.** Every task on this project is
checked against all three before it is handed over. The client's group
raised these after the first review, and they now apply to every feature
rather than being re-requested each time.

### A. User journey

A page that technically works is not finished. Build every feature as a
whole journey: before login, after login, during the action, after
success, and when it fails.

**Check every screen in order:**

| | Question |
|---|---|
| Entry | How did this role arrive here? |
| Orientation | Can they tell where they are and what state the record is in? |
| Primary action | Is the most likely next step obvious? |
| Completion | After saving, is the outcome clear? |
| Continuation | Does the next useful place happen automatically? |
| Recovery | On failure: what changed, what did not, what now? |
| Mobile & keyboard | Does it work without a wide screen or a mouse? |
| Role boundary | Does the user stay in the portal rather than wp-admin? |

### B. Corner cases

Design the unhappy paths at the same time as the happy one, never after.
For every feature, work through:

- **Empty** — no records yet, and a first-run state that says what to do
- **One** — singular wording, no "1 photos"
- **Many** — long lists, pagination, long names, overflow
- **Zero results** — a search that matches nothing must say so, never
  fall back to showing everything
- **Too large / too many** — over a limit, and the limit stated plainly
- **Wrong type or wrong owner** — refused identically, so nothing can be
  probed
- **Lapsed / expired** — membership ends mid-task
- **Partial success** — three saved, one refused: keep the three and
  name the one
- **Double submit** — the button disables; a second press cannot repeat
  the action
- **Back button and resubmit** — restored form state must not delete or
  duplicate anything
- **Slow and offline** — say what is happening while it happens
- **Session lost** — expired nonce or login, with the work not thrown away

### C. Advanced-level design

The standard is a polished, premium marketplace, not "functionally
complete". Every screen carries:

- Consistent spacing, type scale, and button hierarchy from the tokens —
  never ad-hoc values
- Designed **empty, loading, success, error, disabled and pending**
  states, not just the default one
- Visible keyboard focus, sensible tab order, real labels, and touch
  targets that can be hit
- In-product confirmation for destructive actions, not browser dialogs
- Human wording — never a stored slug or status key on screen
- Motion used sparingly, and honoured `prefers-reduced-motion`
- No unstyled output, shortcode artefacts, or layout shift
- Works at desktop, tablet and phone widths, with long content and
  zoomed text

### The seven mistakes this came from

The UX review of 9 September 2026 named these. They are the concrete
form of the three standards above.

1. **A working page can still add a pointless step.** A signed-in user
   who lands on the login page should be taken to their dashboard, not
   shown a "Go to dashboard" button. If there is only one useful next
   destination, go there.
2. **Showing a status is not managing it.** Whatever state is displayed,
   put the action for changing it in the same place — a membership panel
   needs a cancel/manage control, not just a renewal date.
3. **Links into wp-admin break the product.** Daily work stays in the
   branded portal. WordPress is the platform, not the interface.
   (The portal's "WordPress dashboard" link was removed for this reason.)
4. **Responsive is interaction, not just width.** Open/closed behaviour,
   remembered state, keyboard, Escape, and accessible labels all count.
5. **Never show stored values to customers.** Plan slugs and status keys
   need a human label.
6. **Success needs its own screen.** A submitted form should not come
   back as the same form with a small notice attached.
7. **A save can change lifecycle state.** Editing a live listing must not
   silently push it back into review.

**Before handing anything over, walk it as each role that touches it:**
logged-out visitor, new landlord, active landlord, past-due landlord,
staff, administrator.

---

## 1. What this project is

ThirtyDayHomes — a paid-membership marketplace for furnished rentals of
30 days or more. Landlords pay a subscription for a listing allowance,
renters browse and enquire, an administrator approves every listing
before it goes public. Launch market Pittsburgh, expanding to other
cities (Cleveland is next).

Built as a **custom WordPress theme + custom plugin + Elementor**. Three
milestones, paid on written acceptance of each. See
`ThirtyDayHomes_WordPress_Developer_Handoff.md` for the contract —
**it is the authority on what belongs in which milestone.** When a
request arrives, check it against that document before building.

---

## 2. Where everything is

| | |
|---|---|
| Repo | `Md-Abu-Bakker-Siddik/thirtydayhomes-main` — **private** |
| Local WordPress | `D:\xampp\htdocs\thirtydayhomes` |
| Working copy | `D:\fahad vi backup\thirtydayhomes-main` |
| Mirror copy | `…\New folder\thirtydayhomes` — every changed file is copied here, **except `.github/`** |
| Live site | thirtydayhomes.com (Hostinger shared hosting) |
| Server paths | clone at `~/repos/thirtydayhomes-main`, WordPress at `~/domains/thirtydayhomes.com/public_html` |
| SSH / DB credentials | Hostinger hPanel. Never in the repo. |

### Running things locally

```
# The full test battery — 406 assertions across 7 suites
plugins\thirtydayhomes-core\tools\verify.bat

# Anything else via WP-CLI
D:\xampp\php\php.exe D:\xampp\wp-cli.phar <command> --path=D:\xampp\htdocs\thirtydayhomes
```

`git` is **not on PATH**. Use GitHub Desktop's bundled copy:
`%LOCALAPPDATA%\GitHubDesktop\app-*\resources\app\git\cmd\git.exe`

Local test accounts exist (one administrator, several landlords). To use
one, set its password yourself rather than hunting for it:

```
wp user list --fields=ID,user_login,user_email,roles
wp user update <login> --user_pass='<something>'
```

Localhost also has the "Viewing as" persona bar, which signs you in as
any role with no password at all — usually faster.

---

## 3. Architecture — what lives where

**The rule:** if deleting the theme would lose data or behaviour, it is
in the wrong place. Business logic belongs to the plugin; the theme is
presentation only.

### Plugin — `plugins/thirtydayhomes-core/includes/`

| File | Owns |
|---|---|
| `class-tdh-post-types.php` | Listing / facility / inquiry types, taxonomies (`tdh_city`, `tdh_neighborhood`, `tdh_property_type`, `tdh_amenity`) |
| `class-tdh-fields.php` | The listing schema — every meta key and its control |
| `class-tdh-accounts.php` | Registration, login, reset, profile, `is_staff()`, wp-admin guards |
| `class-tdh-membership.php` | Plan status, quota, listing counts |
| `class-tdh-visibility.php` | What the public may see (`pre_get_posts`, priority 10) |
| `class-tdh-search.php` | Keyword search on the archive (`pre_get_posts`, priority 20) |
| `class-tdh-listing-form.php` + `-render.php` | The 4-step create-a-listing wizard |
| `class-tdh-moderation.php` | Approve / request changes from the portal |
| `class-tdh-account-render.php` | Landlord portal **and** the marketplace admin portal |
| `class-tdh-render.php` | Shared markup for every public section |
| `class-tdh-user-privacy.php` | Closes username enumeration |
| `setup/class-tdh-site-structure.php` | Seeds pages, menus, property-type vocabulary |
| `setup/class-tdh-page-layouts.php` | Seeds Elementor layouts, with an edit guard |

### Theme — `themes/thirtydayhomes/`

`inc/` holds helpers (`listings.php`, `account.php`, `icons.php`,
`brand.php`…); `style.css` is the single stylesheet, driven by tokens in
`assets/design-tokens.css`. Templates: `archive-tdh_listing.php`,
`single-tdh_listing.php`, `template-parts/listing-card.php`.

### Conventions that matter

- **CSS:** every selector scoped to its section. Child combinators, never
  bare descendant `span`/`small`/`b`/`p`. One section must never restyle
  another.
- **Versions:** bump `TDH_THEME_VERSION` in `functions.php` **and** the
  `Version:` header in `style.css` together, or the browser serves stale
  CSS. Same for the plugin header and its `VERSION` const — the deploy
  workflow fails the build if those two disagree.
- **Writing PHP files:** use the editor tools or
  `[System.IO.File]::WriteAllText` with `UTF8Encoding($false)`. **Never
  PowerShell `Set-Content`** — it adds a BOM, which has broken the live
  site twice.
- **Shell scripts stay LF.** CRLF breaks bash on the server.
- Every feature has a suite in `tools/verify-*.php`, chained from
  `verify.bat`. Add to it; do not leave a feature untested.

---

## 4. How work moves — the agreed process

Every commit eventually has to be re-tested on the live site, so a commit
is not free.

1. **Build** — code, tests green, files mirrored
2. **Reviewer checks localhost** — the developer does not self-certify
3. **Reviewer writes "100% OK"**
4. **Commit** — one approved piece at a time
5. **Reviewer pushes** — in GitHub Desktop, never automatically
6. **Test on live**

**Nothing is committed, and no new task is started, before step 3.**

### Pushing is deploying

`.github/workflows/deploy.yml` runs `on: push: branches: [main]` and
rsyncs to production. **There is no staging environment**, even though
every acceptance criterion in the handoff says "on staging". A push is a
live release.

### After every deploy

- [ ] **LiteSpeed → Purge All** — or visitors keep the old CSS
- [ ] **Tools → Import Demo Content → tick only "Pages and menus"** —
      needed when a change seeds pages or taxonomy terms. **Never** tick
      the sample-listings step on live.
- [ ] Spot-check the changed screens on thirtydayhomes.com

---

## 5. Current state

### Done and live

- Accounts: registration, login, logout, reset, profile
- Stripe subscriptions in test mode; active / past-due / cancelled /
  expired all drive listing visibility correctly
- Landlord portal — real numbers only, no invented figures
- Marketplace administration portal for staff, with approve /
  request-changes, so the client never needs wp-admin for the daily loop
- 4-step listing wizard (this is **M2 work**, built early on instruction)
- Elementor-editable About, How it works, Pricing, Contact, home
- Username enumeration closed: `?rest_route=/wp/v2/users` and `?author=1`
  both 404
- Photo upload — oversized posts now report the real limit instead of
  failing silently

**Comment #8 — photo upload** (two different faults, 1 and 9 Sep 2026)

The first was real: a batch larger than `post_max_size` made PHP discard
the whole request, so `$_POST` and `$_FILES` both arrived empty and the
page re-rendered as though nothing had been submitted. The server limits
were raised and the overflow is now caught and named.

The second was not a fault at all — uploads worked, but nothing on
screen said so, and the owner reasonably read silence as failure:

- Choosing files now draws instant previews in the browser, in a gold
  "Ready to upload" panel that says they are not saved yet
- Continue reads "Uploading…" while they travel
- The review counts them back and confirms how many were added
- Four handlers that used to redirect to step 1 without a word now say
  why, and PHP's own upload errors are named rather than swallowed

A separate "Upload photos" button was built and then removed: once the
preview and the confirmation existed, it was a press that bought
nothing. Worth remembering before adding one back.

**Comment #1 — multi-city** (`40e1022`, verified on live 9 Sep 2026)

The city was typed into the templates and the wizard never asked for it,
so the site could only ever talk about one city. Now: a City field in
wizard step 1, `tdh_listing_city()` / `tdh_listing_location()` in the
theme, and every card, banner and alt text reads the listing's own term.
Copy made city-neutral where a city constrained the site.

Kept deliberately: "Pittsburgh is the first market. The platform is built
to add more." That is the owner's own framing and it scales by saying so.

**To add a second city there is nothing left to build:** create the term
in **Listings → Cities**, set a listing to it, and that listing's card
and page say the new city immediately.

**Comment #3 — the search page** (live and verified on production,
9 Sep 2026)

"Find a home" now opens a working search: a bar above the results that
matches on the home's name, neighborhood, city, property type or ZIP.
`TDH\Search` owns the query — plugin, not theme, so a theme swap cannot
take search with it.

The failure it is built around: an empty `post__in` is **ignored** by
`WP_Query`, so a search that matched nothing would have returned every
home on the site — a wrong answer wearing the costume of a right one. It
falls back to `[0]` instead, and the page says "No homes match …" with a
way back to the full list.

**Scope, deliberately:** only the *keyword* half. Price, bedrooms,
bathrooms, type, pet policy, availability, sorting and the map are M2 by
the handoff, and the hero's date fields belong to that same work. The
archive says so plainly when dates are submitted rather than ignoring
them. A half-wired filter bar is exactly what this file's own docblock
has always warned against.

### Reclassified to M2 — do not build

**Comment #6 — email verification on registration.** Originally read as
M1, because acceptance criterion 1 says "register, **verify**/access the
account". In the reply sent to the owner on 9 Sep 2026 it was proposed
as an M2 addition instead, on the grounds that it was not in the
recorded M1 registration scope. Phone/SMS verification was always M2,
since the SMS gateway is an M2 deliverable.

**Open:** the owner has not yet confirmed this in writing. Until he
does, the reclassification exists only in our sent mail. Chase it — a
sign-off that leaves the word "verify" in criterion 1 unaddressed is a
dispute waiting to happen.

**With #6 moved and #3 done, every Milestone 1 item from the owner's
review is complete.** What remains is not code: the retest checklist,
his confirmation, the designer pass, and written acceptance.

---

## 6. The owner's ten review comments

| # | Comment | Milestone | Status |
|---|---|---|---|
| 1 | Scale beyond Pittsburgh | M1 | **Live** — accepted 9 Sep 2026 |
| 2 | Remove the four audience card links | M1 | Live |
| 3 | "Find a Home" → search bar + properties | M1 | **Live** — verified on production 9 Sep 2026 |
| 4 | "Single Family House" property type | M1 | Live |
| 5 | Cleaning / pet / application fees + pet filter | **M2** | Deferred |
| 6 | Email verification on registration | **M2** (reclassified 9 Sep, awaiting the owner's written agreement) | Not to be built now |
| 7 | Utilities: yes / no / partial | **M2** | Deferred |
| 8 | Photo upload broken | — | **Fixed twice** — see below |
| 9 | How does an admin approve? | — | Answer only: login → Marketplace administration → Listings → Approve |
| 10 | Calendar and mapping | **M2** | Deferred; owner agrees |

### Why 5, 7 and 10 are M2 — argued from the handoff

- **Fees, pet policy, utilities** — M2 "Full listing fields … fees" and
  M2 search "price, bedrooms, bathrooms, type, **pet policy**,
  availability"
- **Mapping** — M2 "List and map presentation", plus the whole
  medical-facility proximity module

The fee and utility fields **already exist in the schema**
(`_tdh_deposit`, `_tdh_application_fee`, `_tdh_pet_fee`,
`_tdh_pet_policy`, `_tdh_utilities`). M2 adds the form controls and the
filters, not the data model. Only **cleaning fee** is genuinely new.

### The calendar scope — settled 9 Sep 2026

The handoff only commits to **availability as a listing field**, but the
reply sent to the owner commits M2 to "landlord-managed availability
connected to date-based search, plus the map/list search experience and
property-location maps".

That is the **larger** build — a landlord blocking out dates as a
property gets rented, not a single "available from" date. Estimate M2
accordingly; it is more than the handoff's bare wording implies.

The full moderation workflow was placed in **M3** in the same reply.

---

## 7. Gotchas that have already cost time

- **The repo went private**, which broke deploys: the server fetched over
  HTTPS and had no credentials. Fixed with a read-only **deploy key** —
  key on the server at `~/.ssh/id_ed25519`, public half in the repo's
  Settings → Deploy keys, remote switched to `git@github.com:`. If
  deploys ever fail with `could not read Username`, this is why.
- **Hostinger disables `proc_open`**, so `wp db export/import` fail.
  `tools/lib-db.sh` falls back to `mysqldump` directly.
- **LiteSpeed cached the account pages**, swallowing form errors and
  serving stale nonces. `class-tdh-no-cache.php` keeps the cache off
  every private page. Add new private pages to its `PRIVATE_KEYS`.
- **Elementor autosaves beat imported layouts**, so the importer deletes
  the autosave first and clears the per-post CSS cache.
- **`wp_parse_args` only fills absent keys**, so widgets `unset()` empty
  URLs to let the plugin's defaults apply.
- **The `background` shorthand resets `background-image`** — form fields
  use `background-color` so the custom select arrow survives focus.
- **A pull can silently revert uncommitted work.** It happened once.
  Commit or stash before pulling.

---

## 8. Blocked on the client — these gate M1 sign-off

- Frontend designer review (acceptance criterion 5)
- Client walkthrough and **written acceptance**
- Business mailbox + SPF / DKIM / DMARC (email currently rides Gmail SMTP)
- Attorney text for Terms, Privacy, Fair Housing — placeholders are live
  and say so on their face
- USD pricing and branding on the live Stripe account
- A2P / SMS registration (EIN is in hand)
- Off-server backup destination
- **No staging environment**, though the handoff assumes one throughout

---

## 9. Milestone 2

Opened on Rob's written go-ahead of 15 September 2026; work starts
17 September 2026. Confirm the Milestone 1 approval on Upwork is recorded.
Everything about Milestone 2 — scope, current state, task order, hand-over
format, client dependencies, decisions pending, requests register — is in
`MILESTONE-2-PLAN.md`. Do not duplicate it here.
