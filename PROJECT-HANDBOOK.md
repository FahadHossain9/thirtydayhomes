# ThirtyDayHomes — project handbook

**Read this before touching the project.** It explains the whole system:
what it is, how it is built, where every piece lives, how data moves, how
work is done and released, and what is known to be wrong. It exists so that
any developer or AI agent can start work without scanning the codebase.

**Other files, and when to read them**

| File | Read it for |
|---|---|
| `AGENTS.md` | The one rules file for every agent: rules, the three pillars, pillar plan, hand-over (`CLAUDE.md` imports it) |
| `MILESTONE-2-PLAN.md` | The current milestone: task cards, order, hand-over format, decisions |
| `MILESTONE-2-CHECKLIST.md` | What is done and what is waiting, as tick boxes |
| `MILESTONE-1-CLOSEOUT.md` | Rules in full, the three standards, Milestone 1 history |
| `ThirtyDayHomes_WordPress_Developer_Handoff.md` | The client contract — the authority on scope |
| `client-data/` | Readable copies of what Rob sends: `hospitals.md` (the facility list for B1/B2/C3). `client-data/private/` (test addresses) and his original Word/Excel files in `rob/` are gitignored — real addresses never go into git |

**Keep this file true.** When a change makes a statement here wrong, fix
the statement in the same commit. A handbook that lies is worse than none.

Last verified against the code: 20 September 2026 (plugin 0.12.0, theme
0.37.0; B1, B2 and R42b live; C1 — search filters, sorting, chips, the
phone drawer and URL state — built, not yet committed).

---

## Contents

1. The project in one page
2. People, rules and the way work moves
3. Environments and access
4. Architecture
5. Data model
6. Pages, URLs, shortcodes and menus
7. User journeys, end to end
8. Plugin file map
9. Theme file map
10. Hook index
11. Elementor
12. Billing (Stripe)
13. Email
14. Tests
15. Deployment, backups and operations
16. Conventions and past mistakes not to repeat
17. Current state
18. Known issues and drift
19. Documents index

---

## 1. The project in one page

**ThirtyDayHomes** is a paid-membership marketplace for furnished rentals of
30 days or more. Launch market Pittsburgh, built to add cities (Cleveland
next). Audience: travel nurses and medical staff, corporate travellers,
construction crews, students, families.

- **Landlords** pay a monthly Stripe subscription for a listing allowance
  (1, 2 or 3 homes), create listings through a 4-step wizard, and receive
  inquiries.
- **Renters** browse and search without an account and send inquiries.
- **Staff / the administrator** approve every listing before it is public,
  manage members and medical facilities, and read inquiries — from a branded
  "Marketplace administration" portal, not wp-admin.
- The distinguishing feature is **distance to hospitals**: each listing is
  measured against staff-managed medical facilities.
- **Street addresses are never public.** Renters see neighbourhood and ZIP.

**Client:** Rob, Thirty Day Homes LLC. **Delivery:** Instaquirk (Fahad
reviews and pushes; team lead Md-Abu-Bakker-Siddik owns the repo).
**Live site:** https://thirtydayhomes.com (Hostinger shared hosting).

**Stack:** WordPress (7.x) · custom plugin `thirtydayhomes-core` (all data
and behaviour) · custom theme `thirtydayhomes` (presentation only) ·
Elementor free (editable page sections) · Stripe Billing (test mode) ·
PHP 8.2 locally, 8.3 live · no build step, no npm in the WordPress build.

**Contract:** three milestones, each paid on written acceptance.

| Milestone | Share | Content | Status (17 Sep 2026) |
|---|---|---|---|
| 1 Foundation | 30 % | Accounts, Stripe memberships, dashboard shell, public pages, Elementor editing, email | Delivered and live; client approved in writing ("should be good to get milestone 2 started", 15 Sep) |
| 2 Core marketplace | 45 % | Full listing management, search/filters/map, hospital proximity, inquiries with email + SMS, membership enforcement | Started 17 Sep; task A1 built, awaiting check |
| 3 Launch & handover | 25 % | Admin tooling, SEO, analytics, performance, go-live, documentation, training | Not started |

---

## 2. People, rules and the way work moves

### Who does what

| Person | Role |
|---|---|
| Rob | Client. Answers scope questions, supplies accounts (Stripe, Twilio, Google Cloud, DNS), approves milestones on Upwork |
| Fahad (Instaquirk) | Reviewer. Checks every task on localhost, writes "100% OK", pushes, talks to Rob |
| Md-Abu-Bakker-Siddik | Team lead, repo owner. Wants every client request captured and M2 finished quickly |
| Developer / AI agent | Builds one task at a time to the plan, hands over, waits |

### The rules (full text in `CLAUDE.md` and `MILESTONE-1-CLOSEOUT.md` §0)

1. Replies in English (the reviewer writes Banglish).
2. Do only what was asked. One task at a time.
3. Hand over, then stop. No commit and no next task until **"100% OK"**.
4. **Never `git push`.** A push to `main` deploys to the live site.
5. Current milestone's scope only. New requests go into the requests
   register (`MILESTONE-2-PLAN.md` §6), never straight into code.
6. Verify on localhost, never on live.
7. Anything the client reads is short: done / how to check / not included.
8. Mirror every changed file to `New folder\thirtydayhomes` (never `.github/`).
9. CSS scoped to its section; no bare `span`/`small`/`b`/`p` descendants.
10. Secrets only in `wp-config.php` constants — never in code, docs or chat.
11. Every hand-over includes a very simple localhost check guide with full
    links, accounts, expected results, a "break it" step and a phone check.
12. Keep `Milestone-2-Report.docx` (client report) current after each live task.
13. No unrequested advice inside client messages.

### The three standards — a gate on every task

Every hand-over carries the filled checklist (`MILESTONE-2-PLAN.md` §0b):

- **A. User journey:** entry → orientation → primary action → completion →
  continuation → recovery → mobile and keyboard → role boundary.
- **B. Corner cases:** empty · one · many · zero results · over limit ·
  wrong owner or type · lapsed mid-task · partial success · double submit ·
  back button · slow or offline · session lost.
- **C. Advanced design:** tokens only; designed empty/loading/success/error/
  disabled/pending states; focus, labels, touch targets; in-product
  confirmations; human wording; reduced motion; every width.

Walk each task as every role it touches: visitor, new landlord, active
landlord, past-due landlord, staff, administrator.

### How a task moves

1. Build (plugin for logic, theme for presentation) with a `tools/verify-*.php`
   suite; `verify.bat` green; bump versions; mirror files.
2. Walk it as every role; take screenshots at desktop and phone width.
3. Hand over in the §0b format with the localhost guide. **Stop.**
4. Reviewer checks and writes "100% OK" (or reports problems → fix only those).
5. Developer commits that task alone with a prepared message.
6. Reviewer pushes from GitHub Desktop → GitHub Action deploys.
7. Reviewer: LiteSpeed → Purge All; Tools → Import Demo Content →
   "Pages and menus" **only** if the task seeded pages/terms/layouts;
   check on live.
8. Update `MILESTONE-2-PLAN.md` §10, `MILESTONE-2-CHECKLIST.md`, and the
   client report.

---

## 3. Environments and access

### Local (the developer machine, Windows 10)

| What | Where |
|---|---|
| Working repo | `D:\fahad vi backup\thirtydayhomes-main` |
| Local WordPress | `D:\xampp\htdocs\thirtydayhomes` → http://localhost/thirtydayhomes |
| Plugin in WP | junction `wp-content\plugins\thirtydayhomes-core` → repo `plugins\thirtydayhomes-core` |
| Theme in WP | junction `wp-content\themes\thirtydayhomes` → repo `themes\thirtydayhomes` |
| PHP | `D:\xampp\php\php.exe` (not on PATH; no ZipArchive) |
| WP-CLI | `D:\xampp\php\php.exe D:\xampp\wp-cli.phar <cmd> --path=D:\xampp\htdocs\thirtydayhomes` |
| git | not on PATH: `%LOCALAPPDATA%\GitHubDesktop\app-*\resources\app\git\cmd\git.exe` |
| Mirror copy | `New folder\thirtydayhomes` — a separate git repo (remote `FahadHossain9/thirtydayhomes`), gitignored in the main repo |
| Captured mail | system temp `thirtydayhomes-mail\*.txt` (local never sends real email) |
| Chrome | `C:\Program Files\Google\Chrome\Application\chrome.exe` (usable headless for screenshots) |

Because the plugin and theme are junctions, **editing the repo changes
localhost immediately.** There is no copy step.

**Local accounts**

- `testuser1@example.com` — landlord with an active plan (quota 2)
- `admin` — WordPress administrator
- The demo "Viewing as" bar (top of every front-end page when
  `TDH_DEMO_MODE` is on) signs in as a persona with no password:
  Renter (signed out), New landlord, Active landlord, Failed payment,
  Administrator (marketplace staff role `tdh_demo_admin`).
- Passwords are not written in the repo. The reviewer knows them; or set
  one with `wp user update <login> --user_pass=<new>`.
- Demo personas have **no membership meta** (status none, quota 0) — use
  `testuser1` for anything that needs an active plan.

**Useful local links**

| Page | URL |
|---|---|
| Home | http://localhost/thirtydayhomes/ |
| Search / all homes | http://localhost/thirtydayhomes/homes/ |
| Sign in | http://localhost/thirtydayhomes/login/ |
| Dashboard (landlord or staff portal) | http://localhost/thirtydayhomes/account/ |
| Staff listings (approve) | http://localhost/thirtydayhomes/account/?view=listings |
| Listing wizard | http://localhost/thirtydayhomes/add-listing/ (add `?step=N&listing=ID`) |
| wp-admin | http://localhost/thirtydayhomes/wp-admin/ |

### Live

| What | Where |
|---|---|
| Site | https://thirtydayhomes.com |
| Host | Hostinger shared hosting (LiteSpeed cache, `proc_open` disabled) |
| Server clone | `~/repos/thirtydayhomes-main` (read-only deploy key, SSH remote) |
| WordPress root | `~/domains/thirtydayhomes.com/public_html` |
| SSH host/port/user | GitHub Actions secrets and Hostinger hPanel — never in docs |
| Backups | `~/backups/thirtydayhomes/` (same server only) |
| Live admins | the reviewer's accounts; ask, never guess |

**There is no staging environment**, although the contract assumes one.
Every push to `main` is a production release.

### GitHub

- Deploy repo: `Md-Abu-Bakker-Siddik/thirtydayhomes-main` (private).
- Actions secrets: `SSH_PRIVATE_KEY`, `SSH_HOST`, `SSH_PORT`, `SSH_USER`.
- The reviewer pushes from GitHub Desktop. Agents never push.

---

## 4. Architecture

### The one rule

**If deleting the theme would lose data or behaviour, the code is in the
wrong place.** The plugin owns every marketplace rule, query, record, form
handler, email and screen that is not purely visual. The theme owns
templates, styles, icons and layout helpers only.

### Repository layout

```
plugins/thirtydayhomes-core/   the marketplace engine (deployed)
  thirtydayhomes-core.php      header, VERSION const, autoloader, boot
  includes/                    one class per concern (namespace TDH)
    admin/  billing/  demo/  elementor/  setup/
  assets/seed-images/          sample listing photos
  tools/                       verify-*.php test suites, import/baseline CLIs
themes/thirtydayhomes/         presentation (deployed)
  style.css  functions.php  templates  inc/  assets/  template-parts/  elementor/
tools/                         server scripts + local tools (NOT deployed)
  deploy.sh backup.sh restore.sh lib-db.sh build-release.ps1 test-webhook.ps1
  client-report/               client .docx generator
.github/workflows/deploy.yml   push-to-deploy
src/ index.html package.json public/   the original React prototype (reference only)
30 days/                       client's original spec and delivery plan (.docx)
docs/                          an old HTML plan (historical)
New folder/                    the mirror repo (gitignored)
*.md                           plans, rules, this handbook
```

### Boot sequence

- `thirtydayhomes-core.php` registers a PSR-4-ish autoloader:
  `TDH\Foo_Bar` → `includes/class-tdh-foo-bar.php`;
  `TDH\Billing\Customer_Portal` → `includes/billing/class-tdh-customer-portal.php`.
- On `plugins_loaded` (priority 5) `Core::instance()->init()` builds every
  module and calls its `register()`, which adds that module's hooks.
- `init` priorities: post types 5 → taxonomies 6 → statuses 7 → meta 8 →
  textdomain 10.
- `pre_get_posts`: Visibility (10) then Search (20).
- `init` (priority 5) → `Core::maybe_upgrade()`: if stored `tdh_db_version`
  < `VERSION`, reinstall tables and roles, flush rewrites, and run any
  versioned step. **Runs on the first request of any kind after a deploy
  (front end, WP-CLI or wp-admin), and only if VERSION was bumped** — it
  was `admin_init` until 0.24.7, which left content changes waiting for
  someone to open wp-admin. Steps so far: **0.24.7** (G2 design system)
  renames the primary menu's "Renter FAQ" → "How it works" and "List your
  property" → "Pricing" (only if the title is still the importer's), and
  removes `_tdh_rating` and the sample badges "Guest favorite" / "Top
  location" from every home, saving the old values in the option
  `tdh_design_v2_removed_meta` so they can be put back.

### Modules (`Core::module( 'key' )`)

| Key | Class | Loaded |
|---|---|---|
| post_types | Post_Types | always |
| statuses | Statuses | always |
| fields | Fields | always |
| roles | Roles | always |
| visibility | Visibility | always |
| proximity | Proximity | always |
| accounts | Accounts | always |
| shortcodes | Shortcodes | always |
| mail | Mail | always |
| smtp | Smtp | always |
| webhook | Billing\Webhook | always (Stripe calls it logged out) |
| checkout | Billing\Checkout | always |
| customer_portal | Billing\Customer_Portal | always |
| contact | Contact | always |
| no_cache | No_Cache | always |
| views | Views | always |
| listing_form | Listing_Form | always |
| moderation | Moderation | always |
| user_privacy | User_Privacy | always |
| search | Search | always |
| listing_preview | Listing_Preview | always |
| listing_actions | Listing_Actions | always |
| availability | Availability | always (the My listings quick edit posts to it) |
| geocoder | Geocoder | always (addresses are saved from the front end; staff "Set location" posts to it) |
| meta_boxes, reference, importer, payments, email, security, editor_hint | Admin\*, Billing\Settings | wp-admin only |
| demo | Demo\Demo_Mode | always created; registers nothing unless `TDH_DEMO_MODE` and not production |
| elementor | Elementor\Registrar | only when Elementor is active |

Static helpers with no hooks: `Membership`, `Phone`, `Security_Baseline`,
`Activator`, `Billing\Stripe`, `Render`, `Account_Render`,
`Listing_Form_Render`, `Listing_Manage_Render`, `Availability_Render`.

### Front-end form handling pattern

Every form posts to its own page with a hidden `tdh_action`. Handlers run on
`template_redirect`, check capability then nonce, validate, write, and
redirect (POST-redirect-GET). Messages never travel in the URL — only a
short flag (e.g. `tdh_moderated=approved`), and the renderer owns the words.
Errors and typed values are stashed in a transient keyed to the visitor.

---

## 5. Data model

### Post types

| Slug | Public | REST | Capability type | Supports | URL |
|---|---|---|---|---|---|
| `tdh_listing` | yes | yes | `tdh_listing` / `tdh_listings`, map_meta_cap | title, editor, thumbnail, author, revisions, excerpt, custom-fields | archive `/homes/`, single `/homes/<slug>/` |
| `tdh_facility` | no | no | `tdh_facility` / `tdh_facilities` | title | none (reference data) |
| `tdh_inquiry` | no | no | `tdh_inquiry` / `tdh_inquiries` | title | none; stored with status `publish` |

### Taxonomies (all hierarchical, public, in REST)

| Slug | On | URL base | Seeded values |
|---|---|---|---|
| `tdh_property_type` | listing | `/property-type/` | Apartment, Condo, Duplex, Guest House, Loft, Single Family House, Studio, Townhouse (add-only seeding) |
| `tdh_neighborhood` | listing | `/neighborhood/` | created by sample content: Shadyside, Lawrenceville, Oakland, South Side, Squirrel Hill |
| `tdh_amenity` | listing | `/amenity/` | not seeded; terms created from the wizard's fixed catalogue (10 groups, `Listing_Form::amenity_groups()`) |
| `tdh_city` | listing, facility | `/city/` | Pittsburgh (created by sample content) |

### Listing statuses

| Status | Label | Meaning |
|---|---|---|
| `draft` | Draft | Landlord still editing |
| `pending` | Pending review / "In review" | Submitted, waiting for staff |
| `publish` | Live | The **only** publicly visible status |
| `tdh_paused` | Paused | Landlord took it down on purpose |
| `tdh_rejected` | Rejected / "Changes requested" | Staff sent it back; reason in `_tdh_rejection_reason` |
| `tdh_billing_hold` | Hidden — membership / payment | System hid it for a lapsed membership. **Only these are restored on renewal** — never merge with paused |

`Enforcement` (E1) moves live homes into `tdh_billing_hold` when a plan is
past its grace, and moves exactly those back on payment.

### Listing meta (`Fields::listing_schema()` — the single source of truth)

"Private" keys are never in REST and never in public markup.

| Key | Type | Private | Notes |
|---|---|---|---|
| `_tdh_street_address` | string | **yes** | Never shown publicly |
| `_tdh_zip` | string | | 5 digits |
| `_tdh_state` | string | | default PA |
| `_tdh_lat`, `_tdh_lng` | number | **yes** | Filled from the address by `TDH\Geocoder` (B1), or typed by staff; empty or 0,0 = no point |
| `_tdh_geocode_status` | string | yes | '' not checked / ok found / failed not found / manual typed — read-only in wp-admin, written by the Geocoder only |
| `_tdh_geocode_reason` | string | yes | why not found / not checked: not_found, imprecise, no_key, denied, limit, network, unknown, no_address |
| `_tdh_price_monthly` | number | | rent |
| `_tdh_deposit` | number | | |
| `_tdh_application_fee` | number | | |
| `_tdh_pet_fee` | number | | cleared when pets = no |
| `_tdh_cleaning_fee` | number | | added in A1 |
| `_tdh_beds` | number | | |
| `_tdh_baths` | number | | 0.5 steps |
| `_tdh_sqft` | integer | | max 20000 in wizard |
| `_tdh_rooms` | integer | | max 50 in wizard |
| `_tdh_badge` | string | | editorial card badge, a fact about the home ("Utilities included"); empty = automatic "New this week". `_tdh_rating` was removed in 0.24.7: there is no review system, so the card never prints a rating (G2) |
| `_tdh_furnished` | boolean | | default true |
| `_tdh_backyard`, `_tdh_parking` | string | | free text, max 80 in wizard |
| `_tdh_min_stay_days` | integer | | 30 / 60 / 90 / 91 (= "13 weeks") |
| `_tdh_available_from` | string | | YYYY-MM-DD; blank = free now (A5: set on step 2 or the quick edit, can be cleared) |
| `_tdh_blocked_ranges` | string | | unavailable periods, one per line `2026-12-15 to 2027-01-05`, both days included, sorted, never overlapping; read only through `Availability::ranges()` (A5) |
| `_tdh_lease_term` | string | | not in the wizard |
| `_tdh_utilities_included` | string | | '' Not given / yes / partial / no (added A1) |
| `_tdh_utilities` | string | | free-text details ("Water and trash") |
| `_tdh_pet_policy` | string | | '' Not given / yes / considered / no |
| `_tdh_contact_name` | string | **yes** | inquiry contact |
| `_tdh_contact_email` | string | **yes** | empty = use the account email |
| `_tdh_contact_phone` | string | **yes** | E.164 (`+14125550184`) via `TDH\Phone` |
| `_tdh_contact_method` | string | **yes** | email / sms / both ("Either") |
| `_tdh_fair_housing_ack_at` | string | yes | MySQL datetime of the acknowledgment |
| `_tdh_rejection_reason` | string | yes | shown to the landlord |
| `_tdh_submitted_at`, `_tdh_approved_at` | string | yes | written on submit / approval (A3) |
| `_tdh_approved_by` | integer | yes | written on approval (A3) |
| `_tdh_live_since`, `_tdh_paused_at` | string | yes | "Live since" / "Paused on" on the landlord's row (A3) |
| `_tdh_reviewing_edits` | string | yes | labels of the material fields a live home's edit changed; set while it is back in review, cleared on approval (A4) |
| `_tdh_edited_while_paused` | string | yes | labels of material edits made while paused; Resume then goes to review (A4) |

Not in the schema (not registered): `_tdh_nearest_facilities` (proximity
cache: up to 10 rows of id, title, type and miles, closest first — B2
replaced the single-row `_tdh_nearest_facility`), `_tdh_views` (view
counter), `_tdh_geocode_address` (fingerprint
of the address last looked up) and `_tdh_geocode_point` (the point the
lookup wrote) — both B1 bookkeeping, on listings and facilities.

### Facility meta (`Fields::facility_schema()`)

`_tdh_facility_type` (hospital / clinic / rehab / other), `_tdh_street_address`,
`_tdh_state`, `_tdh_zip`, `_tdh_lat`, `_tdh_lng`, `_tdh_active` (boolean,
"Active in renter search"), `_tdh_sort_order`, `_tdh_geocode_status`
(read-only, B1). Five Pittsburgh hospitals are seeded with coordinates
(they read as "set by hand"): UPMC Presbyterian, UPMC Shadyside, UPMC
Children's, AHN Allegheny General, UPMC Mercy. Rob's full list of 11 is in
`client-data/hospitals.md` — not yet entered.

### Inquiry meta (`Fields::inquiry_schema()` — all private)

`_tdh_listing_id`, `_tdh_renter_name`, `_tdh_renter_email`,
`_tdh_renter_phone`, `_tdh_move_in`, `_tdh_stay_length`, `_tdh_message`,
`_tdh_rules_version`, `_tdh_inquiry_kind` ('' = about a listing,
`contact` = Contact page), `_tdh_topic`, `_tdh_notified` (sent / failed /
no-recipient), `_tdh_read` (boolean), `_tdh_status` (new / opened / archived).

**Every key a form writes must be in this schema**, or wp-admin cannot show
it (a stored message once became unreadable this way).

### User meta

| Key | Meaning |
|---|---|
| `_tdh_membership_status` | none / active / past_due / cancelled / expired |
| `_tdh_membership_plan` | plan label, e.g. "3 listings" |
| `_tdh_membership_expires` | unix timestamp |
| `_tdh_listing_quota` | listings allowed; empty = 0 |
| `_tdh_stripe_customer_id`, `_tdh_stripe_subscription_id` | link to Stripe |
| `_tdh_phone`, `_tdh_company` | from registration/profile. Since D4 the phone is edited in the **Text message alerts** card, not the details grid, and `do_profile()` writes it only when the field was posted; any change to it fires `Sms::on_phone_changed()`, which resets the SMS verification |
| `_tdh_terms_accepted` | timestamp at registration |
| `_tdh_email_pending` | F1: the address still to be confirmed. **Absent = confirmed** (grandfathers every older account, staff-made members and personas) |
| `_tdh_email_verified_at` | F1: when the link was followed |
| `_tdh_email_token`, `_tdh_email_token_expires`, `_tdh_email_token_spent`, `_tdh_email_sends` | F1: HMAC of the live token (never the token), its 24-hour end, the spent one (to say "already confirmed"), send times for the 60 s / 5-an-hour limit |
| `_tdh_demo_persona` | demo users only |

### Roles and capabilities

- **`tdh_landlord`**: read, `edit_tdh_listing(s)`, `delete_tdh_listing(s)`,
  `edit_published_tdh_listings`, `delete_published_tdh_listings`,
  `upload_files`, `read_tdh_inquiry`. Explicitly **no** `publish_tdh_listings`
  and no `*_others_*` — that absence is what enforces ownership.
- **administrator**: every tdh_listing / tdh_facility / tdh_inquiry
  capability (`Roles::administrator_caps()`), plus `tdh_moderate_listings`,
  `tdh_manage_facilities`, `tdh_view_all_inquiries` (granted, never checked).
- **`tdh_demo_admin`** (demo only): all marketplace capabilities + pages +
  `edit_theme_options`; **no** `manage_options`.
- **Staff test:** `Accounts::is_staff()` = `edit_others_tdh_listings`.
  Never use `manage_options` for marketplace permissions.
- **Landlord test:** `Accounts::is_landlord()` = has `tdh_landlord` and not
  `manage_options`.
- Roles are rebuilt (`remove_role` + `add_role`) on every version upgrade;
  hand-added landlord capabilities are wiped.
- Inquiry ownership: `Roles::map_listing_caps()` on `map_meta_cap` lets the
  author of the inquiry's listing read it; everyone else needs
  `read_private_tdh_inquiries`.

### Custom tables (created, not yet used)

- `{prefix}tdh_distances` — listing_id, facility_id, miles, drive_minutes,
  computed_at.
- `{prefix}tdh_log` — created_at (UTC), level, feature, event, message
  (255), context (masked JSON), user_id, object_type, object_id, source
  (`file:function`), request_id; indexes on feature+time, level+time,
  object, user+time. Written by `TDH\Log`, read on `?view=logs`, purged
  after 90 days (R42b)
- `{prefix}tdh_notifications` — inquiry_id, channel, recipient,
  provider_message_id, status (default queued), provider_response,
  attempts, created_at, updated_at. Written by `TDH\Notifications` (D3).
  **One row per (inquiry, channel)**: an attempt increments `attempts` on
  the existing row rather than adding another, because three attempts at
  one email are one thing that happened three times, and a staff screen
  listing it three times reads as three enquiries. `status` is
  `queued` → `sent`, or `failed` → `given_up` after
  `Notifications::MAX_ATTEMPTS` (3). `provider_response` is whatever the
  mail server said, truncated to 500 and never a secret — it is shown on a
  staff screen. D4 adds `sms` rows to the same table.

### Options, transients, constants, cookies

| Kind | Name | Owner |
|---|---|---|
| option | `tdh_db_version` | Core upgrade marker |
| option | `tdh_stripe_mode`, `tdh_stripe_{test|live}_{publishable|secret|webhook|price_1|price_2|price_3}` | Stripe |
| option | `tdh_mail_from`, `tdh_mail_from_name` | Mail |
| option | `tdh_smtp_{enabled|host|port|username|password|encryption}`, `tdh_smtp_last_error`, `tdh_smtp_last_sent` | Smtp |
| option | `tdh_demo_imported_at`, `tdh_menu_fingerprint_{location}` | Importer |
| transient | `tdh_notice_{visitor}` | Accounts form stash (5 min) |
| transient | `tdh_login_fail_*`, `tdh_login_ip_*` | login throttle |
| transient | `tdh_contact_{visitor}`, `tdh_contact_rate_{md5 ip}` | Contact form |
| transient | `tdh_lform_{user_id}` | wizard errors (5 min) |
| transient | `tdh_geocode_pause` (60 s), `tdh_geocode_typed_{user_id}` (5 min) | Geocoder: leave Google alone after "slow down"/timeout; a refused "Set location" entry |
| option | `tdh_geocode_problem` | Geocoder: last "denied"/"limit" from Google, for the staff notice; cleared by the next success |
| option | `tdh_proximity_count`, `tdh_proximity_radius` | How many hospitals a property page lists (1–5, default 3) and how far out it looks (1–100 miles, default 15); set on Listing setup (B2) |
| option | `tdh_log_purged` | Log: when the daily clean-up last ran and how many rows it removed (R42b) |
| option | `tdh_inquiry_admin_copy`, `tdh_inquiry_admin_copy_to` | Notifications: send a second copy of every enquiry email to one more address. **Off by default**; set on Email delivery → Copies of enquiry emails (D3) |
| transient | `tdh_resend_{row}` | Notifications: 30 seconds, so a second press of **Send it again** cannot send a second email |
| constant | `TDH_SMS_ENABLED` | Sms (D4): the feature switch. Without it nothing registers except the verification reset on a phone edit |
| constant | `TDH_TWILIO_SID`, `TDH_TWILIO_TOKEN`, `TDH_TWILIO_FROM` | Sms\Twilio. `FROM` is a number in E.164 or an `MG…` Messaging Service SID. **wp-config.php only — never an option, never the repo** |
| constant | `TDH_SMS_TEST_RECIPIENTS` | Sms: comma-separated E.164 numbers. Non-empty = **test mode**: only these are texted, everyone else's text is written down as "not sent". Shows an admin notice on every page while set |
| transient | `tdh_sms_rate_{user}` | Sms: texts to one landlord this hour; past `Sms::RATE_LIMIT` (20) the rest go by email only |
| user meta | `_tdh_sms_verified`, `_tdh_sms_verified_at`, `_tdh_sms_consent`, `_tdh_sms_consent_at`, `_tdh_sms_opted_out`, `_tdh_sms_opted_out_at`, `_tdh_sms_code_hash`, `_tdh_sms_code_expires`, `_tdh_sms_code_tries`, `_tdh_sms_code_sent` | Sms: `_tdh_sms_verified` holds the E.164 number that was confirmed, so a change to `_tdh_phone` is detectable; the code is stored hashed only |
| transient | `tdh_log_failed_writes` | Log: writes that failed in the last day, shown on System status; a failed write never breaks the action it describes |
| cron | `tdh_log_purge` | Log: daily; deletes rows older than `Log::RETENTION_DAYS` (90) in batches of 1000; cleared on deactivation |
| transient | `tdh_stripe_event_{md5 id}` | webhook dedupe (4 days) |
| cookie | `tdh_notice_id` | visitor token for stashes (10 min) |
| constant | `TDH_DEMO_MODE`, `TDH_MAIL_CAPTURE`, `TDH_SMTP_*`, `TDH_STRIPE_{MODE}_{FIELD}`, `TDH_REMOVE_ALL_DATA_ON_UNINSTALL`, `TDH_MAPS_SERVER_KEY` (Google Geocoding, server only — B1; not set anywhere yet) | wp-config |
| file | `wp-content/uploads/.tdh-last-backup` | backup receipt |
| browser | localStorage `tdh_saved_homes`, `tdh_portal_sidebar_collapsed`; sessionStorage `tdh-viewed-{id}` | theme JS / view beacon |

### REST

- `POST /wp-json/tdh/v1/listing-view` — public view beacon (`Views`).
- `POST /wp-json/tdh/v1/stripe-webhook` — Stripe (`Billing\Webhook`).
- `/wp/v2/tdh_listing` and taxonomy routes are exposed; private meta is
  stripped. `/wp/v2/users` is removed for anyone without `list_users`.

---

## 6. Pages, URLs, shortcodes and menus

### Seeded pages — found by seed key, never by slug

Every structural page carries post meta `_tdh_seed_key`.
`Accounts::url( 'key' )` returns that page's permalink (falls back to home).
**Always link pages this way** — slugs can be renamed by the client.

| Seed key | Title | Content | Flags |
|---|---|---|---|
| home | Home | Elementor layout (hero, audience, grid, split, owner CTA); shortcodes as fallback | front page |
| how-it-works | How it works | `[tdh_how_it_works]` / Elementor | wide |
| pricing | Membership | `[tdh_pricing]` / Elementor | full layout |
| about | About | `[tdh_about]` / Elementor | wide |
| contact | Contact | `[tdh_contact]` / Elementor | wide |
| terms | Terms of Service | **a real "SMS terms" section** (24 Sep 2026, for the carriers' campaign form: the brand, one text per inquiry, consent optional, frequency, "Message and data rates may apply", STOP/HELP, carrier liability, a link to the privacy page) — then a `.page-note` for the rest. Was "Terms of Use" until 24 Sep; the carriers accept only "Terms & Conditions" or "Terms of Service", and the sign-up form's link and refusal were renamed with it | |
| privacy | Privacy Policy | **a real "Text messages" section** — the owner's approved sentence plus frequency, HELP, rates and retention (R46), and since 24 Sep the brand name, what is kept and why, and the carriers' exact statement *"We do not sell or share your SMS opt-in data or personal information with third parties for marketing purposes."* — then a `.page-note` for the rest | privacy page |
| fair-housing | Fair Housing | real copy, plus a `.page-note` marking it draft | |

The three legal pages share one treatment: prose in `.page-shell.narrow >
.prose`, each `h2` opening a section above a hairline rule, and every
"this is not final yet" aside in a `.page-note` panel. The panel exists so
a reader — or a carrier's reviewer — never mistakes our editorial note for
one of the terms. The privacy page's texting clauses are checked literally
in `verify.php`, because each is a claim about behaviour: an inquiry is the
only thing that triggers a text, STOP and HELP are answered by the
provider, and nothing deletes a phone number on a schedule.
| register | Create an account | `[tdh_register]` | noindex |
| login | Sign in | `[tdh_login]` | noindex |
| lost-password | Reset your password | `[tdh_lost_password]` | noindex |
| reset-password | Choose a new password | `[tdh_reset_password]` | noindex |
| account | Dashboard | `[tdh_account]` | noindex |
| profile | Account details | `[tdh_profile]` | noindex |
| add-listing | List your home | `[tdh_add_listing]` | noindex |

Page flag meta read by the theme: `_tdh_full_layout`, `_tdh_wide_body`,
`_tdh_headline`, `_tdh_lead`, `_tdh_noindex`.

Private pages (never page-cached): login, register, lost-password,
reset-password, account, profile, contact, pricing, add-listing
(`No_Cache::PRIVATE_KEYS`). **Add any new private page there.**

### Shortcodes

| Tag | Renders |
|---|---|
| `[tdh_hero_search]` | Hero with search (q, start, end dates) |
| `[tdh_property_grid]` | Listing cards (count, columns, orderby, neighborhood, heading…) |
| `[tdh_audience]` | Four audience cards |
| `[tdh_split_feature]` | Photo + benefits band |
| `[tdh_owner_cta]` | "List your property" band |
| `[tdh_pricing]` | Membership plans with Stripe checkout buttons |
| `[tdh_about]`, `[tdh_how_it_works]`, `[tdh_contact]` | Full page bodies |
| `[tdh_register]`, `[tdh_login]`, `[tdh_lost_password]`, `[tdh_reset_password]` | Account screens |
| `[tdh_account]` | Landlord portal or staff marketplace portal |
| `[tdh_profile]` | Account details (inside the portal) |
| `[tdh_add_listing]` | The 4-step listing wizard |

Page sections share one renderer (`TDH\Render`) behind both the shortcode
and the Elementor widget.

### Menus (seeded, fingerprinted — edits are kept)

- **Primary:** About · Find a home (`/homes/`) · Renter FAQ (how-it-works) ·
  List your property (pricing) · Sign in (login) · List your home (register,
  gold CTA). For signed-in users, `Accounts::account_aware_menu()` removes
  the register item and turns "Sign in" into "Dashboard".
- **Footer:** Find a home, How it works, Membership, About, Contact, Terms,
  Privacy, Fair Housing.

---

## 7. User journeys, end to end

### Renter: find a home

1. Home hero asks for a place and move-in/move-out dates (submit disabled
   until both dates, `assets/nav.js`), GET to `/homes/?q=&start=&end=`.
2. `Visibility::filter_public_queries` forces `publish` (membership half
   is not wired yet). `Search::apply` (priority 20) reads the keyword
   first, and there are **two ways it can be read** (C6):
   - If the term names a **place** — a postcode, town, neighbourhood or
     county, resolved by `Area::point()` — the results become every home
     near that point, **closest first**, inside the radius (the staff
     setting by default, `within=any` to drop it). A home that names the
     place but was never geocoded is kept and put last rather than
     dropped. This is what makes "15226" answer with the five nearest
     homes instead of nothing (R44/R18).
   - Otherwise it narrows by **text** across title, neighbourhood, city,
     type and ZIP, plus the homes near a hospital whose name matches (no
     match → `post__in = [0]`).
   A hospital chosen in the filter bar always beats a place typed in the
   box, and a term naming a hospital stays a hospital. With no key, a
   refused key or a slow service the place lookup returns null and the
   text path runs, so the page never errors. Then the **stay** (C2), then
   the chosen **hospital and radius** (C3, which also sets the order), and
   then
   applies the **filters** from the URL (C1):
   `min_price`/`max_price` (`_tdh_price_monthly`, swapped if the wrong way
   round), `beds` ≥, `baths` ≥ in half steps, `type` (property-type slug),
   `pets` (yes / considered / no), `sort` (newest, price-asc, price-desc —
   a home with no price is left out of a price sort). Nonsense values are
   ignored, never errors. The same rules run on the city, property-type,
   neighborhood and amenity archives.
3. `template-parts/listing-results.php` — shared by
   `archive-tdh_listing.php` and the `taxonomy-tdh_{city,property_type,
   neighborhood}.php` templates (which used to fall through to the bare
   `index.php`) — shows **one search form** (`Render::filter_bar()`, since
   G3a: the keyword row with the single filled **Show homes** above the
   filter panel, one GET form; on phones `assets/filters.js` turns the
   panel into a drawer with a backdrop, Escape, a focus trap and focus
   return, and the drawer carries its own Show homes because the keyword
   row's is behind it; without the script the panel stacks and its button
   is the form's one), **Sort by** beside the result count
   (`Render::sort_control()`: its own small GET form carrying the current
   search as hidden fields, submitted on change by filters.js, with a Sort
   button for a page with no script; the Within list always includes the
   staff radius, even one off the standard 5/10/15/25/50 steps), removable
   **chips**. The lists these selects open — and the inquiry form's — are
   drawn as theme cards where the browser has the customizable select
   (`appearance: base-select`, Chrome 135+, style.css "DROPDOWN LISTS");
   elsewhere they are the native list. Removable **chips**
   (`Render::filter_chips()`, × removes one, Clear all keeps the keyword),
   a count (`Search::summary()`), cards, pagination that keeps every
   filter, and an empty state that names the constraint
   (`Search::empty_sentence()`: "No 2+ bedroom homes under $2,000 that
   allow pets") with the chips right there. On a neighbourhood, city, type
   or amenity page with nothing asked it names the place — "No homes in
   South Side yet" — rather than the site-wide "No homes are listed yet".
   A page number past the end (`/homes/page/9/`, or page 3 of a search
   that a new filter shrank to one page) goes **back to the first page of
   the same search**, filters kept (`Search::back_to_first_page()`, on
   `template_redirect`): WordPress answers it as a 404, and the theme's
   not-found page is written for a missing home. A keyword is capped at
   `Search::MAX_TERM` (100 characters), and the chips, the count and the
   empty heading wrap a long unbroken one instead of widening the page
   (23 Sep 2026).
   On the `/homes/` archive only, H1 Version B replaces the photographic
   banner below 43.75rem with the short **Find a home** orientation and
   puts the full-width keyword, Filters / Show homes row, result count,
   Sort by and List / Map directly above the first home; when that toolbar
   wraps at 320px, its second row stays aligned with the result count. The first card
   begins inside the opening viewport at both 390px and 320px. Desktop,
   taxonomy archives, Elementor result blocks, GET URLs, Back/share and
   successful in-place refreshes are unchanged. If a phone refresh fails,
   the current homes and typed fields stay put, loading clears, and one
   alert offers **Try again** for the same URL. Without JavaScript the real
   GET filter fields stay expanded in the page, so every filter still works.
4. Card (`template-parts/listing-card.php`; since G3a read in the order a
   renter decides: the rent largest with an availability **pill** beside
   it — "Available now" · "Available 6 Nov" · "No open dates right now" ·
   "Free for your dates", green / sand / grey and always in words — then
   the name, the place, the facts as icon + bold number + unit, the
   hospital band, and one **View home** link at the foot) → single page
   (`single-tdh_listing.php`): valid photos stay in the landlord's order in
   a 60/40 five-tile desktop mosaic, cover plus two tablet previews or one
   phone cover. **Show all N photos** opens a focused viewer with **Photo X
   of N**, captions, bounded Previous / Next, keyboard navigation and exact
   focus return; one photo stays a plain cover without false controls. The
   facts follow as one line ("2 bedrooms · 1
   bathroom · 1,050 sq ft · 5 rooms", singular when one), description,
   **Included** (amenities, utilities, parking, backyard — ticks) and
   **House rules** (pets, minimum stay — neutral marks, because "✓ No
   pets" read as the opposite), availability, "Close to care" (the map
   area, then the nearest hospitals under a heading that names the real
   farthest distance — "3 medical facilities within 1.7 miles",
   `Proximity::within_phrase()`, rounded up to the rows' precision), and
   the inquiry form last, at `#inquire`. The side column holds only the
   price and fees and a gold **Ask the owner** that jumps to the form.
5. **Inquiry** (D1). Every word the site shows says *inquire / inquiry*,
the American spelling Rob asked for (R53, 0.24.6); only internal class
names such as `.enquiry-card` keep the old one. Since G3a the form
   (`.enquiry-card`) sits in the **main column** at `#inquire`, and the
   side column (`.listing-side` → `.price-box` with **Ask the owner**) is
   short enough (~420px) to pin, so the price and the button stay in view
   down the whole page and cover nothing; below 950px a fixed bottom bar
   (`.detail-bar`) carries the rent and the button. `assets/detail.js`
   hides the button and the bar while the form or the footer is on screen
   (IntersectionObserver), so one filled button is ever in view, and moves
   focus to the form's first field after the jump. The three layouts that
   came before — one tall card, two cards with the price pinned, nothing
   pinned — are recorded above `.listing-side` in style.css and checked
   by verify-inquiry. The form asks for name, email, optional
   phone, move-in, expected stay, message, and a consent box that records
   which version of the rules was on screen (`Inquiry::RULES_VERSION`,
   R24). `Inquiry::handle()` runs on `template_redirect`, stores the
   enquiry, fires `tdh_inquiry_received` and redirects — so the success
   screen is a fresh GET and Back cannot re-send. Success **replaces** the
   form rather than sitting above it. Refusals keep every typed value,
   including the address exactly as typed. A home paused between page load
   and Send is refused and says so.
6. "Save" hearts are browser-only (localStorage).

### Landlord: account

- **Register** (`tdh_action=register`): name, email, phone, company,
  password ≥ 8 (`Accounts::MIN_PASSWORD`, R54), terms, honeypot. Creates `tdh_landlord`, membership `none`,
  signs in, lands on the dashboard. Since F1 it also emails a confirmation link and the welcome says so.
- **Sign in** (`login`): email or username + password (the box is "Email or username", a text field — R41), throttled (5 per account
  and IP, 20 per IP, 15 minutes). Same message for wrong email or password.
- **Lost / reset password**: same confirmation whether or not the account
  exists; custom email with our reset URL; all sessions destroyed on reset.
- **Profile** (`profile`, inside `?view=profile`): current password needed
  to change email or password; auth cookie reissued. A landlord's new email must be confirmed again (F1).
- **Confirm the email (F1, `TDH\Email_Verification`)**: until the link is followed, a band on every dashboard view says "Confirm your email address — We sent a link to x…" with an outlined **Send a new link** (60 s apart, 5 an hour; each new link kills the older ones); the pricing page says the same as information; starting a plan (`tdh_checkout_error=verify_email`) and submitting a home are refused, the draft kept. The link (`/account/?tdh_verify=<id>.<token>`) works signed out and signs the landlord in; used twice it says "already confirmed" and signs nobody in; expired (24 h) it signs nobody in and asks for a new one; forged, unknown or sent to an address the account no longer has it says "not valid" — the same whichever. Every send goes to the account's current address. Staff are never asked. Log: `members` / `verify_sent`, `verified`, `verify_send_failed`. Local mail from the web server is captured in `C:\Windows\TEMP\thirtydayhomes-mail` (the command line's temp folder differs).
- Landlords are kept out of wp-admin (redirected to the dashboard) and never
  see the admin bar.

### Landlord: the inbox (D2)

The portal is **`/account/`** — there is no page under the key
`dashboard`, and `Accounts::url()` answers an unknown key with the home
page, so build inbox links on `Accounts::url( 'account' )`.

- **`?view=inquiries`** — tabs **All / Unread / Archived**, 20 a page,
  newest first. Unread is shown as a **word** ("New") and weight, not
  colour alone, and the count appears on its own tab and in the nav.
- Every row names the home it is about, because a landlord may have
  several; a deleted home reads **"Listing removed"** in italics and its
  conversations are kept (`post_status` must name `trash` explicitly —
  `'any'` drops it).
- **`?view=inquiries&msg=<id>`** — one message: the renter's email and
  phone as `mailto:`/`tel:` links, move-in, length of stay, the message,
  **Reply by email** (primary) and **Archive** (secondary — nothing is
  deleted, so it neither looks destructive nor confirms).
- Opening marks it read, and does so **before** the badge is counted, or
  the nav and the list disagree by one. Archiving is POST-redirect-GET
  with a nonce and is idempotent.
- **Ownership** is `Inquiry::can_read()`: the author of the home the
  enquiry is about. Staff read everything; a Contact-page message has no
  home and is staff-only. A wrong owner is answered exactly as a missing
  id, so ids cannot be probed.
- Staff read the same message **inside the portal** at
  `?view=inquiries&msg=<id>`, plus which landlord owns it and whether
  they have opened it. It used to link to wp-admin's post editor — R29.

### The landlord's email, and what happens when it fails (D3)

`TDH\Notifications` listens to `tdh_inquiry_received`, so it runs only
**after** the enquiry is stored and the renter has already been told it
was. A mail server that hangs cannot cost a renter their message, and the
renter is never told whether it worked — the failure is loud, but only to
us.

- **The send is booked, not made.** `on_inquiry()` writes the row and calls
  `wp_schedule_single_event( time(), … )`; WordPress spawns due events in a
  separate non-blocking request, so the renter's page returns at once. It
  used to send inline, which put `Smtp::TIMEOUT` — **fifteen seconds** —
  between the renter pressing Send and their success screen, and a renter
  who gives up before it arrives never learns that their message was saved
  perfectly well. Measured at 2.3 s end to end afterwards.
- Because the first attempt now depends on cron, `sweep()` collects
  **queued** rows as well as failed ones, after `QUEUED_GRACE` (2 minutes):
  on a host with cron off, the attempt that goes missing is the first one,
  and a row sitting queued for ever is worse than one that failed loudly.
  Both the sweep and `send()` are restricted to `channel = email` —
  everything there ends in `wp_mail()`, and D4's `sms` rows start life
  queued in this same table.

- **Recipient**: the home's `_tdh_contact_email` when it is usable, else
  the author's account address. An unusable one **falls back rather than
  failing** and logs `contact_email_unusable`, so the landlord can be told
  about the typo. No usable address anywhere writes a `given_up` row
  saying so — an enquiry with no row at all looks like one that was never
  processed.
- **The body deliberately leaves out the renter's phone number and their
  message.** An inbox is a less careful place than the dashboard and mail
  gets forwarded; the email says who wrote, about which home, when they
  want it, and links to `?view=inquiries&msg=<id>`.
- **Reply-To is the renter**, From stays our authenticated address — the
  renter's there would fail SPF and put the one email that must not be
  missed in spam. The display name has `,;<>"` stripped first, because
  `wp_mail()` splits Reply-To on commas and "Dana Whitfield, Jr." would
  otherwise become two addresses, the second one nonsense.
- **Retries** at +10 min then +60 min, then `given_up`. Each failure
  clears the booking before making the next, so retries cannot stack.
  `send()` refuses a row that is `sent` or `given_up`, so a stray cron
  event cannot push the counter past three.
- **Cron is not trusted.** WordPress cron only fires on a page view, so a
  quiet night would leave a failure unretried until morning. `sweep()`
  runs on `admin_init` and picks up anything overdue — filtering by **each
  row's own backoff**, because the query can only ask for the shortest and
  sweeping a second attempt early would spend all three inside the first
  ten minutes of an outage.
- **Staff see it** under **Delivery** on `?view=inquiries&msg=<id>`: a
  badge in words, the address it tried, what the mail server said, and
  **Send it again** for anything not sent. The list badges only the
  messages in trouble — a badge on all twenty is what hides the one that
  matters. A landlord is shown none of it.
- The staff list is **paged** (`ipage`, 20 a page, reusing the landlord
  inbox's `.inbox-pages`) and states the total. It drew a flat twenty with
  no pager and no count until 23 Sep, so a message past the first twenty
  was unreachable — which defeats the badge exactly: the one nobody can
  reach is the one whose failure nobody can act on.
- **Send it again** shows a disabled "Sending…" while it works. The label
  lives in `data-sending` and the handler reads it: written into the
  `onsubmit` attribute with `wp_json_encode`, its double quotes closed the
  attribute and left the rest of the script loose in the tag — a broken
  handler and broken markup that still looked right on screen. The button
  is a plain submit, so it works with JavaScript off (verified by clicking
  it with scripting disabled).
- **Send it again is `Notifications::resend()`**, which resets the counter
  and re-reads the address from the home — the usual reason a row is there
  at all is a wrong address, and by now somebody has corrected it. Staff
  only, POST-redirect-GET, with a 30-second lock so a second press cannot
  send a second email.
- **Copies** are off by default: Email delivery → *Copies of inquiry
  emails* (`tdh_inquiry_admin_copy`, `tdh_inquiry_admin_copy_to`).
  Switching it on with a blank or unusable address is **refused rather
  than stored**, or the screen would say copies were on while every one
  went nowhere. The address survives being switched off. A copy that fails
  while the landlord's own email succeeds leaves the row `sent` — the
  person who needed it got it — but logs `inquiry_copy_failed` as a
  warning, or copies could stop for a month with an empty mailbox as the
  only clue.

### The landlord's text, once they have asked for it (D4)

`TDH\Sms` is the second listener on `tdh_inquiry_received` (priority 20,
after the email). Nothing is texted until the landlord has asked for it on
**Profile → Text message alerts**: a number, the consent line ticked, and
the six-digit code we text them typed back. All of it is registered only
when `TDH_SMS_ENABLED` is true — without it the card is still drawn,
greyed, saying why texts are not available and that every enquiry still
arrives by email, with the number still editable (**Save number**); the
one hook that stays is the listener that resets a verification when
`_tdh_phone` changes. A card that appears and vanishes with a wp-config
constant reads as a bug.

- **Same table as D3**, `channel = sms`, one row per enquiry. The sweep
  and `send()` are each restricted to their own channel, so a phone number
  can never reach `wp_mail()` and an address can never reach Twilio.
  Booked through cron like the email; one retry at `RETRY_DELAY` (10 min),
  then `given_up`. The `admin_init` sweep keeps to those waits — a queued
  row after `QUEUED_GRACE` (2 min), a failed one only after the full ten
  minutes. Until the 23 Sep pass it swept failures after two minutes and
  spent the one retry early: the mistake D3's sweep had made before it.
- **Who gets no row at all:** a landlord who never opted in, or who turned
  texts off on the card — an enquiry about their home looks exactly as it
  did before D4; "not texted" is only news when they asked for texts and
  something stopped them. **Who gets a
  `skipped` row** (the reason in `error`): opted out, number no longer
  verified, no provider configured, or more than `RATE_LIMIT` (20) texts
  in the hour — a bot hammering one listing must not run up the bill or
  wake the landlord every minute.
- **The body** is `New ThirtyDayHomes inquiry for {home}. View it: {link}
  Reply STOP to opt out.` — the home and a link to
  `?view=inquiries&msg=<id>`, never the renter's name, phone or words.
  **Kept to one segment:** the home's name is cut with `...` to fit
  `SEGMENT` (160) after the fixed words and the link, and brought back to
  plain punctuation first. `Inquiry::about()` returns text now rather than
  the title filter's `&#8217;` — which D3's email subject was carrying too
  — and `Sms::plain()` turns curly quotes and dashes into keyboard ones,
  because one curly apostrophe switches a text to the 70-character
  encoding and two segments. The carrier rules (R45, R46) want STOP on
  every message and the privacy page to say what we do.
- **Verification.** `send_code()` texts a code that is stored only as an
  HMAC (`wp_salt('auth')`), expires after `CODE_TTL` (10 min) and locks
  after `CODE_ATTEMPTS` (5) wrong tries; a new code no sooner than
  `RESEND_GAP` (60 s) and at most `RESEND_HOURLY` (5) an hour.
  `_tdh_sms_verified` holds the E.164 number that was confirmed, so
  `on_phone_changed()` can tell a real change from a re-save and resets
  consent and verification on a change from anywhere — the card, Details,
  wp-admin.
- **Provider.** `Sms\Twilio` (`TDH_TWILIO_SID`, `TDH_TWILIO_TOKEN`,
  `TDH_TWILIO_FROM` — a number or a Messaging Service SID) on the Messages
  API by `wp_remote_post`, no SDK. No constants on a non-production site →
  `Sms\Capture`, which writes the text to `%TEMP%\thirtydayhomes-sms\` the
  way mail is captured, so a local walk can read the code as a phone would
  show it. No constants on production → `skipped` rows saying so. Filter
  `tdh_sms_provider` lets a suite stand in a fake.
- **Test mode.** `TDH_SMS_TEST_RECIPIENTS` (or filter
  `tdh_sms_test_recipients`) makes every other number `skipped` **at send
  time**, not at queue time, and puts a notice in wp-admin so nobody
  wonders why a landlord was never texted. This is how live runs between
  carrier approval and the first real landlord.
- **Webhooks.** `POST /wp-json/tdh/v1/sms-inbound` — STOP words pause
  (`_tdh_sms_opted_out`), START words resume, anything else is ignored,
  empty TwiML back — and `/sms-status`, where undelivered or failed marks
  the row `given_up`. Both refuse anything without a valid
  `X-Twilio-Signature` with 403 (`tdh_sms_webhook_trusted` lets a suite
  bypass). A landlord who texted STOP sees it on the card. **The block
  lives at the carrier**, so setting up the *same* number again is refused
  with the reason (`STILL_PAUSED`); only START, or a different number,
  lifts it — a number change clears the old number's pause along with its
  verification. If the STOP never reached the webhook, Twilio's refusal of
  the next send (error 21610) is treated the same way: the landlord is
  marked opted out and the row is `skipped`, rather than failed and
  retried for every enquiry from then on.
- **Delivery reports** log only what matters: `delivered` as one line,
  `undelivered`/`failed` as an error that also turns the row `given_up`.
  Twilio's queued/sending/sent steps are ignored — three lines per text.
- **Staff see it** as one more line under Delivery — Texted · Text queued ·
  Text failed — trying again · Text failed · Not texted — and nothing when
  SMS is off. There is no "send it again" for a text: a landlord who wants
  the message has the email and the dashboard.
- **The card is one form per state, so there is always exactly one primary
  button** and it says the next thing to do. Not set up: number, consent,
  **Send code**. Code sent — check your phone: "Sent to (412) 555-0184.
  Wrong number? Correct it below…", a code field marked
  `autocomplete="one-time-code"`, **Verify**, then "Send a new code" as a
  link and a "Wrong number?" field with **Use this**. On: "Texts go to …
  · verified", **Turn texts off** and a change-number field. Number
  verified, alerts off: consent and **Turn texts on** — the number is
  still verified, so no new code. Paused — you replied STOP: the hint says
  so and the set-up form is offered again. A wrong code keeps the field; a lapsed
  nonce says the page expired and changes nothing; logged out goes to the
  login page. **Send code, Use this, Change and Send a new code** — the
  four that wait on the gateway — go quiet and read "Sending…" the moment
  they are pressed (`Account_Render::sending_attrs()`, D3's lesson);
  Verify and Turn texts off ask nobody and stay live. Verify pressed twice
  says "confirmed", not "expired".
- **The link, on a phone that is signed out.** The account page shows
  *Please sign in* with a return link to that exact page, and the login
  form carries it through. `sign_in_wall()` built the link as `home_url()`
  plus the request path, which doubled the site folder on a sub-directory
  install (`/thirtydayhomes/thirtydayhomes/account/…` on localhost) and
  sent the landlord to a 404 after signing in; it is built from the host
  and request now. Live, at the domain root, was never affected.

### Landlord: membership (Stripe)

1. Pricing page → POST `tdh_action=tdh_checkout` with `tdh_listings` (1–3).
2. `Checkout::maybe_start` checks sign-in, nonce, role, not already
   subscribed, plan exists, Price configured → creates a Stripe Checkout
   Session (subscription) → redirect to Stripe.
3. Return to `account?tdh_checkout=success`. **The return grants nothing.**
4. Stripe calls the webhook; signature verified; subscription re-fetched;
   `Membership::apply()` writes status, plan, quota, expiry.
5. Dashboard shows status, plan, renewal, "X of Y listings", and "Manage or
   cancel membership" (Stripe customer portal) for active/past-due.

### Landlord: a payment fails, homes hide, homes return (E1, `TDH\Enforcement`)

1. **Payment fails** (webhook → `past_due`): the grace clock starts once
   (a later retry never restarts it). Nothing is hidden for
   `Visibility::GRACE_DAYS` (7). Every dashboard view shows the red band:
   "Your N homes stay visible until DATE. Update your card and nothing
   changes." → **Update your card** (straight to the Stripe billing portal
   when the landlord has a Stripe customer; otherwise the membership
   screen).
2. **Paid inside grace**: the clock clears; nothing was ever hidden.
3. **Grace runs out**: the daily job moves every live home to
   `tdh_billing_hold` (content, meta, photos, terms untouched; `_tdh_held_at`
   stamped). Paused, in-review and draft homes are left alone. Band: "Your N
   homes are hidden until a plan is active. Nothing is deleted…" Rows read
   "Hidden — Payment" with Manage billing. Renters get a 404; search drops
   them. Any live row that slips through is still hidden by
   `tdh_inactive_member_ids`.
4. **Ended plan** (expired, or cancelled past its end date — even without a
   webhook): no grace, hidden at the next check; the band offers **Restart
   a plan** (pricing). A cancelling landlord sees "Your membership ends
   DATE…" until then.
5. **Payment succeeds**: exactly the held homes go back live (a home the
   landlord paused stays paused); the next dashboard view says once
   "Payment received — your N homes are back online."
6. **While unpaid**: the wizard refuses Submit (the draft is kept); staff
   cannot approve the landlord's pending home (`Listing_Actions::approval_blocked()`; a home with no landlord is never blocked) — the queue row reads
   "Membership inactive" with Approve disabled, the preview bar offers only
   Request changes, and a forged approve returns `owner_inactive`.
7. **More homes than the plan covers** (R40, `Membership::usage()`): "7
   homes · plan covers 2", never "7 of 2 used"; My listings adds "Your plan
   covers 2 homes. To add another, delete 6 or move to a larger plan."
   Nothing is hidden for being over the plan.
8. Staff and administrators are never billed or held. Every step writes a
   line to the log: `grace_started`, `billing_hold`, `billing_restored`,
   `billing_move_failed`.

### Landlord: create a listing (the wizard, `[tdh_add_listing]`)

Gate (`Listing_Form::gate_reason()`): signin → role → staff bypass → plan
(quota < 1) → full (at quota and not editing an existing draft).

| Step | Collects | Rules |
|---|---|---|
| 1 The basics | title, private address, neighbourhood, city, ZIP, type, bedrooms, bathrooms · **Rent & fees**: rent, deposit, application, cleaning · **Inquiries**: contact name, email, mobile, preferred contact | Required fields all-or-nothing; optional fields partial-success (good values saved, bad ones named); contact prefilled from the listing owner; "Save draft" |
| 2 Features & amenities | **Availability** (A5): available from + unavailable dates · minimum stay · **Utilities** (required) + details · **Pets** (required) + pet fee (hidden and cleared for "Not allowed") · square feet, rooms, parking, backyard · amenities from the fixed catalogue | Everything valid is saved before missing answers are named; a refused period comes back in its row with the reason |
| 3 Photos & description | up to 10 JPG/PNG/WebP, instant previews; per photo: ‹ › arrows, "Make cover", a description (≤ 125 chars, stored as alt text), Remove tick; description ≤ 1500 chars | The first photo is the cover; removing the cover promotes the next; at 10 the dropzone becomes a note; HEIC and non-photos refused by name; arranging saves at once and shows "Photo order saved" |
| 4 Review & submit | every answer in five groups with Edit links; "A few things before review" list when incomplete; Fair Housing tick | Submit disabled while incomplete, and refused on the server too |

Leaving a step never loses what was typed: **Back (button and header arrow)
saves first**, skipping only the "unanswered question" check. A step opened
from the review's Edit link (`&review=1`) offers **"Save and return to
review"**. A double press cannot post twice. A page left open past its
nonce returns to **the step that was posted**, with the home, saying that
this step was not saved and everything before it is still there (it used
to land on step 1 and call a possibly live home "a draft"). The gate is
checked again on every POST, every reason included: a crafted step 1 at
the allowance creates nothing.

Submit → `pending`, `_tdh_fair_housing_ack_at` and `_tdh_submitted_at`
recorded, action `tdh_listing_submitted( id, resubmitted )` fires
(landlords only) and emails staff, redirect to dashboard. Landlords can
open draft, pending, **changes-requested**, **live and paused** listings
in the wizard (the note shows on every step of a sent-back home; step 4
says "Resubmit for review"). Live and paused homes follow the rules below.

### Landlord: editing a live home (task A4)

The wizard says "Edit listing", and steps 1–3 carry a note: what updates
at once, what goes back to review. Step 4 is "Check your listing" with an
"Everything is saved" card, Back to My listings and View live page — no
Submit (`Listing_Form::submit()` also refuses to move a live/paused
landlord home to pending). Staff keep the ordinary step 4 with Submit.

- **Material fields** — `Listing_Form::material_fields()`, filter
  `tdh_material_fields` (decision 8; Rob's answer changes one list):
  title, description, photos, street address, ZIP, city, neighborhood,
  property type, bedrooms, bathrooms. Everything else is minor.
- **Minor edit on a live home:** saves, stays live, next step shows
  "Saved. The change is live now." (`?updated=1`).
- **What decides is what was stored.** For a landlord's live or paused
  home, `guard_live_edit()` takes `material_snapshot()` before the step
  saves, and `finish_live_edit()` (from `go()` / `fail()`) compares it
  with the stored values after. Only fields that really changed count —
  so a form posted without a field, a refused upload, a title stored as
  `&amp;`, or a block-editor description posted back untouched can
  neither slip past review nor send an unchanged home to it. Text is
  compared via `comparable()` (sanitised, entities decoded); a term the
  home never had (e.g. the preselected city) is "filled in", not changed.
- **Asking first (live home):** `material_changes()` predicts the change
  from the POST the same way. Without `tdh_confirm_review` the POST is
  held in a transient (`tdh_lform_confirm_{user}`, 10 min) and the
  landlord sees `?confirm=1` — `confirmation()` names the fields ("You
  changed: Title and Bedrooms"), re-posts the answers as hidden fields.
  The hold survives a refresh (`held_confirmation()` reads, not takes);
  it is dropped when the confirmed POST arrives or any step is opened
  ("Go back and discard changes"). A step that `basics_errors()` would
  refuse is not sent for confirmation. Back to a used confirmation page
  says the change is already in review.
- **After the save (live):** real changes → `pending`,
  `_tdh_submitted_at`, `_tdh_reviewing_edits` (labels), action
  `tdh_listing_changes_submitted( id, labels, false )` → staff email
  "Edited home waiting for review", landlord lands on My listings with
  `tdh_done=in_review`. This also happens for a real change that arrived
  without confirmation — renters never see an unreviewed material change.
- **Photos on a live home:** files cannot be held, so step 3 shows a tick
  (`tdh_confirm_review`) that unlocks the photo controls (JS gate,
  `is-locked`; unticking clears chosen files); uploads without it are
  refused with the typed description kept for that home only
  (`stash_values( listing, … )` / `take_values( listing )`). Arranging or
  removing without the tick goes through the confirmation page. The
  description is written only when it reads differently.
- **Paused home:** edits save and it stays paused; really-changed labels
  accumulate in `_tdh_edited_while_paused`, the step notice and the row
  say Resume will review it first, and `Listing_Actions::resume()` then
  sets `pending` (`tdh_done=resume_review`, email "…while it was
  paused…").
- **Staff:** exempt — edits publish at once, status unchanged.
- **Lapsed membership:** the wizard gate already refuses (quota < 1), and
  `guard_live_edit()` refuses a confirmed change too.
- Approval clears `_tdh_reviewing_edits` and `_tdh_edited_while_paused`;
  Request changes and an ordinary submit clear `_tdh_reviewing_edits`.

### The two search widgets (task C5)

- **Acceptance criterion 9.** `Search_Results` ("Search results") and
  `Nearby_Facilities` ("Nearby hospitals") sit in the ThirtyDayHomes
  category beside the nine existing widgets, and both read live
  marketplace data rather than repeating it.
- **The results band works on any page.** On the listing archive it uses
  that page's own query, because that is the one the URL describes and the
  one pagination pages. Anywhere else — a landing page, and the Elementor
  editor, where a page is never an archive — it runs `Search::own_query()`,
  a query flagged with `Search::OWN_QUERY` so the identical keyword, date,
  hospital, price and sort rules apply. The flag is why `is_listing_query()`
  was widened rather than loosened: every other secondary query on the site
  stays untouched.
- **The hospitals band needs a home.** On a property page it measures from
  that home. With no home in context a **visitor sees nothing at all**, and
  the person in the Elementor editor sees the newest home that has a
  location, above a line saying it is an example (`Render::editor_note()`,
  which returns '' outside the editor). A widget that could only say "I
  will work somewhere else" is impossible to judge while building a page,
  and the contract asks for real data in the editor.
- **No duplication of the theme.** The results widget renders the theme's
  own `template-parts/listing-results.php` through `get_template_part()`,
  passing the editor's choices as `$args`. A copy of the markup here would
  let a page built in Elementor drift away from `/homes/`.
- **What the controls may and may not own.** Heading, intro, which bands
  to show, and — only where the widget runs its own query — how many homes
  a page. *Not* which homes, and not the sort: those are the plugin's, read
  from the URL, and a control that decided them would put marketplace
  behaviour inside a page layout, which the handoff forbids. On the archive
  itself `per_page` is ignored, because the page decides. The hospitals
  widget owns only the row count,
  clamped to the 1–5 staff may set. A test asserts neither widget offers a
  control matching distance, price, address or coordinates.
- **The scripts follow the block, not the template.** `filters.js` and, in
  map view, `map.js` are enqueued on any page `Search::page_holds_results()`
  recognises. Without that a widget page had the Filters button with no
  drawer behind it and a map panel stuck on "Loading the map" — a control
  that cannot work is worse than no control.
- **A page holding the results band takes the full width.** An ordinary
  page puts its content in a narrow reading column, and a wide block
  dropped into it is squeezed with no way for the person building the page
  to know why. `tdh_is_wide_body_page()` now asks the filter
  `tdh_wide_body_page`, and the plugin answers yes when the page's content
  or its `_elementor_data` mentions the results band. The theme keeps the
  decision; the plugin supplies the fact about its own block.
- **The shortcodes are the primitive**, as everywhere else:
  `[tdh_search_results]` and `[tdh_nearby_facilities]`, both listed in the
  admin shortcode reference. With Elementor switched off they still work.
- `Proximity::list_html( id, ?count )` returns what `render_list()` prints,
  so the property page and the widget share one copy of the markup.

### Maps (task C4, `TDH\Maps`)

- **The rule this module exists to keep:** the exact point never leaves
  the server. Not in the page source, not in a data attribute, not in a
  REST response, not inside a hidden element. A furnished home stands
  empty between stays; publishing its address says which door is unlocked.
- **What a browser gets:** `Maps::approximate( id )` — the real point
  moved 80 to 250 m in a direction taken from `wp_hash()`, then rounded to
  three decimals (about 110 m). The page draws a **circle** of 400 m, a
  quarter mile, which is wider than the error, so the home is plausibly
  anywhere inside it. Never a pin.
- **Why the offset is salted.** The task card asked for an offset derived
  from the listing id. On its own that is reversible by anyone who reads
  the code, which would hand back the address the feature hides. `wp_hash`
  mixes in the site's own salts: unguessable from outside, identical on
  every request, so a circle never wanders between page loads.
- **Facilities are exact and deliberately so.** A hospital is a public
  building and its coordinates are public meta already.
- **The key:** `TDH_MAPS_BROWSER_KEY` in `wp-config.php`, read only by
  `Maps::key()`. Unlike the server key this one is printed into the page;
  Google's referrer restriction is the only thing protecting it, so it
  must stay restricted to this site.
- **List or map:** `Search::view()` reads `view=map` and is **not** a
  filter — no chip, no Filters badge, and it survives Clear all. It rides
  in `args()`, so every chip, page link and widened radius keeps the
  renter where they were. With no key the view is always the list and no
  toggle is drawn.
- **In map view the cards are still rendered** and hidden with
  `.is-behind-map` (clipped, not `display:none`), so a screen reader and
  a failed map both still have the whole list.
- **When the map cannot load** — no key, a key refused, blocked or offline
  — `map.js` empties the panel and writes our own sentence plus a "Show
  the list" link. It empties it first on purpose: Google paints its own
  English "Oops! Something went wrong — see the JavaScript console" over
  the container, which is written for a developer.
- **How it looks:** the base map is styled down — businesses and transit
  off, the site's cream as the ground, hospitals and parks kept — so the
  homes are the only gold on screen. Each home is a wide faint halo plus a
  tighter ring, and a navy price tag drawn as a custom `OverlayView`
  rather than a Google marker, because a marker is a pin and an overlay
  takes the site's own type and colours. Clicking a tag or a ring opens a
  card with the name, place, price and a link.
- **Counts:** at most `MARKER_CAP` (100) circles, and the page says
  "Showing 100 of 214". Homes with no point are counted in a second
  sentence and stay in the list.
- **Tested by `verify-privacy.php`**, which renders a real property page
  through its own template loop and asserts the exact latitude, longitude
  and street address are absent. Note the trap it caught: calling
  `the_post()` before including the template leaves `have_posts()` false,
  the loop never runs, and every assertion passes against an empty page.

### A typed place stays in the service area (R50, `TDH\Area`)

A place typed in the search box is looked up with Google's `bounds` set to `Area::service_bounds()` — the box around the active facilities, padded about 25 miles (Pittsburgh when there are none) — and an answer outside that box plus about 35 miles is refused (cached as `outside_area`) so the words are searched instead. "Oakland" and "Lawrenceville" find the Pittsburgh homes, never Oakland, CA. Cache keys are `tdh_area_2_*`; the old `tdh_area_*` answers are never read.

### Search by hospital (task C3, in `TDH\Search`)

- **The URL:** `facility` (a facility post id), `within` (miles, or `any`)
  and `sort=closest`. The radius is left out of the URL when it is simply
  the staff setting, so a shared link stays short and the default can
  change later without old links disagreeing with new ones.
- **What a chosen hospital does:** filters to the homes within the radius
  **and** makes "closest" the order, because that is the whole point of
  choosing one. A sort the renter picks afterwards still wins. Asking for
  `sort=closest` with no hospital falls back to newest and the page says
  why (`Search::sort_notice()`); it never reorders nothing in silence.
- **The distance is to the chosen hospital**, not to each home's own
  nearest one. That was the demo's defect (`DEVELOPMENT_PLAN.md` §6) and
  the reason the feature exists. `Search::near_facility_ids()` measures
  with `Proximity::miles()`, sorts, and hands the ordered ids to
  `post__in` with `orderby => 'post__in'`, so the sort costs no second
  query. `Proximity::render_band()` asks `Search` for the choice, so the
  card band and the order can never disagree.
- **Un-geocoded homes:** with a radius they are left out — a home listed
  under "within 15 miles" with no distance is a claim we cannot make.
  With `within=any` they follow at the end, in their previous order, with
  no band. Never "0.0 mi".
- **Which hospitals are offered** (`Search::facilities()`): the same query
  the property pages use (`Proximity::facilities()`, filter
  `tdh_facility_query_args`), minus any without coordinates, grouped by
  the `tdh_city` taxonomy so two of one name in two cities are told apart.
- **The radius control** is disabled until a hospital is chosen and says
  so in its own label, rather than sitting there doing nothing.
- **Empty:** "No homes within 15 miles of UPMC Mercy." plus a primary link
  naming the next radius out ("Look within 25 miles instead"), and at the
  widest step "Look at any distance instead".
- **The keyword** matches hospital names too (`matching_ids()` lookup 4),
  so the hero's "Neighborhood, ZIP, or hospital" is finally true: typing
  "Mercy" returns the homes within the staff radius of it.

### Date search (task C2, in `TDH\Search`)

- **The URL:** `start` and `end`, the hero's own field names, so the dates
  typed on the front page survive the jump to `/homes/`. Both are also two
  fields at the head of the filter bar ("Move in", "Move out"), filled from
  `Search::typed()` — what was typed, not what survived validation, so a
  refused date is still there to be corrected.
- **Who decides:** nobody re-implements "free". `Search::free_ids( start,
  end )` asks `Availability::is_free()` once per published home and hands
  the answer to `post__in`, intersected with whatever the keyword already
  narrowed (empty → `[0]`, never every home). It cannot be a meta query:
  the taken periods are lines of text and the turnover rule lives in
  `fits()`. Over `DATE_SCAN_LIMIT` (500) homes it still answers correctly
  and writes a `listings/date_scan_large` warning to the log — the signal
  to move availability into its own table.
- **Open-ended:** a move-in with no move-out asks each home whether it is
  free from that day for **its own** minimum stay, or the site's, whichever
  is longer. A fixed 30 would hide every home that only takes longer lets.
- **Refused dates** (`Search::stay()`, said by `date_notice()`): a move-out
  with no move-in (nothing filtered, the missing date is asked for); a
  move-in in the past (moved to today, and said); a stay wholly in the past
  (refused); a move-out on or before the move-in (refused); fewer than
  `Search::MIN_STAY_DAYS` (30) nights (refused, with the rule); a date past
  `Availability::horizon()` (refused, with the furthest date). A date that
  is not a date is ignored like any other nonsense. A refused stay never
  empties the page and never narrows it silently.
- **The words:** the count reads back the dates ("2 homes free 1 Nov –
  5 Dec 2026"), the empty sentence carries them ("No homes over $90,000
  free 1 Nov – 5 Dec 2026."), and the two dates are **one** chip and count
  as **one** filter on the Filters badge, because to a renter they are one
  stay. Removing that chip removes both.
- **Beyond the results:** a card in a dated search says "Free for your
  dates" instead of its first free day, and carries the dates on its link;
  `template-parts/listing-availability.php` then repeats the stay above the
  calendar with a tick or a cross — re-checked there, never trusted from
  the URL.

### Availability (task A5, `TDH\Availability`)

- **Stored:** `_tdh_available_from` (blank = free now) and
  `_tdh_blocked_ranges` (plain lines, see §5). `ranges()` reads them
  forgivingly: a line that is not two real dates in order is skipped,
  overlaps are joined — so a hand edit in the wp-admin box cannot break
  anything.
- **One rule for "free":** `fits( ranges, available_from, min_stay, start,
  end )` — start is move-in, end is move-out, and the move-out day is not a
  night, so a stay may end on the first day of a taken period.
  `is_free( id, start, end )` wraps it; date search (C2) should call it.
- **First move-in:** `first_move_in()` = the first day from today or
  available-from (whichever is later) that fits the minimum stay, skipping
  taken periods and gaps too short; '' when nothing fits in 3 years.
  `summary( id )` turns it into words: "Available now" / "Available from
  11 Nov 2026" (card: "Available 11 Nov") / "No open dates right now", plus
  upcoming and past periods.
- **Typing rules** (`check()` / `check_from()`): backwards, half a period,
  not a real date, or more than `HORIZON_YEARS` (3) ahead → refused and
  named; past → kept as history (marked "Past" for the landlord, never shown
  to renters); single day = the same date twice; overlapping or touching →
  joined and said ("…were joined into one: 10 – 31 Dec 2026"); at most
  `MAX_RANGES` (20), the extra ones refused by name.
- **Saving:** `save_posted( id )` is shared by the wizard's step 2 and the
  quick edit. Fields absent from the POST are left alone (`tdh_available`,
  and the rows only when `tdh_availability=1` is posted). Good periods are
  stored, refused rows are kept for the form (`take_kept()`), notes about
  joins are said on the next page (`take_notes()`).
- **One editor, two doors** (`TDH\Availability_Render`): "Available from"
  plus rows of First day / Last day / Remove, one empty row always at the
  end (works without JavaScript: fill it to add, clear a row to remove);
  the script adds "Add another period", Remove, the live count "3 of 20
  periods", focus moves, and stops at 20.
- **Quick edit on My listings:** live, paused, in-review and
  changes-requested rows get an **Availability** link beside Edit
  (`?availability=ID`, a server-rendered panel like Delete): what renters
  see now, the editor, **Save availability** / Cancel. POST
  `tdh_action=listing_availability` → `Availability::handle()`: owner or
  staff (someone else's or a deleted home → `tdh_done=missing`); an expired
  page saves nothing and keeps every typed date (`replace`); partial
  success reopens the panel with the reasons and "Everything else is
  saved"; success → `tdh_done=availability` ("Availability for “…” is saved.
  Renters see: Available from …"). A draft (in the wizard already) and a
  payment-held home have no quick edit. Availability is never a material
  field: a live home stays live.
- **Property page:** `template-parts/listing-availability.php` — the
  headline and minimum stay, "Unavailable: …" (upcoming only), a legend in
  words, and 12 server-rendered month tables in a sideways-scrolling row
  (two in view on desktop, one on a phone; `contain: inline-size` keeps it
  from widening the page); `assets/availability.js` adds Previous / Next.
  Taken days are hatched and struck through, with "unavailable" for screen
  readers; today has `aria-current="date"`. The price box's "Available" and
  the card's line use the first move-in day.

### Landlord: managing a listing (task A3)

`TDH\Listing_Manage_Render` draws "Your listings" (overview: 5 rows +
"View all N homes"; My listings: 10 a page, `?listings_page=`). Every row:
photo, name, status chip, rent and place, **one line in words** and **one
primary action**:

| State | Line | Primary | Also |
|---|---|---|---|
| Draft | "Not submitted yet. N answers still needed." / "Complete…" | Continue editing / Review and submit | Preview, Delete |
| In review | "Submitted 17 Sep. A person reviews every home…" | Preview | Edit, Availability, Delete |
| Live | "Live since 5 Sep. Renters can find it." | View live page | Edit, Availability, Pause, Delete |
| Paused | "Paused on 15 Sep. Hidden from renters until you resume it." | Resume (or Manage billing if the membership lapsed) | Edit, Availability, Preview, Delete |
| Changes requested | "Our team asked for changes…" + the note | Edit and resubmit | Preview, Availability, Delete |
| Hidden — payment | "Hidden because a payment failed…" | Manage billing | Preview, Delete |

`TDH\Listing_Actions` handles `listing_pause` (live → `tdh_paused`,
`_tdh_paused_at`), `listing_resume` (paused → live, **no second review**,
`_tdh_live_since`; refused unless the owner is active, cancelling-in-period
or staff) and `listing_delete` (→ trash). Owner or staff; someone else's id
gets the "missing" answer; repeats are harmless. Outcomes return as
`?tdh_done=<flag>&tdh_home=<id>` — **never `tdh_listing=`, which is the
listing post type's query var and turns the page into a 404.**

Delete asks first **in the page** (`?delete=ID` renders the panel: name,
what is kept, "Delete listing" in danger colour, "Keep it"; focus moves in,
Escape cancels). Trashed homes vanish from the landlord's list and free an
allowance slot; their inquiries stay in the inbox. Staff restore from
wp-admin → Listings → Trash (WordPress restores it as a draft).

### Listing photos (`TDH\Listing_Photos`)

- **Order** lives in each attachment's `menu_order` (1, 2, 3…). `0` means
  "never arranged" and sorts after arranged photos, so a wp-admin upload
  never silently becomes the cover. `ordered()` is the one reader.
- **Cover = the first photo.** Every change calls `sync_cover()`, which
  sets `_thumbnail_id` to the first photo or deletes it when none are left
  (the card then shows "Photo coming soon"). Listings photographed before
  A2 keep their old cover first until the landlord arranges them.
- **Descriptions** are WordPress's own `_wp_attachment_image_alt`.
- **Every upload is re-drawn before WordPress stores it**
  (`prepare_upload()`): turned upright from the EXIF flag, longest side
  ≤ 2000 px, re-encoded (JPEG/WebP 82, PNG 6). Re-encoding drops all
  metadata, including **GPS, which would publish the home's address**.
  HEIC/HEIF and > 40 MP are refused with a named message.
- Property page: `template-parts/listing-gallery.php` rejects stale,
  non-attachment, non-image and unrenderable IDs before it counts. It shows
  a 60/40 cover + 2×2 desktop mosaic, cover + two previews on tablets and the
  cover only on phones. **Show all N photos** opens a native `<dialog>` at
  the chosen photo; one photo prints no dialog/count/navigation. The viewer
  keeps every valid photo in landlord order, says **Photo X of N**, keeps its
  caption with the photo, stops at both ends, supports arrows/Home/End/Escape
  and returns focus to the exact opener (`assets/gallery.js`). Without the
  script, up to five real previews remain and dead controls stay hidden.

### Staff: the marketplace portal (`/account/` when `is_staff()`)

| `?view=` | Shows |
|---|---|
| (overview) | Tiles (active members, live, pending, recent inquiries), approval queue, membership health. When work is waiting, the first tile says **Pending approval**, shows the count once, and has a separate **Review now** button; with zero waiting it has no button |
| listings | Status filter; the maps-service notice (B1); "Waiting for approval" with **Approve** / **Request changes** (opens a required note under the row, `?request_changes=ID`); all listings, 20 a page (title opens the wizard). Rows needing a location show a chip + **Set location** (`?locate=ID`, form under the row) |
| listing-setup | Vocabulary counts; management disabled ("Milestone 2") |
| members | Add member; per-member edit (status, plan, quota, expiry), reset password, delete |
| facilities | Add / edit / delete medical facilities; coordinates fill from the address (B1), typed ones override; delete asks in the page (`?delete_facility=ID`) |
| inquiries | Latest 20 inquiries; name links to wp-admin |

Moderation (`TDH\Moderation`): `listing_approve` → `publish`, stamps
`_tdh_approved_at` / `_tdh_approved_by` / `_tdh_live_since`, clears the old
note, action `tdh_listing_approved` — **refused while the address was not
found** (`Geocoder::blocks_approval()`, `tdh_moderated=location`, B1);
`listing_changes` **requires
`tdh_reason`** (≤ 1000 chars, stored in `_tdh_rejection_reason`, refused
with `tdh_moderated=reason` when empty) → `tdh_rejected` + action
`tdh_listing_changes_requested`. Capability checked before the nonce.
**Both act only on a `pending` home** (`tdh_moderated=not_pending`
otherwise, 23 Sep 2026): a queue page can be open for a while, and a
stale Approve used to set a trashed post to `publish` — the home the
landlord had deleted came back live — or re-stamp "Live since" on a live
home, while a stale Request changes took a live home down.

### Map locations (task B1, `TDH\Geocoder`)

- **When:** whenever an address really changes, from anywhere (listing
  form, Facilities, wp-admin, import). The address fields are watched as
  they are written (`added/updated/deleted_post_meta`, `set_object_terms`
  for the city) and looked up once at `shutdown` (`Geocoder::flush()`,
  also called directly by the facility save so its message can say how it
  went). A lookup never blocks or fails a save. An unchanged address is
  never looked up again (`_tdh_geocode_address` fingerprint). A record
  still waiting ("not checked") is retried on its next save
  (`save_post_*`), or in bulk: `wp tdh geocode [--all] [--type=] [--dry-run]`.
- **Provider:** `Geocode_Provider` interface; `Geocode_Google` with
  `TDH_MAPS_SERVER_KEY` (wp-config only; never printed, logged or stored).
  Filter `tdh_geocode_provider` (tests use a fake). Only the building
  counts: ROOFTOP / RANGE_INTERPOLATED, or GEOMETRIC_CENTER for a building
  or campus; APPROXIMATE (town, ZIP) and a street's middle are "imprecise".
- **States** (`state()`): found (ok) · manual (typed, or legacy points from
  before B1) · failed (not_found / imprecise — the old point is dropped) ·
  pending (no key, service down, waiting). Only **failed** blocks approval
  (queue button disabled with the reason, preview bar offers Set location
  instead, server refuses); pending never does.
- **Typed points win:** a coordinate that changes as a NUMBER (the
  `update_post_metadata` filter compares) marks the record manual; the
  same number re-saved does not; clearing both looks the address up. A new
  address replaces a typed point.
- **Errors:** "slow down" or a timeout pauses lookups for 60 s
  (`tdh_geocode_pause`); denied/limit are kept for the staff notice
  (`tdh_geocode_problem`). An address change that gets no answer drops the
  old point rather than keep it for the wrong address.
- **Set location** (staff, `tdh_action=listing_location`, nonce
  `Geocoder::NONCE`): one box, coordinates as Google Maps copies them
  ("40.4406, -79.9959"; `parse_point()` accepts spaces, degrees, brackets;
  refuses words, out of range, 0,0 — typed text kept). "Look up the
  address again" (`listing_geocode`) only when a key exists. Outcomes
  `tdh_located=<flag>&tdh_home=ID` (words in `Geocoder::notice()`).
- **Landlord:** a "not found" address is said on the listing form (every
  step, "Check the address" back to step 1); nothing else changes for them.
- **Distances:** `Proximity` reads `Geocoder::coordinates()`, so an empty
  or 0,0 point never produces a distance.

### Nearest hospitals (task B2, `TDH\Proximity`)

- **What a renter sees:** the listing card keeps its one-line band ("1.3 mi
  from AHN Allegheny General", nearest facility, no radius). The property
  page's "Close to care" section lists the nearest few under the map note:
  a lead line ("3 medical facilities within 15 miles"), then name, type in
  words and distance per row.
- **Three states:** a home with no location prints nothing (the map note
  still stands); a home with a location and nothing in range says "No
  medical facilities within 15 miles of this home."; otherwise the list.
  It never falls back to showing every facility, and never prints "0.0 mi"
  for a missing coordinate.
- **Which facilities count:** published, `_tdh_active` not '0', and with a
  real point. The query is filterable (`tdh_facility_query_args`).
- **The numbers:** `Proximity::nearest_n( id, count, radius )`; defaults
  from options `tdh_proximity_count` (3) and `tdh_proximity_radius` (15),
  set by staff on **Listing setup** (`tdh_action=proximity_save`, nonce
  `tdh_proximity_save`). 1–5 facilities, 1–100 miles; out-of-range values
  are refused with the limit named, and clamped to the default if anything
  else writes them. Exactly at the radius counts as within it.
- **Cache:** `_tdh_nearest_facilities` on the listing — up to 10 rows,
  closest first, more than any page shows, so changing either setting
  rebuilds nothing. Dropped when a listing or facility is saved or
  deleted, and when a coordinate is written outside a save (the geocoder's
  own writes and `wp tdh geocode`). Cleared through `delete_post_meta()`
  per post, not one DELETE, so the object cache cannot serve rows the
  database no longer has.
- **Distance:** Haversine, one decimal under ten miles and none above —
  "12.3 mi" implies a precision the geocoding does not have. No drive time
  (decision 9: distance only).

### The event and failure log (R42b, `TDH\Log`)

- **What it is:** one table, `{prefix}tdh_log` (time in UTC, level, feature,
  event, message, masked context JSON, user, object type + id, source
  `file:function`, request id), one writer `Log::write()` with
  `debug/info/warning/error/critical()`, and the staff screen
  `/account/?view=logs`.
- **How lines get there:** mostly by listening. `Log::register()`
  subscribes to the actions the features already fire — `tdh_listing_*`,
  `tdh_geocoded`, `tdh_membership_changed`, `tdh_stripe_received/rejected`,
  `tdh_contact_received`, `tdh_mail_captured`, `tdh_login_throttled`,
  `tdh_proximity_settings_saved`, and WordPress's `wp_login_failed`,
  `wp_login`, `user_register`, `profile_update`, `deleted_user`,
  `wp_mail_failed`, `wp_mail_succeeded`, `save_post_tdh_facility`,
  `deleted_post`. The sentence lives in `Log`; a feature only fires what it
  already fires. Anything may also call `Log::info()` directly.
- **What never goes in:** context keys that look like a key, token,
  password, signature or cookie become `[hidden]`; values shaped like a
  Stripe, Google or Twilio key too; emails and phones are masked
  (`f***@domain`, `***1234`); the visitor's IP drops its last octet.
  Objects become their class name, `WP_Error` its message. A message over
  255 characters is cut and kept whole in the context.
- **It never gets in the way:** a failed write is swallowed and counted
  (`tdh_log_failed_writes`), never thrown.
- **The screen:** wp-admin → **Listings → Logs**
  (`edit.php?post_type=tdh_listing&page=tdh-logs`, `Admin\Log_Screen`),
  beside Email delivery, Payments and Security — **not** in the
  marketplace portal, which is the client's daily surface (reviewer's
  decision, 20 Sep 2026). Administrators only (`manage_options`), like
  those screens. Native admin markup: nav tabs, a striped table, the
  button styles, one small scoped `<style>` block. One tab per feature
  (Listings, Locations, Facilities, Inquiries, Email, SMS, Payments,
  Members, Sign-in, Settings, System) plus **System status**; filters Show
  (everything / warnings and errors / errors only), Period (24 h / 7 / 30
  / 90 days) and Search, all GET so a view can be linked and Back restores
  it; 50 rows a page; **View** opens the context with `<details>`, no
  script. The menu item carries WordPress's own red bubble with the errors
  of the last 7 days; each tab its count. Empty state says "Nothing logged
  for these filters".
- **Silent during tests:** `verify.bat` and CI set `TDH_LOG_SILENT=1`, and
  `Log::write()` writes nothing while it is set — the suites create and
  delete hundreds of fixtures, which are not events on the site. The log's
  own suite clears the variable for itself.
- **System status:** environment and review mode; Google Maps key and the
  last lookup or refusal; email (captured on non-production, last sent,
  last error); SMS from the same settings as the landlord's card (switched
  off / Twilio details missing / written to a file off production / test
  mode naming its numbers or live, the last text sent, a newer failure as
  Needs attention — 0.24.5; it said "not built yet" until then); Stripe webhook (mode, last event, last
  refusal); this log (rows, last and next clean-up, failed writes).
- **Retention:** daily `tdh_log_purge`, 90 days, batches of 1000; the run
  logs what it removed.
- **Who sees it:** administrators, in wp-admin. The portal has no Logs
  view or link; a landlord cannot reach the screen at all.

### Previewing a home that is not public

Staff clicking a waiting home (approval queue) or a landlord opening their
own draft/pending home get `?post_type=tdh_listing&p=ID&preview=true`.

- `Visibility::permits_preview()` lets the **main query of a single-listing
  preview** through only for someone who can `edit_post` that listing (its
  landlord or staff). Everyone else gets not-found.
- `TDH\Listing_Preview` prints a sand bar above the page (theme hook
  `tdh_listing_preview_bar`): the status, who can see the page, what
  happens next, and the next action — **Approve / Request changes** for
  staff on a pending home; for the landlord **Edit listing / Continue
  editing**, **Resume** on a paused home (or Manage billing), **Edit and
  resubmit** with the note on a changes-requested home. No bar on live
  listings. Previews are `noindex`.
- A home that was live keeps its pretty address, so its preview link is
  `/homes/name/?preview=true` with no id; `permits_preview()` finds it by
  name.
- Anyone without access sees the theme's designed `404.php`: "This home
  isn't available right now", Browse homes, homepage, search bar. It never
  reveals why a home is unavailable.

### Contact page

`tdh_action=tdh_contact`: topic chips, name, email, phone, message ≤ 5000,
honeypot, 5 per hour per IP. **Stored first** as a `tdh_inquiry`
(kind `contact`), then emailed to the site admin address with Reply-To the
sender; `_tdh_notified` records sent/failed. Fires `tdh_contact_received`.

### Listing views

A JS beacon on single listings posts to `/wp-json/tdh/v1/listing-view`
(counted once per session, not for the owner or staff). Needed because
LiteSpeed serves cached pages without running PHP.

---

## 8. Plugin file map (`plugins/thirtydayhomes-core/includes/`)

| File | Class | Owns |
|---|---|---|
| class-tdh-core.php | Core | Module wiring, `maybe_upgrade()` |
| class-tdh-activator.php | Activator | Activation, tables, roles install |
| class-tdh-post-types.php | Post_Types | 3 post types, 4 taxonomies, constants for their slugs |
| class-tdh-statuses.php | Statuses | Custom statuses, `all()`, `public_status()` |
| class-tdh-fields.php | Fields | Meta schemas, registration, sanitizers, `private_keys()` |
| class-tdh-roles.php | Roles | Landlord role, admin caps, inquiry ownership |
| class-tdh-membership.php | Membership | Status/plan/quota/expiry getters, `apply()`, `listing_count()`, `usage()` ("3 of 5 used" / "7 homes · plan covers 2", R40) |
| class-tdh-visibility.php | Visibility | Public query rule, `is_public()`, REST private-meta strip, `GRACE_DAYS = 7` |
| class-tdh-email-verification.php | Email_Verification | F1: `is_verified()`, `start()` (on sign-up and a landlord's own email change), `follow()` (the link: confirmed / already / expired / invalid), `maybe_resend()` with `resend_block()`, `band()` and `sentence()` for the dashboard and pricing page |
| class-tdh-enforcement.php | Enforcement | Lapsed memberships (E1). `state()` (ok / grace / hold; staff always ok), the grace clock in user meta `_tdh_grace_started`, `enforce()` on `tdh_membership_changed` (hides live homes into `tdh_billing_hold` with `_tdh_held_at`, restores exactly those on payment), the daily `tdh_enforce_memberships` job (`run()`, or `run( [ids] )` for tests), `inactive_member_ids()` for the visibility rule, `band()` (the dashboard sentence and its one action) and `take_restored()` (the one-time "back online" message) |
| class-tdh-proximity.php | Proximity | Haversine miles, cached nearest facilities (list), card band, the property-page list, count and radius settings (B2); `usage_counts()` — how many live homes list each facility, for the Facilities screen (G5a) |
| class-tdh-log.php | Log | The event and failure log: writer, masking, query, counts, purge, System status, and the subscribers that turn every module's actions into lines (R42b) |
| admin/class-tdh-log-screen.php | Admin\Log_Screen | wp-admin → Listings → Logs: the features as six grouped tabs (`groups()`; a Section select on phones; a feature key in `?tab=` still works), filters, warnings first when the period has any (with a lead line), the striped table whose line opens its own context, times in Eastern Time (`zone()`, filter `tdh_log_timezone`), stored status keys read as words (`words()`), System status; administrators only (R42b, G5b) |
| class-tdh-search.php | Search | The search: keyword (`?q=`), filters (`min_price`, `max_price`, `beds`, `baths`, `type`, `pets`), dates, hospital + radius, sort; chips, Clear all, the count and empty-state words; runs on `/homes/` and the listing taxonomy archives (C1). `near_point_ids()` is the one distance measurement behind both anchors, `anchored()` / `anchor_label()` say whether there is one and what it is called (C3, C6) |
| class-tdh-inquiry.php | Inquiry | The renter's enquiry about one home (D1): the `tdh_inquire` handler, validation, the honeypot, per-IP rate limit and the ten-minute same-person-same-home window, `store()`, the form, and the success screen that replaces it. Fires `tdh_inquiry_received` after the write, which is where `Notifications` (D3) and D4 hang. Refuses by **state** (the home must be public at the moment of the POST), never by identity. **D2** adds the reading side: `can_read()` (ownership by the home's author; staff see all; a Contact-page message is staff-only), `unread_clause()` / `not_archived_clause()` (both match a missing meta row **and** an empty string), `inbox()`, `unread_count()`, `about()` (names a deleted home "Listing removed"), `mark_read()` and the archive handler. Fires `tdh_inquiry_refused` and `tdh_inquiry_filed`, which `Log` listens to |
| class-tdh-notifications.php | Notifications | Telling the landlord an enquiry arrived, and writing down every attempt (D3). Listens to `tdh_inquiry_received`, so it runs only after the record exists. `recipient_for()` (the home's contact address, else the account's, falling back rather than failing), `queue()` (one row per inquiry+channel, found or made), `send()` (one attempt; refuses a row already `sent` or `given_up`), `retry()` on `tdh_notification_retry`, `sweep()` on `admin_init` for hosts whose cron only fires on a page view, `resend()` for the staff button (resets the counter and re-reads the address), `subject()` / `body()` / `headers()`, and `state_of()` for the badge. `copy_to()` reads the staff copy switch, off by default |
| class-tdh-sms.php | Sms | Text messages to landlords (D4). Registers nothing unless `TDH_SMS_ENABLED`, except the verification reset on a phone-number edit. `state_for()` (phone, verified, consented, opted out, pending — and the label in words), `on_inquiry()` at priority 20 on `tdh_inquiry_received` (after the email; writes an `sms` row and books the send, or a `skipped` row saying why not — never a row for someone who never opted in), `send()` (test-mode gate at send time, one retry, then given up), `sweep()`, `body()` (decision 3, exactly, no renter data), the profile card's four POST actions (start / verify / resend / stop) with the six-digit code (10 min, 5 tries, 60 s between sends, 5 an hour), the STOP/START inbound webhook and the delivery-status webhook, both signature-checked, the per-landlord hourly limit, and the test-mode admin notice |
| sms/class-tdh-provider.php | Sms\Provider | The one-method interface a gateway implements: `send( to, body )` → ok / id / error. Never throws |
| sms/class-tdh-twilio.php | Sms\Twilio | Twilio's Messages API by plain `wp_remote_post`, credentials from `TDH_TWILIO_SID` / `TDH_TWILIO_TOKEN` / `TDH_TWILIO_FROM` (a number, or an `MG…` Messaging Service SID — the one the A2P campaign is attached to). `verify_signature()` for the webhooks |
| sms/class-tdh-capture.php | Sms\Capture | Texts written to `%TEMP%\thirtydayhomes-sms\` instead of sent, on local/development when Twilio is not configured — the SMS twin of `Mail::capture()`, so the whole landlord journey can be walked without an account. Never on production |
| class-tdh-area.php | Area | A typed postcode, town or neighbourhood → a point on the map, cached a month (a miss 12 hours, a service failure 10 minutes). Refuses house numbers and states on purpose. Returns null for every unhappy ending, so the search falls back to text and never shows an error (C6, R44) |
| class-tdh-phone.php | Phone | US numbers → E.164 and back to "(412) 555-0184" |
| class-tdh-accounts.php | Accounts | Register/login/reset/profile/member/facility handlers, `url()`, `is_staff()`, `is_landlord()`, stashes, menus, admin guard |
| class-tdh-account-render.php | Account_Render | Account screens, landlord portal, staff marketplace portal. The marketplace portal's root carries `portal--admin` (G5a) and its screens share `mk_queue_row()` (a waiting home with Approve and Request changes, on Overview and Listings), `mk_member_counts()` / `mk_status_clause()` (Members chips and filter), `mk_plan_name()` (stored plan text → words) and `mk_initials()`. Listings takes `?needs=location` (homes with no map point) beside `?listing_status=`; Members takes `?q=`, `?member_status=` and `?add=1`; Facilities takes `?add=1` |
| class-tdh-listing-form.php | Listing_Form | Wizard handler, gate, `missing_for_submit()`, amenity catalogue |
| class-tdh-listing-form-render.php | Listing_Form_Render | Wizard markup, review blocks, busy script. Since G4b (plugin 0.24.11): a named stepper (`lform-steps`, links only on the review page), `lform-field--half` for the short pairs, `unit_input()` for "$" fields, the review's status pill and **Worth fixing** card (`worth_fixing()`: advice only, never a gate — `Listing_Form::missing_for_submit()` stays the gate) and the cover-and-description glance |
| class-tdh-listing-photos.php | Listing_Photos | Photo order, cover sync, descriptions, upload cleaning (upright, ≤ 2000 px, metadata/GPS stripped) |
| class-tdh-moderation.php | Moderation | Approve / request changes (reason required) |
| class-tdh-listing-actions.php | Listing_Actions | Pause, resume, delete; outcome notices; staff email on submit |
| class-tdh-listing-manage-render.php | Listing_Manage_Render | The landlord's listing rows: state line, one primary action, delete confirmation, pages |
| class-tdh-listing-preview.php | Listing_Preview | Preview bar on non-public listing pages, noindex for previews |
| class-tdh-availability.php | Availability | Available-from and unavailable periods: parse/check/save, `fits()` / `is_free()`, first move-in, summary, calendar months, the quick-edit handler (A5) |
| class-tdh-geocoder.php | Geocoder | Address → coordinates on save, the four location states, approval hold, staff Set location / look up again, service notice (B1) |
| class-tdh-geocode-provider.php, class-tdh-geocode-google.php | Geocode_Provider, Geocode_Google | The provider interface and Google's Geocoding API. Two methods asking opposite questions: `lookup()` wants the **building** and refuses the middle of a postcode; `area()` wants exactly that middle and refuses the house, preferring the smallest place in the answer (B1, C6). `Geocoder::provider()` returns null while `TDH_OFFLINE` is set, so no suite can reach the live service |
| class-tdh-geocode-command.php | Geocode_Command | `wp tdh geocode` (B1) |
| class-tdh-availability-render.php | Availability_Render | The availability editor (wizard step 2 and My listings) and the quick-edit panel (A5) |
| class-tdh-contact.php | Contact | Contact form handler, storage, notification |
| class-tdh-bot-check.php | Bot_Check | Cloudflare Turnstile on sign-in, sign-up, password reset, contact and inquiry (R62). Keys `TDH_TURNSTILE_SITE_KEY` / `TDH_TURNSTILE_SECRET` in wp-config only; off until both exist. `passes()` is what a form asks (a WP-CLI run is never challenged); `judge()` is the verdict on one token. Cloudflare unreachable lets the person through and logs it. Suite `verify-bot-check.php` |
| class-tdh-render.php | Render | Every public section (hero, grid, audience, pricing, about, how it works, contact, search bar), plans |
| class-tdh-shortcodes.php | Shortcodes | All shortcode registrations |
| class-tdh-mail.php | Mail | From name/address, local mail capture |
| class-tdh-smtp.php | Smtp | SMTP transport, readiness, test send |
| class-tdh-no-cache.php | No_Cache | Keeps page cache off private pages |
| class-tdh-legal-pages.php | Legal_Pages | Prints the three seeded legal pages (`terms`, `privacy`, `fair-housing`) as a visitor should read them: bare URLs become links, and the editor's "Still to come" / "Draft copy" notes (with the heading over them) are shown to staff only, labelled — a `the_content` filter at priority 9, matched on the seed key, so nothing stored is rewritten (G3b). `prepare( $content, $staff )` is the testable core |
| class-tdh-views.php | Views | View beacon REST route and counts |
| class-tdh-user-privacy.php | User_Privacy | Closes username enumeration (REST users, ?author=, users sitemap) |
| class-tdh-security-baseline.php | Security_Baseline | Security checks as data |
| billing/class-tdh-stripe.php | Billing\Stripe | Credentials, mode, API calls, plan↔price mapping |
| billing/class-tdh-checkout.php | Billing\Checkout | Start Checkout, return notice |
| billing/class-tdh-webhook.php | Billing\Webhook | Signed webhook → Membership |
| billing/class-tdh-customer-portal.php | Billing\Customer_Portal | Stripe billing portal redirect |
| billing/class-tdh-settings.php | Billing\Settings | Listings → Payments screen |
| setup/class-tdh-importer.php | Setup\Importer | Runs structure / content / homepage steps |
| setup/class-tdh-site-structure.php | Setup\Site_Structure | Pages, menus, vocabularies, front page, tagline |
| setup/class-tdh-page-layouts.php | Setup\Page_Layouts | Elementor layouts with edit guard |
| setup/class-tdh-sample-content.php | Setup\Sample_Content | Sample facilities and listings (**never on live**) |
| setup/class-tdh-address-homes.php | Setup\Address_Homes | Step "Homes from your address list" (unticked by default, R55): one live home per row of the private `tdh-address-list.php` uploaded next to wp-config.php (never in git); sample rent/rooms/photo, `_tdh_sample_details` = 1; re-run skips homes already added. Suite `verify-address-homes.php` |
| admin/class-tdh-meta-boxes.php | Admin\Meta_Boxes | Schema-driven wp-admin fields |
| admin/class-tdh-demo-importer.php | Admin\Demo_Importer | Tools → Import Demo Content |
| admin/class-tdh-mail-settings.php | Admin\Mail_Settings | Listings → Email delivery |
| admin/class-tdh-security-screen.php | Admin\Security_Screen | Listings → Security |
| admin/class-tdh-shortcode-reference.php | Admin\Shortcode_Reference | Listings → Shortcodes |
| admin/class-tdh-editor-hint.php | Admin\Editor_Hint | "Built with Elementor" notice |
| admin/class-tdh-listing-table.php | Admin\Listing_Table | wp-admin → Listings → All Listings (G5b): photo, Status pill, Landlord, Rent (sorts as a number), Map point and one Updated date in place of Author, Date and City; staff without `manage_options` sent to the portal's Listings (`staff_destination()`); Elementor's tracking opt-in notice dismissed for the site (`quiet_elementor()`) |
| demo/class-tdh-demo-mode.php | Demo\Demo_Mode | Review personas and "Viewing as" bar |
| elementor/class-tdh-registrar.php + widgets/ | Elementor\* | Category, 9 widgets, editor styles |

Plugin `tools/`: `verify.bat` and `verify*.php` (tests), `import-demo.php`
(WP-CLI importer), `baseline.php` (security checks), `optimise-images.php`.

---

## 9. Theme file map (`themes/thirtydayhomes/`)

| File | Purpose |
|---|---|
| functions.php | `TDH_THEME_VERSION` and requires for `inc/` |
| header.php / footer.php | Site chrome; opens/closes `<main id="content">`; `has-js` class |
| front-page.php | Page content if present, otherwise a coded fallback home |
| page.php | Banner + content; full-layout and wide-body variants; on the three legal pages (seed keys `terms`, `privacy`, `fair-housing`) a **Last updated** line from the page's modified date, and the theme's `tdh-page-<seed>` classes give them a compact navy banner without the photograph (G3b) |
| archive-tdh_listing.php | Search results; H1 adds the concise `/homes/` phone orientation that replaces the archive's photographic banner only below 43.75rem |
| single-tdh_listing.php | Property page (never the street address); fires `tdh_listing_preview_bar`; hides "About this home" when there is no description; photos via `listing-gallery`; since G3a the facts are one line, Included and House rules are two sections, the inquiry form is in the main column at `#inquire`, the side column pins with **Ask the owner**, and phones get a bottom bar (`.detail-bar`, printed only when the inquiry hook exists) |
| template-parts/listing-gallery.php | H3 Version B gallery: validates IDs before the count; five-tile 60/40 desktop mosaic, tablet/phone reductions and every valid ordered photo in the native viewer; one photo stays plain; alt falls back to "{title} — photo n of N" |
| template-parts/listing-availability.php | Property page availability: first move-in day, minimum stay, upcoming unavailable periods, legend, 12-month calendar (A5) |
| 404.php | Designed not-found page, with home-specific wording under `/homes/` |
| template-parts/listing-card.php | Listing card: rent + availability pill (`.card-head`), name, place, facts, hospital band, **View home** (G3a); fires `tdh_listing_card_proximity` inside `.property-body` |
| template-parts/listing-results.php | The search results: one search form (`filter_bar( [ 'search' => … ] )`, or the plain `search_bar()` when the filters are switched off), Sort by and List / Map beside the count (`.result-tools`), chips, cards, pagination, the two empty states (the no-homes-at-all one offers **List your home**) — shared by the listing archive and the taxonomy archives (C1, G3a) |
| taxonomy-tdh_city.php, taxonomy-tdh_property_type.php, taxonomy-tdh_neighborhood.php | A term's banner, then the shared results part; before C1 these fell through to `index.php` |
| assets/filters.js | The filter panel as a drawer on phones (backdrop, Escape, focus trap, focus return, closes when the screen widens), the sort form's submit-on-change (its Sort button hidden), and the move-out `min` sync; the form works without it |
| assets/results.js | Filters, sort, page numbers, filter labels and clear links refresh only the `[data-tdh-results]` block (R59): fetches the same URL, swaps the block, updates the address bar, re-runs `tdhFiltersInit` / `tdhSavedInit`, settles on the results below any pinned header. Map view and failures normally fall back to a page load; H1's phone `/homes/` keeps the old results and typed fields on failure, clears busy, shows one alert and retries the exact intended URL |
| assets/places.js | Address suggestions from Google Places (New) (R58): the listing form's street box (`data-tdh-places="address"`, fills street, ZIP, state, city, neighbourhood; addresses only) and staff's Set location search (`"point"`, fills the coordinates). Settings from `Maps::places_settings()`; loaded only on the listing form and the staff portal. Plain text boxes without it |
| index.php, elementor/tpl-full-width.php, elementor/tpl-canvas.php | Fallbacks and page templates |
| inc/setup.php | Theme supports, menus (`primary`, `footer`), image sizes `tdh-card` 640×480, `tdh-gallery` 1300×760, `tdh-thumb` 160×120 |
| inc/assets.php | Enqueues fonts, `tdh-tokens`, `tdh-theme`, `tdh-nav`, `tdh-saved`, and on the property page only `tdh-gallery`, `tdh-availability`, `tdh-inquiry` and `tdh-detail`; hero preload; favicon redirect |
| inc/brand.php | Logo and wordmark, hero image fallback |
| inc/customizer.php | Hero image setting |
| inc/security.php | Hides generator, disables XML-RPC, generic login errors |
| inc/elementor.php | Page templates, `tdh_elementor_location()`, Elementor helpers |
| inc/breadcrumb.php | Breadcrumb trail + schema, `tdh_page_banner()` |
| inc/account.php | Portal page detection, hides header/footer/admin bar on portal pages; `tdh-page-<seed>` body classes (the header CTA is outlined on `tdh-page-login` / `-register`) |
| inc/icons.php | Inline Lucide icons: `tdh_icon( name, size )` |
| inc/listings.php | `tdh_listing_city()`, `tdh_listing_location()`, `tdh_listing_utilities()` |
| assets/design-tokens.css | All colours, type, spacing, radii, shadows, motion, plus H3's gallery height/tablet height/phone aspect tokens. Since G2 (theme 0.51.0) the type scale has no step under 12px (`--text-3xs`/`--text-2xs` = 12, `--text-sm` = 14), text is two-tone (`--text-default` for headings and figures, `--text-body` #3e4956 for running text, `--text-muted` #5b6774 ≥ 5.4:1), gold text is `#7a6222` (5.8:1) and the three shadows are navy-tinted (Y 4/12/16, blur 16/48/56). The standard is `D:\fahad vi backup\MASTER-DESIGN-PROMPT.md` |
| assets/nav.js | Mobile nav drawer, portal sidebar, hero end-date `min` sync (the button is always enabled since G2) |
| assets/saved.js | Saved-homes hearts (localStorage) |
| assets/gallery.js | H3 focused photo viewer: opens at the chosen tile, updates Photo X of N and caption, bounded Previous / Next plus arrows/Home/End/Escape, traps focus, locks page scroll, handles image failure and returns focus to the exact opener |
| assets/availability.js | Previous / Next month buttons for the property page calendar; reduced motion honoured (A5) |
| assets/detail.js | Property page (G3a): hides the side column's **Ask the owner** and the phone bar while `#inquire` or the footer is on screen, and moves focus to the form's first field after the jump; without it the button is a plain link |
| assets/auth.js | Sign in, Create account, Choose a new password and the landlord portal (G3b, G4a): reveals the **Show / Hide** control beside each password field (`aria-pressed`); holds the text-alerts **Send code** until a 10-digit number and the consent tick, with the reason beside it; closes the listing cards' **More** menus on phones; opens the marketplace portal's **Add member** / **Add facility** panel in place and puts the cursor in its first field (G5a, `data-tdh-add`). Without it: plain password fields, a pressable Send code the server checks, menus open in the row, the Add button opens the panel through `?add=1` |
| style.css | The only stylesheet; H1's `/homes/` phone rules stay archive-scoped; H3 adds the token-based 60/40 gallery, tablet/phone reductions and navy focused viewer with scoped direct-child selectors |

### style.css map (search for the banner comments)

BASE · BUTTONS · LOGO · HEADER · HERO · SECTION SHELL (heading rhythm,
breadcrumb) · PROPERTY CARDS (archive search bar) · SPLIT FEATURE ·
AUDIENCE · OWNER CTA · LISTING DETAIL · LISTING PREVIEW BAR · NOT FOUND ·
STATES · ACCOUNT SCREENS (auth
split, notices, dashboard, settings, pricing, membership, stat tiles, empty
state) · FOOTER · RESPONSIVE · HOW IT WORKS (`hiw-`) · ABOUT (`about-`) ·
CONTACT (`contact-`) · LANDLORD PORTAL (`portal-`, incl. marketplace
portal, note form, pagination) · LANDLORD LISTINGS (`manage-`) · CREATE A
LISTING WIZARD (`lform-`) · AVAILABILITY (`avail-` editor, `manage-avail`
panel, `avail-cal` calendar).

Breakpoints in use: 1050px, 950px, 700px, 62rem (992px, nav drawer and
most sections), 48rem, 43.75rem (700px), 34rem. Media queries cannot use
CSS variables.

### Design tokens (highlights)

- Navy `--color-navy #0c192b`; gold `--color-gold #d7b967` (fills);
  **gold text on light backgrounds must use `--color-gold-deep`** (contrast).
- Role tokens: `--bg-*`, `--text-*`, `--border-*`; semantic
  `--color-success|warning|danger|neutral` (+ `-bg`).
- Fonts: Playfair Display (headings), DM Sans (body).
- Type: `--text-display`, `--text-h1…h5`, `--text-xl…3xs`.
- Spacing `--space-1…12` (4px scale); radii `--radius-xs…full`;
  motion `--duration-fast|base|slow` with `--ease`; `--disabled-opacity`.
- Rule: section styles use tokens only; add a token before adding a value.

---

## 10. Hook index

### Actions and filters the plugin fires

| Hook | Args | Listeners today |
|---|---|---|
| `tdh_before_init`, `tdh_init` | Core | none |
| `tdh_membership_status` (filter) | status, user_id | none |
| `tdh_listing_quota` (filter) | quota, user_id | none |
| `tdh_membership_changed` | user_id, changes, before | `Enforcement::enforce` at priority 20 (grace clock, hide, restore — E1) |
| `tdh_inactive_member_ids` (filter) | int[] | `Enforcement::inactive_member_ids` (authors of a live row whose plan is past its grace — E1) |
| `tdh_listing_submitted` | listing_id, resubmitted | `Listing_Actions::notify_staff` (email to the admin address) |
| `tdh_listing_paused`, `tdh_listing_resumed`, `tdh_listing_deleted` | listing_id | none |
| `tdh_listing_changes_submitted` | listing_id, string[] changed, bool was_paused | `Listing_Actions::notify_staff_of_changes` (email) |
| `tdh_material_fields` (filter) | key => label | none — decision 8's final list goes here |
| `tdh_listing_availability_saved` | listing_id | none (fired by the My listings quick edit) |
| `tdh_availability_today` (filter) | YYYY-MM-DD | tests pin "today" with it |
| `tdh_geocode_provider` (filter) | Geocode_Provider\|null | tests stand in a fake; null = no key |
| `tdh_geocoded` | id, state, reason, asked, why | after every lookup decision; `Log` writes found / not found / no answer only when the service was asked |
| `tdh_stripe_received` | type, event_id, mode | every verified, first-seen webhook event, before it is handled |
| `tdh_stripe_rejected` | reason, mode | not_configured, bad_signature, malformed, mode_mismatch, duplicate |
| `tdh_login_throttled` | login | a sign-in refused for too many attempts (the wrong-password case is core's `wp_login_failed`) |
| `tdh_proximity_settings_saved` | count, radius | staff changed the hospitals setting |
| `tdh_log_features` (filter) | array key => label | the log's tabs |
| `tdh_listing_approved` | listing_id | none (tests only) |
| `tdh_listing_changes_requested` | listing_id | none |
| `tdh_contact_received` | inquiry_id | none |
| `tdh_landlord_registered` | user_id | none |
| `tdh_stripe_event` | type, object, mode | none (invoice events are ignored) |
| `tdh_mail_captured`, `tdh_smtp_configured` | — | none |
| `tdh_listing_upload_overrides` (filter) | overrides | tests |
| `tdh_membership_plans`, `tdh_plan_features` (filters) | arrays | none |
| `tdh_demo_reset` | path | none |

### Hooks the theme fires for the plugin

| Hook | Where | Listener |
|---|---|---|
| `tdh_listing_card_proximity` | listing card | `Proximity::render_band` |
| `tdh_listing_preview_bar` | single listing, above the page | `Listing_Preview::render_bar` |
| `tdh_listing_proximity` | single listing | `Proximity::render_list` |
| `tdh_listing_inquiry_form` | single listing price box | **none** (task D1) |

Query var `tdh_bypass_visibility => true` (strictly true) lets owner and
staff queries see non-public listings.

---

## 11. Elementor

- Free Elementor only (no Pro). Widgets live in the "ThirtyDayHomes"
  category and each wraps a `TDH\Render` method — no markup in widgets.
- Widgets: Hero Search, Audience Cards, Property Grid, Split Feature, Owner
  CTA, About Page, How It Works Page, Membership Pricing, Contact Page.
- Deliberately **not** editable: search field names, plan prices, contact
  form fields and topics.
- `Setup\Page_Layouts` builds Home, About, How it works, Pricing and Contact
  as one section per widget, stores `_tdh_layout_fingerprint`, and skips any
  page edited since (edit guard). It deletes stale autosaves and clears
  Elementor CSS caches.
- The Elementor editor loads the theme tokens and stylesheet so widgets look
  the same in the editor.

---

## 12. Billing (Stripe)

- Mode option `tdh_stripe_mode`; only the exact value `live` is live.
- Credentials per mode side by side; `TDH_STRIPE_{MODE}_{FIELD}` constants
  override and lock fields. Screen: Listings → Payments (`manage_options`).
- Plans are code (`Render::plans()`): 1 listing $49, 2 listings $88,
  3 listings $125 — **not signed off by the client**. Stripe Price IDs map
  plans (`price_1..3`).
- Webhook `POST /wp-json/tdh/v1/stripe-webhook`: raw-body HMAC with 5-minute
  tolerance, mode check, event dedupe (4 days), marked seen before work.

| Stripe subscription status | Membership |
|---|---|
| active / trialing with cancel_at_period_end | cancelled ("Cancelling") |
| active, trialing | active (quota = plan listings) |
| past_due, unpaid | past_due (quota kept) |
| canceled, incomplete_expired, subscription deleted | expired (quota 0) |
| incomplete, paused, other | none |

- `Membership::status()` reads a stored active/cancelled as expired once
  the expiry passes.
- Local webhook testing: `tools/test-webhook.ps1` (signed fake events).

---

## 13. Email

- From: `tdh_mail_from` or `noreply@<site domain>`; name from option or
  site name.
- Transport: `TDH\Smtp` (Listings → Email delivery), constants
  `TDH_SMTP_*` override; 15-second timeout; test send with transcript.
- **Local and development capture mail to files** in the system temp folder
  `thirtydayhomes-mail` (`TDH_MAIL_CAPTURE` overrides; production never
  captures). Reset links are in those files.
- Live currently sends through Gmail SMTP, from `mdsweem4@gmail.com` (a reset email reached a Gmail inbox, not spam, within seconds on 24 Sep 2026 — F1 depends on this). MX, SPF and DMARC are in Cloudflare; the `support@thirtydayhomes.com` mailbox and DKIM wait on Hostinger clearing a stuck email order ("There is an active order for this Domain", still so on 24 Sep 2026). When it exists: switch the `TDH_SMTP_*` constants in live wp-config to `smtp.hostinger.com` and that mailbox, and add DKIM in Cloudflare as DNS only. Claim the **Free** Business Email plan — the Starter trial bills the client's card yearly after 12 months.
- The web server captures local mail in `C:\Windows\TEMP\thirtydayhomes-mail`; WP-CLI captures in the user's temp folder.

---

## 14. Tests

Run all suites (about a minute):

```
set TDH_NOPAUSE=1
plugins\thirtydayhomes-core\tools\verify.bat
```

Run one: `D:\xampp\php\php.exe D:\xampp\wp-cli.phar eval-file wp-content/plugins/thirtydayhomes-core/tools/verify-listing-form.php --path=D:\xampp\htdocs\thirtydayhomes`
(from the WP root, or with `--path`).

| Suite | Covers | Passing (17 Sep 2026) |
|---|---|---|
| verify.php | Ownership, visibility, membership defaults, proximity, noindex | 34 |
| verify-contact.php | Contact form end to end | 92 |
| verify-delivery.php | Mail and SMTP | 71 |
| verify-notifications.php | Who the email goes to (contact address, the fall-back and what is logged, nobody to write to); one row per inquiry+channel; a send that works (the row, the recipient, the subject, the link, and that the renter's phone and message are NOT in it); reply-to with commas, angle brackets and no address; the failure ladder (failed, the reason, +10 min, +60 min, never stacked, given up at three, a fourth attempt refused, and a row already sent not sent twice); the sweep (not yet due, due, a second attempt waiting the full hour, terminal rows never swept); resend (the counter restarts, the address is re-read, no address refuses without spending an attempt); the button (a stale page, a landlord, a logged-out visitor, staff, double press, an id that does not exist); the words (singular "1 attempt", every state its own); the screen (the Delivery block, badges, the address, the reason, the form and its nonce, no Resend on a sent one, a landlord shown none of it, the list badging only trouble); copies (off by default, rubbish refused, the settings form and who may use it); the real capture file; the log lines; that the renter never waits for the mail server (the row is queued and the send booked, not made), the sweep collecting queued rows so cron being off delays an email rather than losing it, an sms row never reaching the email sweep or send(), a failed staff copy still logged, the staff list's second page and its total, the resend form's handler being well-formed markup with its label in a data attribute; and that the run leaves no enquiry, no row and no false alarm on the Email delivery screen | 167 |
| verify-page-widgets.php | Elementor page widgets vs shortcodes; H1's phone-only archive opening, DOM order, 320px toolbar alignment and offline recovery contract | 100 |
| verify-portal.php | Views, landlord portal, staff portal, moderation, the sign-in box takes a username | 85–86 (one check runs only when a pending listing exists) |
| verify-listing-form.php | Wizard gate, every step, phone, Back saves, review mode, submit | 148 |
| verify-privacy.php | Username enumeration closed | 9 |
| verify-search.php | Keyword search; each filter alone, combined, nothing (never everything), prices swapped and said, negative and nonsense refused, a home with no price; sorting; the stay (which homes are free, the shared turnover day, open-ended, dates plus filters, a window no home can take) and every refused stay with its wording; chips and what removing one keeps, Clear all; the count and empty-state words; the filter bar markup (labels, kept values, count, drawer parts, the two date fields and their bounds, the hospital groups, the disabled radius); the hospital (order, radius, any distance, un-geocoded last, refused choices, the words, a hospital name typed as a keyword); the city archive gets the same filters and posts back to itself; a secondary query untouched | 178 |
| verify-preview.php | Who may preview, the preview bar per role/status, 404 template | 24 |
| verify-photos.php | Upload cleaning (size, EXIF gone), order, cover, alt text, removal, HEIC refusal, legacy cover and step 3 controls; H3 validates zero/invalid/mixed/one/ten renderable photos, exact order, five previews/all viewer slides, captions, labels, bounded initial control, progressive enhancement, keyboard script and hidden-control CSS | 42 |
| verify-geocode.php | Reading Google's answer, pasted coordinates, lookup on save / not on an unchanged address, not found and imprecise, the landlord notice, approval held (server, queue button, preview bar), Set location (refused, expired, saved, not staff), typed points, service down and the pause, no key, 0,0, facilities (address only, override, clear, not found, in-page delete), REST privacy — with a fake provider | 71 |
| verify-log.php | Writing (fields, UTC time, source, request id, level and feature fallbacks, long messages), what a row may never carry (secret-looking keys and values, emails, phones, logins, nested, objects, WP_Error), features and levels, filters and pages, counts and latest, purge and schedule, the line every hook produces (approve, pause, edit to review, refused and throttled sign-in, geocode outcomes, mail failed and captured, Stripe refused/duplicate/received, membership, settings), the staff screen (grouped tabs, rows, the line that opens its context, masked, Eastern Time, feature and group tabs, warnings first and ?level=all, errors only, empty state, unknown tab, the Section select, System status), landlord refused | 89 |
| verify-admin.php | wp-admin for the administrator (G5b): the Listings table's columns, heads and sortables, every cell (status pills, landlord, rent, map point, updated, photo, the missing cases), no post-state suffix, who is sent to the portal, the Elementor notice, the Logs groups, words and zone | 28 |
| verify-proximity.php | Haversine distance and its wording, which facilities count (inactive, no coordinates), order, the radius cut-off and its edge, how many are listed, a home with no location, what the property page prints (three rows, singular, nothing in range), the cache and what drops it, the staff setting (saved, refused, expired, landlord refused, the screen) | 50 |
| verify-enforcement.php | E1, over its own fixtures only (the daily job is called with an explicit list, never the global sweep): the grace clock (starts once, a retry never restarts it, the date), the band on every view with its one action, paying inside grace, the job hiding only live homes (paused, in review, draft, another landlord, staff untouched; content, meta and city unchanged; held stamp; not public; idempotent), submit refused and the draft kept, approval refused (handler, notice, queue row and disabled button, preview bar), a stray live row still hidden, restore of exactly the held homes (paused stays paused) and the one-time message, singular wording, expiry without grace and Restart a plan, cancelling until the end date then held without a webhook, staff exempt, the city archive guarded, R40's over-plan wording (usage, the My listings line, the membership screen), the band opening Stripe when a customer exists and linking otherwise, an unassigned home (never blocked, approvable), the hidden row's reason per state, the log lines, cleanup | 74 |
| verify-email-verification.php | F1: sign-up through the real handler (account, pending, one email with the link, 24 h wording, only a hash stored, the welcome), the band on every view with its outlined button and nonce, checkout and submit refused (draft kept), Send a new link (too soon, sent, old link dead, stale page, 5 an hour), the link (forged, unknown user and rubbish alike, expired signs nobody in, confirmed signed out signs in, twice says already, no probing), plan and submit allowed after, a new address re-confirmed and sent to the new address, a link to a superseded address refused and resend going to the current one, older accounts and staff never asked, someone else signed in told "that" address not "your", the log | 49 |
| verify-availability.php | Dates and wording, stored periods, typing rules (backwards, half, not a date, too far, past, single day, joins, limit), the `fits()` truth table, first move-in, summary and calendar months, wizard step 2 (partial save, kept rows, notes, clearing, live home stays live), the My listings quick edit (rows, panel, save, partial, expired page, wrong owner, deleted, double save), the property page part | 96 |
| verify-listing-actions.php | Pause/resume/delete and every refusal, reason required, resubmit + staff email, every row's line and action, pages, preview bar per role, preview by pretty address, the Active landlord persona's plan; A4: minor vs material edits, the hold and confirmation (refresh, Back, double confirm), staff exempt, photo tick, paused → Resume → review, and one check per review finding (missing field, unconfirmed change, `&` title, block description, refused uploads, preselected city, staff step 4, stale arrow); the review personas (Active landlord has a plan, Failed payment is past due in grace) | 130 |

Harness conventions:
- `ok( label, condition, detail )` with static counters; `ok()` returns totals.
- Code that redirects is tested by throwing from a `wp_redirect` filter.
- `is_main_query()` needs `$GLOBALS['wp_the_query']` swapped.
- No `declare(strict_types)` in suites (breaks under eval-file).
- `global $wpdb;` before using it at script level — under `eval-file` the
  script body is a function scope, not the global one. The same reason
  `ok()` keeps its counters in statics.
- **A suite that clears `TDH_LOG_SILENT` must take its rows back out.**
  Snapshot `MAX(id)` first and `DELETE FROM … WHERE id > floor` after,
  the way `verify-log.php` always has. Restoring the flag is not enough:
  `verify-inquiry.php` restored it and still left 24 lines per run, so an
  administrator opening Logs found invented enquiries and invented mail
  failures against homes that do not exist — which is the exact problem
  `TDH_LOG_SILENT` was added to solve. Deleting by id range, not by event
  name, because one stored enquiry wakes Notifications, Mail and Smtp and
  each writes its own line. A full `verify.bat` run must leave the log
  row count unchanged; check it when a suite touches logging.
- Suites create their own fixtures and delete them. Several suites need
  the demo import (seeded pages) and the theme active.
- **Read the output**: only some suites exit non-zero on failure, so
  "Done." does not prove everything passed. Look for `FAILED`.

### The same suites on every push (`.github/workflows/tests.yml`)

Added 20 Sep 2026 (register R42a). GitHub builds a clean WordPress on the
runner — `WP_ENVIRONMENT_TYPE` `local` as on the developer machine, so
mail is captured to a file and nothing can reach a real mailbox; site URL
`http://thirtydayhomes.test`, because the default From address is
`noreply@<host>` and the plugin rightly refuses `noreply@localhost` —
installs the plugin, the theme and Elementor from the commit, imports the
demo content ("structure" and "content"), runs every suite and reads each
one's output for `FAILED` — the exit code is not enough, for the reason
above. A suite that prints no result line at all is treated as a failure,
because that means it stopped early; a suite that says "cannot be tested
here" is recorded as a skip. PHP notices go to `debug.log` and are printed
as a warning, never as a failure. The first run (20 Sep 2026) found two
suite habits a clean install exposes: a skip recorded as `FAIL`, and a
refusal check that silently did not run because it borrowed a landlord
instead of making one. Both fixed; a suite makes its own fixtures.

**It gates the deploy.** Since 20 Sep 2026 (after run #2 was seen green on
the real runner) `deploy.yml` calls it as its first job and deploys only
if it is green; the Actions tab names the suite that stopped a push. The
suites run as that job rather than from a `push` trigger of their own —
two runs per push would share a concurrency group and could cancel the
one the deploy waits on. Pull requests and manual runs still run
`tests.yml` on their own. If the tests are wrong rather than the code, fix
the tests and re-run the deploy workflow by hand; there is no bypass.
Nothing in the job touches the live site, and it needs no secrets.

Visual checks: headless Chrome via the DevTools protocol can sign in through
the real login form and screenshot pages at 1280px and 390px. Do not bypass
authentication to take screenshots.

---

## 15. Deployment, backups and operations

### Deploy (automatic on push to `main`)

1. `.github/workflows/deploy.yml` checks out, verifies the plugin header
   `Version:` equals `const VERSION` (fails the build otherwise).
2. Verifies the server host key against a pinned fingerprint.
3. SSHes in, `git reset --hard origin/main` in `~/repos/thirtydayhomes-main`,
   runs `tools/deploy.sh`.
4. `deploy.sh` takes a database snapshot (kept 7 days), then
   `rsync --delete` of **only** `themes/thirtydayhomes` and
   `plugins/thirtydayhomes-core` into WordPress.
5. Health check: the home page must return 200.

After a deploy: LiteSpeed → Purge All; load wp-admin once if the plugin
version changed (runs the upgrade); run "Pages and menus" import only when
seeds changed; never run "Sample listings" on live.

**Checking whether a deploy landed: read the theme version on `/account/`,
never on the home page.** `?ver=` on `style.css` comes from
`TDH_THEME_VERSION`, so it is the quickest proof of what is running — but
LiteSpeed serves the home page from cache until it is purged, and it will
happily keep advertising the previous version for hours. On 22 Sep the home
page still said `0.44.0` while `/account/` said `0.45.0`, long after D2
had deployed — which reads exactly like a failed deploy. The
account pages carry no-cache headers (`TDH\No_Cache`), so they always
answer with what is really installed.

Rollback: revert the commit and push. Database: restore the pre-deploy
snapshot (`tools/DEPLOY.md`).

### Backups

- `tools/backup.sh` nightly (cron 03:17): database, wp-config, original
  uploads to `~/backups/thirtydayhomes/<timestamp>/`, kept 14 days, writes
  `.tdh-last-backup`. **Same server only — no off-site copy yet.**
- `tools/restore.sh <dir> --yes`: verifies archives, snapshots current DB,
  restores DB and uploads (not wp-config).
- `tools/lib-db.sh` falls back to `mysqldump` because Hostinger disables
  `proc_open`.

### Other tools

- `tools/build-release.ps1` — zips theme and plugin into `dist/` (fallback
  manual deploy).
- `tools/test-webhook.ps1` — simulates Stripe webhooks locally.
- `tools/client-report/make-report.php <name>` — builds the client .docx.
- `wp tdh geocode [--all] [--type=listing|facility] [--dry-run]` — look up
  every home and facility still without a location. Run once on each site
  after `TDH_MAPS_SERVER_KEY` is added (B1).

---

## 16. Conventions and past mistakes not to repeat

### Coding

- PHP 8.1+, `declare(strict_types=1)`, namespace `TDH`, one class per file,
  final classes, WordPress escaping and nonces everywhere.
- **Write PHP with editor tools or `[System.IO.File]::WriteAllText` with
  `UTF8Encoding($false)`. Never PowerShell `Set-Content`** — a BOM broke the
  live site twice. Shell scripts stay LF.
- Bump plugin `Version:` **and** `const VERSION` together; bump
  `TDH_THEME_VERSION` **and** the `style.css` header together.
- New listing field: add it to `Fields::listing_schema()` first; the admin
  meta box follows automatically.
- New inquiry key: must be in `Fields::inquiry_schema()`.
- New private page: add its seed key to `No_Cache::PRIVATE_KEYS`.
- Link pages with `Accounts::url( 'seed-key' )`, never by slug.
- Staff checks use `Accounts::is_staff()`, never `manage_options`.
- Public listing queries go through `Visibility`; owner/staff queries set
  `tdh_bypass_visibility => true`.
- CSS: tokens only; every selector scoped to its section with child
  combinators; no bare element descendants.

- **Tap targets:** a stand-alone link or control is at least 2.25rem tall. The shared list sits in `style.css` under "Tap targets (G1 design review)" — add a new stand-alone link there rather than giving it its own padding.
- **Labels:** every field's `<label>` has `for` pointing at the field's `id` (on repeated forms the id carries the record id, e.g. `tdh-m-<id>-tdh-email`), or the field has `aria-labelledby`.

### Mistakes that already happened (do not reintroduce)

- An empty `post__in` returns **every** post — use `[0]` for "nothing".
- **Never assume the code is broken before testing the key.** A wrong API
  key looks exactly like a bug. Google tells you which you have, in one
  request: `InvalidKeyMapError` means a key it has never seen, while
  `ApiTargetBlockedMapError` (Maps JS) or "not authorized to use this
  service" (Geocoding) means a real key aimed at the wrong service. A key
  a client typed or that was read off a photograph will get `I`, `l` and
  `1` wrong; compare it against a key of theirs that already works to
  learn which substitution they make. C4 lost a day to this, 21 Sep 2026.
- A page-output test that calls `the_post()` before including a template
  proves nothing: the template's own `while ( have_posts() )` then finds
  nothing, the body never renders, and every "this must not appear"
  assertion passes against a header and a footer. Assert that something
  which *should* be there is there first (C4's privacy suite, 21 Sep 2026).
- A registered **boolean** meta stores false as an **empty string**, so a
  facility switched off (`_tdh_active`) reads back identically to one
  where the key was never written. `get_post_meta() === ''` cannot tell
  "off" from "never set"; only `metadata_exists()` can. Read it the wrong
  way and every switched-off hospital walks back into renter search
  (caught by C3's own suite, 21 Sep 2026).
- **A register row is only delivered when the behaviour is proven.** C3
  shipped the hospital sort, and R18 ("closest-first ordering for
  location/ZIP") was ticked off against it although C3 never touched
  keyword-by-ZIP. Nobody noticed for five weeks; Rob found it himself on
  22 Sep 2026, and the live Renter FAQ had been promising the behaviour
  the whole time. When a task closes, re-read every row that names it and
  prove each one on the site — and before a phase closes, read the public
  copy and check each promise in it is true. **A promise in shipped copy
  is a specification** (R44).
- **An element taller than the viewport cannot be pinned without paying
  for it. Measure first, and usually do not pin.** The property page's
  right-hand column is ~1200px against a ~900px screen, and D1 tried every
  scheme there is: one tall `position: sticky` card never pinned at all,
  because `sticky` cannot pin what does not fit — the price had been
  scrolling away on every home since before the task. Pinning only the
  price card left the form sliding up *behind* it, hiding the fields being
  filled in. Capping the column and scrolling inside it sliced the card
  off mid-field, and hiding that inner scrollbar made it read as a
  rendering fault. Bottom-sticky is right in principle but Chrome declines
  it for a grid item taller than the scrollport. The column simply
  scrolled from 22 Sep 2026 (reviewer's decision). To pin a sidebar, make
  it short enough to fit the screen first — and that is what G3a did on
  29 Sep 2026: the form moved to the main column at `#inquire`, the column
  became price + fees + one button (~420px), and it pins.
- **`post_status => 'any'` excludes trash, so "all of a user's posts" is
  not all of them.** The landlord's inbox asked for its homes that way,
  and enquiries about a *deleted* home disappeared with the home — while
  the delete confirmation promises the conversations are kept, and the
  row was built to say "Listing removed" precisely so they could still be
  read. Name the statuses and add `trash` explicitly. Caught by a
  walkthrough fixture whose unread count came back one short (D2).
- **`Accounts::url()` answers an unknown key with the HOME PAGE**, not an
  empty string. Every link in the new inbox was built on
  `Accounts::url( 'dashboard' )` — there is no page under that key; the
  landlord portal is `account` — so they all pointed at the front of the
  site and nothing looked wrong until one was clicked. Check the URL a
  key actually returns before building on it (D2).
- **Count a badge with a query, never from the rows already fetched.**
  The overview loaded four recent enquiries and counted the unread among
  *those*, so a landlord with thirty unread saw a badge reading 4. And
  when opening a message marked it read *after* the badge had been
  counted, the nav said 18 while the list said 17. Both are the same
  mistake: two places deciding the same number. One query, computed
  before anything renders (D2).
- **A stylesheet served without a charset will have its encoding guessed,
  and grepping the source can never catch the damage.** `style.css` goes
  out as `text/css` with no charset and no BOM, on localhost *and* on
  live. The file on disk was perfectly good UTF-8, but the browser
  guessed wrong and the amenities tick `✓` arrived on the page as `âœ`.
  Write any rendered character as a **CSS escape** — `content: "\2713"` —
  which is plain ASCII and cannot be misread. `verify.php` now fails on a
  raw non-ASCII character inside any `content:` value; comments are
  exempt, because they never render (22 Sep 2026).
- **The mojibake grep in the rules is not enough on its own.** The
  standing check is `â€|Ã`, and it did not match `âœ` — the tick's own
  corruption — so the check passed while the page was broken. A wider net
  for a mangled UTF-8 lead byte is
  `[ÂÃâ][\u0080-¿Œœ]`, and even that only
  finds damage that reached the file. Look at the rendered page.
- **A status colour is not a decoration.** The property page's calendar
  was given `--color-success` for its free days. Green is a *status*
  colour in this theme and appears nowhere else on that page, so sixty
  green cells made green the loudest thing on screen and put a colour
  outside the brand — navy, gold, sand, cream — onto a customer-facing
  page. Sand was both on-palette and more correct: the "Available from"
  band directly above the calendar is already sand, so one colour now
  carries one meaning twice on the same screen. Reach for the palette
  before reaching for `--color-success` / `--color-danger` (22 Sep 2026).
- **A legend and the thing it describes are two separate CSS rules.**
  Recolour the day cells and forget the swatch beside the word
  "Available", and the legend describes a calendar that is not on screen
  — while nothing about the page looks broken. `verify-availability.php`
  now reads both out of the stylesheet and compares them.
- **Padding applies at both ends.** `.detail-section` was given
  `--space-8`, which put 81–97px between sections and made the page look
  like it had failed to load something — and the gaps varied by 16px
  depending on whether a section ended with a paragraph, because the
  editor's own bottom margin is added to the padding. Measure the gap,
  not the value you typed, and zero the last child's margin.
- **Hiding a scrollbar is only safe when nothing is being cut off.**
  Hiding the bar on a capped column removed the one signal that its
  content continued, turning a clipped card into something that looked
  broken (D1, 22 Sep 2026).
- **Reusing a class for its colour inherits its layout too.** The enquiry
  form's rules line borrowed `.fine`, which is `display: flex` for an icon
  beside a word. One sentence with a link in the middle came out as three
  columns. Give new content its own class (D1).
- **A field that says it has an explanation must point at one that
  exists.** The enquiry form's first version set
  `aria-describedby="tdh-err-move_in"` on the field while the error itself
  carried `id="tdh-err-move-in"`. The two never met, so a screen reader
  read the field as having no explanation at all — and nothing on screen
  looked the least bit wrong. Build both ids from one function, and assert
  that every `aria-describedby` resolves (D1, 22 Sep 2026).
- **A placeholder that reads like a delivered feature is worse than an
  empty space.** The property page carried "Rules and regulations must be
  reviewed before sending an enquiry" — an instruction with nothing to
  follow: no rules shown, no agreement recorded. It would have been read
  as R24 delivered. Say "not built yet", or build it (D1).
- **A pipeline that stops reading kills the suite mid-run.** Piping a test
  through `Select-Object -First n` in PowerShell terminates php before the
  cleanup at the foot of the file, and the fixtures stay on the install.
  Capture the whole output and filter the variable, never the stream.
- **A search term that names a place is a place, not a string.** Matching
  a typed postcode against each home's stored ZIP answers nothing the
  moment no home sits in that postcode, which is almost always. Geocode
  it and measure (C6). The two lookups are separate on purpose: a home's
  location must never accept a postcode-level result, and a search term
  must never accept a house number.
- **A test must not be able to spend the client's money.** The developer
  machine holds the real Geocoding key in `wp-config.php`, so a suite
  that typed a postcode into the search box would have billed Rob and
  answered differently on every run. `TDH_OFFLINE`, set by `verify.bat`
  and by CI, makes `Geocoder::provider()` return null; a suite that needs
  an answer stands in its own fake through `tdh_geocode_provider`
  (22 Sep 2026).
- The `background` shorthand resets `background-image` (select arrows
  vanished on focus) — use `background-color`.
- A POST larger than `post_max_size` arrives with empty `$_POST` and
  `$_FILES` — the wizard must say so, not silently re-render.
- Silent success reads as failure: uploads worked but showed nothing, and
  the client reported them broken. Always confirm results on screen.
- LiteSpeed cached `/register/`, swallowing errors and serving expired
  nonces to everyone — private pages must stay uncached.
- Visitor stash keys once hashed a constant cookie + IP, sharing one
  visitor's typed email with everyone behind an office NAT.
- Failures once redirected to the Referer; with none they landed on the
  home page and looked like success.
- Staff editing a landlord's draft rewrote the author; a double click
  created two drafts; disabling a submit button immediately drops its
  name/value (disable one tick later).
- The wizard's Back was a plain link: changes typed on a step opened from
  the review vanished and the listing was submitted with old answers.
  Every way out of a step now saves first.
- `is_staff` via `manage_options` locked out the review administrator.
- The city was hardcoded "Pittsburgh" in templates — read `tdh_city`.
- A pull silently reverted uncommitted work — commit or stash first.
- The repo went private and deploys failed (`could not read Username`) —
  the server uses a read-only deploy key over SSH.
- Stripe: the success URL is not proof of payment; an out-of-order
  `created(incomplete)` once overwrote `updated(active)` (re-fetch the
  subscription); a missing webhook secret must return 500 so Stripe retries.
- Elementor: URL controls store arrays; empty URLs must be unset so
  defaults apply; JSON must be `wp_slash`ed; fingerprint what is read back.
- Test harness: static counters, not globals; `rename()` fails across
  Windows drives.
- A4's first version trusted the POST to say what would change: a form
  without the bedrooms field saved 0 bedrooms live, a refused upload took
  an unchanged home offline, and `&` in a title asked for review on every
  save. Review is now decided by comparing what was stored before and
  after the save.
- A redirect carried `?tdh_listing=ID`. That is the listing post type's
  query var, so WordPress looked for a home called "ID" and the dashboard
  became "This home isn't available". Use `tdh_home` in URLs.
- Staff moderation errors set `$notice['error']`, which nothing printed —
  every refusal was silent. The key is `errors[]`.
- Photo order: `menu_order` 0 once sorted first, so a photo attached in
  wp-admin jumped ahead and became the cover. 0 means "unarranged" and
  sorts last.
- An empty latitude/longitude box was saved as 0 (the number sanitizer
  turns '' into 0.0), and `Proximity` only checked for ''. Any code that
  reads a point uses `Geocoder::coordinates()`, which treats empty,
  non-numeric and 0,0 as "no point".
- A demo script that deletes coordinates queues a lookup at the end of its
  own run (the Geocoder watches deletes too): call `Geocoder::flush()`
  before setting a demo state, or the flush undoes it.
- The availability calendar's sideways row of 12 months made the property
  page wider than a phone: the narrow layout's `1fr` column is
  `minmax(auto, 1fr)` and grew to fit every month. A wide scrolling row
  inside a grid needs `contain: inline-size` (or `min-width: 0` on the
  grid item). Check phones on a FRESH page load: re-opening the same
  address after switching the emulated width can show a false overflow.
- A state that never happened must not be worded as a failure. Every home
  saved before the maps key arrived was "pending" with no stored reason,
  and the message read "The map service didn't answer" — blaming a lookup
  that was never made. An empty reason code now says "Not looked up yet".
  The same message also pointed at a button called "Try again" that is
  labelled "Look up the address again": name the control that is on the
  screen, and re-read the copy whenever a feature's first real data
  arrives.
- A phone row only looked right until its text arrived. `flex: none` on
  the status chip plus `min-width: 0` on the name column meant the chip
  took its full nowrap width first: "Hidden — Membership" left the name
  three letters wide and a long sentence eight characters wide. Portal
  rows are now a small grid on a phone (`display: contents` on the name
  block, as `.manage-item` already does): photo, name with its status
  beside it, the home's line under both, and anything long across the full
  width. Let the chip wrap and cap its column with `fit-content()`. Give
  every row in a list the same shape — fixing only the rows that had the
  problem left a list of different shapes. Test phone widths with the
  longest real label, not the shortest.
- **A new `<select>` outside `.form-field` gets the browser's arrow on the
  border again.** The chevron rule lives on `.form-field select` and
  `.lform-field > select`; C1's filter selects sat in `.filter-field` and
  the reviewer saw the flush arrow within a minute of opening the page.
  Either put a select inside `.form-field` or copy the chevron block to
  its own scoped selector, as `.filter-fields > .filter-field > select`
  now does.
- **Never seed fake failures into the log.** To give the Logs screen
  something to show, a script fired the real hooks with invented failures
  (a refused Stripe webhook, a failed email, a blocked sign-in). The
  reviewer read them as real and asked for them to be fixed. A red line in
  this log is a promise that something happened; a demo must come from
  real events (a wrong password on the sign-in page) or not exist.
- `s` is WordPress's search query var. A cache-busting `&s=1` on a page URL
  turns the request into a search and returns the 404 template — the
  screenshots looked like a broken portal. Use any other name.
- **Never rewrite a file with `Get-Content -Raw` + `WriteAllText`.** On
  Windows PowerShell 5.1 `Get-Content` reads with the system ANSI code
  page, so writing the result back as UTF-8 double-encodes every non-ASCII
  character: `—` became `â€"` and the amenity tick `✓` became `âœ` on the
  live-looking page. This is the same class of bug as the `Set-Content` BOM
  that broke the live site twice. Edit files with the editor tools, or read
  with `[System.IO.File]::ReadAllText( $p, (New-Object
  System.Text.UTF8Encoding($false,$true)) )` and write with
  `WriteAllText( $p, $t, (New-Object System.Text.UTF8Encoding($false)) )`.
  To repair a file already double-encoded: encode the string to code page
  1252 and decode those bytes as UTF-8.

---

## 17. Current state (5 October 2026)

- **Milestone 1:** all code live. Client comments #1–#4, #8 done; #9
  answered; #5, #7, #10 are M2; #6 (email verification) proposed as M2,
  written OK from Rob still pending.
- **Milestone 2:** started 17 Sep. Task A1 (every listing field) built,
  tested (528 checks green), **live and checked on thirtydayhomes.com on
  17 Sep 2026** (commits e3c2760, 39e0185). The localhost check
  found that Back discarded typed changes; fixed the same day (Back saves;
  review Edit returns to review). At the reviewer's request, **listing
  preview** (planned for A3) was built into A1 too: preview bar, staff
  Approve from the page, designed 404.
  **A2 (photos)** built and tested (563 checks green), **live and checked
  on thirtydayhomes.com on 17 Sep 2026** (commit 6c83769). HEIC stays
  refused (reviewer's decision). Photos are not required to submit a
  listing (unchanged since M1; open question to the reviewer).
  **A3 (listing lifecycle)** built and tested (639 checks green), **live
  and checked on thirtydayhomes.com on 17 Sep 2026** (commit 3aeeee1).
  **A4 (editing a live listing)** built, reviewed by independent readers
  (30 findings; the real ones fixed, each with a check), tested (688
  checks green), **live and checked on thirtydayhomes.com on 19 Sep
  2026** (commit c3ae4ce). Built with decision 8's proposed list; Rob's
  answer, if different, changes `material_fields()` only. Two TEST homes
  are left on live on purpose ("TEST – delete admin", "TEST 2 – delete
  new"); delete them before G1.
  Client report covers A1–A5 (not yet sent to Rob).
  **A5 (availability: blocked dates)** built and tested (verify-availability
  96; all suites green), browser-checked locally at desktop, 390px and
  320px; **live and checked on thirtydayhomes.com on 19 Sep 2026** (commit
  3e19b64).
  **B1 (map locations)** built and tested (verify-geocode 71; all suites
  green), browser-checked locally at desktop, 375px and 320px with no maps
  key; reviewer's localhost check passed 20 Sep 2026, **committed 20 Sep
  2026; waiting for push and live check**. Real lookups wait for Rob's
  Google Cloud access and a key in wp-config.
- **H3 property gallery, 5–6 Oct:** the reviewer selected Version B. Theme
  0.57.6 now has the 60/40 desktop mosaic, reduced tablet/phone entries and
  focused viewer; `verify-photos.php` is 42/0, the 37/37 browser walk, 4/4
  boundary-focus check and landlord draft preview passed, and the full
  23-suite gate is green. The reviewer approved it on localhost and it was
  included in the acceptance commit on 6 Oct; it awaits the user's push and
  live check.
- **From Rob, 18 Sep:** Twilio login (upgraded), Google Cloud login, the
  hospital list (11, incl. Greensburg and Washington → two new cities
  needed), 14 test addresses, a test phone, "hold off" on #15, and the
  business address he wants: support@thirtydayhomes.com (Hostinger
  mailbox, not connected — DNS is in Cloudflare).
  Later on 18 Sep: all 11 hospitals go on the site; the two test
  addresses completed; decisions 7–11 approved; A2P legal name
  (Thirty Day Homes LLC), EIN and address sent (kept out of the repo).
- **From Rob, 21 Sep:** the Cloudflare login and the login of the Gmail it
  sits under (a second address, not the Google Cloud one). Our own
  Cloudflare account is now a Super Administrator on his account, so DNS
  no longer depends on his password. His Gmail still cannot be opened from
  here: Google sends the verification code to his other address.
- **Mail DNS, 21 Sep:** MX (`mx1`/`mx2.hostinger.com`) and SPF added in
  Cloudflare and live. Every mail record is "DNS only"; only the site's A
  and CNAME are proxied. DMARC still to add. **The mailbox itself is
  blocked:** Hostinger has no email subscription for the domain and
  claiming the free one fails with "There is an active order for this
  Domain". With the team lead for Hostinger support. Nameservers must stay
  at Cloudflare whatever support suggests.
- **From Rob, 22 Sep:** searching ZIP 15226 answered "No homes matching
  15226". Logged as R44, corrected R18, and fixed the same day as task C6.
- **Register audit against the handoff, 22 Sep** (prompted by R44, because
  a row had been closed without proof). Three things were missed, none of
  them code:
  - **R45 — A2P 10DLC was never submitted.** Due 17 Sep. Rob sent the
    legal name, EIN, address and contact on 18 Sep and nothing was filed.
    Vetting takes 1–3 weeks. Without it D4 cannot send, criterion 6 fails
    and the payment gate's "real-phone SMS test evidence" cannot exist.
    **The only open item that can slip the milestone on its own.**
  - **R46 — the privacy policy has no SMS section**, which blocks R45:
    `/privacy/` is still the placeholder that *says* a lawyer must cover
    SMS consent, frequency, opt-out and retention. Carriers reject a
    campaign on exactly that.
  - **R47 — no staging**, though handoff §2 makes "Staging first"
    non-negotiable and every M2 acceptance line reads "on staging". Every
    task so far has been accepted on the live client site. R42c has been
    waiting on a Hostinger login since 20 Sep.
- **Still waiting on Rob:** answers to 12, 13, 14 and 16; Milestone 1
  approval on Upwork is still not recorded. See
  `MILESTONE-2-CHECKLIST.md` §1.
- **Client updates:** weekly on Tuesdays (23 Sep, 30 Sep, 7 Oct, 14 Oct);
  video meeting 30 Sep 2026.
- **Outside the code, still open:** no staging (R47), no off-site backups,
  attorney text for Terms/Privacy/Fair Housing (R46 is the urgent part),
  USD pricing on live Stripe, business mailbox, and the Geocoding server
  key still to rotate (R43).

---

## 18. Known issues and drift

Found in a full code read on 17 Sep 2026. Items marked **fixed** were
closed by a later task; the rest stand. Raise them with the reviewer
before fixing — most belong to a planned task.

### Behaviour

1. ~~Membership does not hide listings~~ — **fixed in E1**: `Enforcement`
   listens to `tdh_membership_changed` and `tdh_inactive_member_ids`.
2. ~~`Visibility` does not cover the `/city/` archive~~ — **fixed in E1**:
   `tdh_city` is in `is_listing_query()`.
3. ~~Landlords cannot resubmit a "changes requested" listing~~ — **fixed
   in A3**: the wizard opens sent-back, live and paused homes too.
4. ~~A crafted request can create drafts beyond the plan quota (gate lets
   "full" through for new drafts)~~ — **fixed 23 Sep 2026** (Phase A
   pass): the handler now refuses every gate reason; an existing draft
   never needed the exception, since the gate answers `''` for it.
5. ~~`_tdh_available_from` cannot be cleared from the wizard~~ — fixed in
   A5 (19 Sep 2026): the field moved to step 2 and a blank clears it.
6. ~~Staff moderation notices for "expired" and "missing" never display~~
   — **fixed in A3** (the renderer's `errors` key is used).
7. Expired nonces on staff member/facility forms redirect home and lose the
   message.
8. Landlord unread-inquiry counts are capped by the fetch limit (4 / 50).
9. ~~Moderation does not check the listing's current status before
   approving~~ — **fixed 23 Sep 2026** (Phase A pass): Approve and Request
   changes both refuse anything not `pending` with a `not_pending` notice.
   Before that, a stale queue page could publish a home the landlord had
   deleted (a trashed post set to `publish` comes back from the bin, live),
   re-stamp "Live since" on a live home, or take a live home down.
10. Staff have no profile view (`?view=profile` shows the overview).
11. `Render` finds pricing, contact and fair-housing pages by slug, as does
    `Checkout::pricing_url()` — renaming those slugs breaks links.
12. Checkout blocks active and past-due members but not "Cancelling" ones.
13. Stripe events for the other mode get 200 and are never retried.
14. Member delete uses a browser `confirm()` dialog (facility delete asks
    in the page since B1).
15. The demo "Reset" does nothing (missing `seed-dev-data.php`, no listener).
16. Demo personas have no membership and do not own the seeded listings.
17. Sample listings still carry demo badges and a 4.9 rating; the card
    renders them.

### Tests and tools

18. Several suites never exit non-zero on failure; `verify.bat` can say
    "Done." with failures on screen.
19. `verify-contact` deletes every contact inquiry on the site during cleanup.
20. `optimise-images.php` reads a folder that does not exist and re-encodes
    lossily in place.
21. The deploy workflow's "plugin changed without a version bump" warning
    never runs (shallow checkout); the theme version pair is not checked.
22. `deploy.sh` may skip its DB snapshot over non-interactive SSH (PATH).

### Documents

23. `tools/DEPLOY.md` contains literal server connection details — should be
    removed (rule: no secrets in docs) — and says "never re-run the importer",
    which conflicts with the "Pages and menus" step.
24. `WALKTHROUGH.md` contains a local password in plain text.
25. `DESIGN-SYSTEM.md` understates the stylesheet (hex values, rem literals,
    more breakpoints exist).
26. `README.md`, `BUILD_STATUS.md`, `DEMO_IMPLEMENTATION_PLAN.md`,
    `WALKTHROUGH.md`, `WEBSITE_FEEDBACK_IMPLEMENTATION.md`,
    `docs/ThirtyDayHomes-Development-Plan.html` are historical — see §19.

---

## 19. Documents index

| Document | Status | Use |
|---|---|---|
| PROJECT-HANDBOOK.md | **Current** | This file |
| CLAUDE.md | **Current** | Working rules, loaded by Claude Code automatically |
| MILESTONE-2-PLAN.md | **Current** | Task cards, decisions, requests register, progress |
| MILESTONE-2-CHECKLIST.md | **Current** | Tick-list for M2 |
| MILESTONE-1-CLOSEOUT.md | **Current** (rules) / history | Rules in full, standards, M1 record, gotchas |
| ThirtyDayHomes_WordPress_Developer_Handoff.md (+ .pdf) | **Authoritative** | The contract |
| Milestone-1-Report.docx, Milestone-2-Report.docx | Client-facing | Reports sent to Rob |
| tools/DEPLOY.md, tools/BACKUPS.md | Mostly current | Ops detail (see known issue 23) |
| themes/thirtydayhomes/DESIGN-SYSTEM.md | Mostly current | Token rules |
| DEVELOPMENT_PLAN.md | Reference | Original technical plan and rationale; schedule superseded |
| README-wp.md | Partly current | Local setup and architecture; "not built yet" list is stale |
| BUILD_STATUS.md | Historical | M1 tracker as of 30 Aug |
| WALKTHROUGH.md | Historical | M1 review guide as of 30 Aug |
| DEMO_IMPLEMENTATION_PLAN.md | Historical | React demo plan, pre-WordPress |
| WEBSITE_FEEDBACK_IMPLEMENTATION.md | Historical | Client's 16 Aug comments as done in the React prototype (most are M2 tasks now) |
| README.md | Historical | React prototype readme |
| src/, index.html, package.json, public/ | Reference | The approved React prototype — design source only |
| 30 days/*.docx | Source | Client's original MVP spec and Instaquirk's delivery plan |
| docs/ThirtyDayHomes-Development-Plan.html | Historical | 27 Aug HTML plan |
