# Milestone 2 — the build plan, read this first

**Purpose of this file:** the step-by-step plan for Milestone 2, written so
that any developer or any new AI session can pick up the next task without
being re-briefed: what the contract asks for, what already exists, what is
missing, in which order to build it, how each task is checked, and what
the client still has to provide.

**Companion file:** `MILESTONE-1-CLOSEOUT.md` holds the rules in full, the
architecture map, the environment, the gotchas and Milestone 1 history.
This file does not repeat them; it points to them.

**Keep it current.** When a task lands, update §10. A plan that says
"not started" for something that is live is worse than no plan.

Last updated: 16 September 2026. Kickoff: morning of 17 September 2026.

---

## 0. Rules — unchanged, and in force on every task

The ten rules in `MILESTONE-1-CLOSEOUT.md` §0 apply without change. The
short form, so nobody has to open two files to start:

1. **Do only what was asked.** One task from §5 at a time. Nothing extra.
2. **Finish, hand over, stop.** No next task until the words **"100% OK"**.
3. **No commit before "100% OK".** A screenshot is not the signal.
4. **Never `git push`.** A push deploys to the live client site.
5. **Milestone 2 scope only** (§1). Anything else goes in §6 as a request,
   never into the code.
6. **Verify on localhost, never on live.**
7. **Client-facing text is short.** Done / how to check / not included.
8. **Replies are brief.**
9. **Mirror every changed file** to `New folder\thirtydayhomes`, never
   `.github/`.
10. **CSS is scoped.** Child combinators; no bare `span`/`small`/`b`/`p`
    descendants; one section never restyles another.

Two rules that are new for Milestone 2:

11. **Every hand-over carries the filled three-standards checklist** (§0b).
    A hand-over without it is not finished, however good the code is.
12. **Secrets live in `wp-config.php` constants**, never in the repo, the
    theme, Elementor, browser code, or this file. Map keys, SMS
    credentials and the like are pasted by the user into `wp-config.php`
    on each environment; the code only reads `defined()` constants.
13. **Every hand-over includes a very simple localhost check guide.**
    Numbered steps in plain words. The exact clickable localhost link for
    every page (`http://localhost/thirtydayhomes/...`). Which account to use,
    with its password or the "Viewing as" bar choice. What you should see
    after each step. One step that tries to break it. How to check the
    phone width. No code and no jargon — the reviewer should not need to
    ask "where do I go, how do I check".
14. **Keep the client report current.** `Milestone-2-Report.docx` in the
    repo root has three sections only: *Completed so far*, *How to check
    on the live site*, *Still to come in Milestone 2*. When a task is
    "100% OK" and live, move it from *Still to come* into *Completed* and
    add its live check. Edit the words in
    `tools/client-report/milestone-2.php`, then rebuild with
    `D:\xampp\php\php.exe tools\client-report\make-report.php milestone-2`.
    Client-short: no reasoning, no test counts, no internal detail.

---

## 0b. The three standards are a gate, not a guideline

Current full text, with the pillar plan to write before any code:
`AGENTS.md` §4 (history: `MILESTONE-1-CLOSEOUT.md` §0b). They were asked for by the
client's group so that they stop being re-raised on every feature. From
Milestone 2 onward they are **enforced per task** by this hand-over
format. The agent fills it in; the reviewer checks it before "100% OK".

### The hand-over message, every time

```
Hand-over: <task id> — <title>

What was built
  Two to four lines. What a user can now do that they could not before.

Check it on localhost — very simple, rule 13
  Account: <email> / <password>, or "Viewing as" → <persona>
  1. Open http://localhost/thirtydayhomes/<page>   → you see …
  2. Click / type …                                 → it does …
  3. Break it: …                                    → it says …
  4. Phone: Chrome → F12 → Ctrl+Shift+M → iPhone    → it still …
  (repeat for each role the task touches, every page as a full link)

A. Journey       Entry · Orientation · Primary action · Completion ·
                 Continuation · Recovery · Mobile+keyboard · Role boundary
                 → one line each: what happens, or "n/a — why"

B. Corner cases  empty · one · many · zero results · over limit ·
                 wrong owner/type · lapsed mid-task · partial success ·
                 double submit · back button · slow/offline · session lost
                 → one line each: handled how, or "n/a — why"

C. Design        tokens only · states: empty/loading/success/error/
                 disabled/pending · focus, labels, targets · in-product
                 confirm · human wording · motion & reduced-motion ·
                 desktop/tablet/phone with long content
                 → one line each

Roles walked     visitor · new landlord · active landlord ·
                 past-due landlord · staff · administrator

Tests            <suite>.php: N passed, 0 failed · verify.bat green
Files            list, each mirrored
Commit message   ready — NOT committed
```

"n/a" is allowed only with the reason. "Handled" without saying how is
not an answer. If a row needed real work, one line of evidence: the
message shown, the state drawn, the test that covers it.

### Definition of done for a Milestone 2 task

- The whole journey works from the interface: entry, action, validation,
  storage, notification, and the resulting dashboard or public view
- Every row of A, B and C above has an answer
- Walked as every role the task touches, including the ones it must
  refuse
- A `tools/verify-*.php` suite covers it and `verify.bat` is green
- No street address, private contact detail or credential reaches public
  markup, REST, the browser, or a log
- Works at phone, tablet and desktop widths
- Files mirrored; hand-over sent; then **stop**

---

## 1. What Milestone 2 is

From `ThirtyDayHomes_WordPress_Developer_Handoff.md`, Milestone 2 — Core
Marketplace Build. **45 % of the contract, 18 working days estimated.**
Paid on written acceptance of the whole core flow.

### Deliverables, in the contract's own groups

| Group | The contract asks for |
|---|---|
| Listing management | Create, save draft, edit, preview, submit, pause, delete. States: pending approval, live, paused, rejected, membership-hidden. Full fields, categorised amenities, fees, availability, contact preferences, Fair Housing acknowledgment. Multi-image upload with validation, ordering, deletion, compression, responsive display. Approved edits publish immediately; material edits return to moderation. |
| Public marketplace | Search with price, bedrooms, bathrooms, type, pet policy, availability, and sorting. List and map presentation. Property page with gallery, key facts, description, amenities, approximate location, nearby facilities, fees, availability, inquiry actions. Exact address never public. |
| Medical-facility proximity | Admin adds/edits/deactivates facilities with coordinates. Property addresses geocoded and stored securely. Distance from each property to each facility. Search sorts/filters by facility proximity. Property page shows the three nearest within 15 miles (count configurable). |
| Inquiry, email, SMS, dashboard | Inquiry stored → appears in the landlord dashboard with unread/read → visible to admin → email to landlord (optional admin copy) → SMS to the landlord's **verified** phone → every attempt logged without exposing credentials. Renter form: name, email, phone, move-in date, expected stay, message, consent, validation, spam protection. Clear sending / success / validation / email-failure / SMS-failure behaviour. A notification failure never loses a stored inquiry. |
| SMS specifics | Environment-based credentials, verified sender, US A2P registration, client-approved template, phone normalisation, consent and opt-out, delivery logging, rate limiting, test mode, no keys in the repo. Not two-way chat. |
| Membership enforcement | Listings hidden after the grace period when membership is inactive; data intact; eligible listings restored on renewal. |

### What the client was told in writing on 9 September 2026

The reply sent to Rob commits Milestone 2 to **"landlord-managed
availability connected to date-based search, plus the map/list search
experience and property-location maps."** That is a block-out calendar,
not a single available-from date. It also proposed email verification
(#6) as an M2 addition, and placed the full moderation workflow in M3.

### The ten acceptance criteria (§7 maps each to a task)

1. Eligible landlord creates a complete listing with images and amenities,
   previews it, submits it
2. Moderation state visible to landlord and administrator
3. Approved property appears in search and on its page, correct and
   responsive
4. Search, all filters, sorting, list/map and empty states work together
5. Facility distances accurate for the agreed Pittsburgh test set
6. A renter's inquiry is stored, shows in the dashboard, sends an email,
   sends an SMS to the agreed real phone
7. Email and SMS failures logged with safe feedback and no lost inquiry
8. Membership lapse and restoration hide and restore listings correctly
9. Elementor widgets render real plugin data in editor and front end
10. No critical or high defects

---

## 2. Where Milestone 2 stands on 16 September 2026

Honest inventory. "Built" means usable from the interface today.

| Area | Built | Partly | Not built |
|---|---|---|---|
| Listing wizard | 4 steps: basics (title, private address, ZIP, neighborhood, type, city, rent, deposit, beds, baths, available-from) · stay length + 78 amenities in 10 groups · up to 10 photos with previews, "Uploading…", added-count confirmation, Remove · review + Fair Housing + submit. Save-draft on every step. Edit from the dashboard for drafts and pending. Quota gate; staff bypass. | Photo **ordering, cover choice, compression, alt text** | Application / pet / **cleaning** fee controls, utilities Yes/No/Partial, pet policy, parking, sq ft, rooms, backyard, lease term, contact preferences — **the schema already has all of these except cleaning fee** (`class-tdh-fields.php`); only the wizard controls and the review rows are missing |
| Listing states | draft · pending · live · paused · rejected · billing-hold all **registered** (`class-tdh-statuses.php`); labels in the dashboard | Approve / Request changes from the marketplace portal (`class-tdh-moderation.php`) | **Preview, pause/resume, delete, resubmit after changes requested, editing a live listing** — paused and billing-hold are reachable only through wp-admin today |
| Availability | `_tdh_available_from` field, shown on card and page | — | **Blocked date ranges**, calendar view, date search |
| Facilities | Marketplace admin has add/edit/delete with **manual** latitude/longitude; 5 Pittsburgh hospitals seeded with coordinates | — | **Geocoding** from address; deactivate is a checkbox but delete uses a browser `confirm()` (standard C says in-product) |
| Proximity | Haversine distance, nearest facility cached per listing, sand band on cards (`class-tdh-proximity.php`) | — | Listings have **no coordinates** unless typed in wp-admin; the property page hook `tdh_listing_proximity` has **no listener**; no facility sort/filter |
| Search | Keyword on name, neighborhood, city, type, ZIP (`class-tdh-search.php`); two honest empty states; hero collects move-in/move-out and the archive **says** dates are not applied yet | — | **Filters, sorting, chips, clear-all, mobile drawer, URL state, date search, facility selector, map view** |
| Property page | Fees, deposit, available-from, min stay, amenities, "approximate map area" text | — | **Gallery/lightbox, real map, three nearest facilities, availability calendar, inquiry form** (a placeholder notice sits where the form goes) |
| Inquiry | The Contact form is the working template: store first, email second, honeypot, per-IP rate limit, typed values kept on error, `_tdh_notified` status (`class-tdh-contact.php`). `tdh_inquiry` type and schema exist. Landlord dashboard has an inquiries panel and the marketplace admin an inquiries view | — | **The renter inquiry form itself**, unread/read, detail view, landlord email, delivery log, retries, SMS, phone verification, consent, opt-out |
| Email | Branded From, SMTP settings screen, failure/success listeners, dev capture to temp files (`class-tdh-mail.php`, `class-tdh-smtp.php`) | Live still rides Gmail SMTP; SPF/DKIM/DMARC blocked on the client | — |
| Membership | Stripe webhook → `Membership::apply()`; statuses none / active / past-due / cancelling / expired; quota; `GRACE_DAYS = 7` constant | — | **Nothing listens to `tdh_membership_changed` and nothing filters `tdh_inactive_member_ids`.** Public visibility today depends on post status alone. Grace → hold → restore is entirely unbuilt |
| Elementor | Hero, property grid, audience, pricing, contact, about, how-it-works widgets over `TDH\Render` | — | Search/results widget, nearby-facilities widget; editor rendering check for M2 widgets |
| Tests | 8 suites, 436 assertions, green at M1 close (`tools/verify.bat`) | — | A suite per M2 task (§5 names them) |

Versions at start: plugin **0.3.4**, theme **0.28.0**.

### Small defects found while writing this, to fix inside the task that touches them

- The wizard writes `_tdh_fair_housing_ack` but the schema declares
  `_tdh_fair_housing_ack_at`, so the admin screen shows the
  acknowledgment as empty → **A1**
- `Visibility::is_listing_query()` covers type, neighborhood and amenity
  archives but not the **city** archive (`/city/…`) → **E1**
- The hero placeholder promises "Neighborhood, ZIP, or hospital"; hospital
  does not match anything yet → **C3**
- Facility delete uses `confirm()` → **B1**
- The dashboard listing list stops at 20 with no pagination → **A3**

---

## 3. Before any code — the morning of day 1

These are not code and most have lead times longer than the code.

| # | Action | Who | Why now |
|---|---|---|---|
| 1 | Confirm Milestone 1 is **approved on Upwork** | User | Rob wrote "should be good to get milestone 2 started" on 15 Sep; approval is the record |
| 2 | **Open the SMS gateway account and submit A2P 10DLC brand + campaign registration** (Twilio recommended; account in Thirty Day Homes LLC's name, owned by Rob; needs EIN, legal address, website, sample message, opt-in description) | Rob with our help | Carrier vetting is **one to three weeks and nobody controls it**. It is the only M2 item that can slip the milestone on its own. Acceptance criterion 6 needs a real SMS. |
| 3 | **Choose the maps provider and open the billing account** (§4, decision 1) | Rob | Needed by task B1 in week 2 |
| 4 | Send Rob the decision list in §4 as one short message | User | Several tasks are shaped by the answers |
| 5 | Ask Rob for the facility list with addresses and 3–5 test property addresses in Pittsburgh | User | Acceptance criterion 5 measures against these |
| 6 | Get Rob's **written OK** that email verification (#6) is M2 | User | It is only in our sent mail until he says so |

Client dependencies with the date each one starts to block:

| Needed | Blocks | Needed by |
|---|---|---|
| A2P registration submitted | D4, criterion 6 | **17 Sep** |
| Maps provider account + API key in `wp-config.php` | B1 onward | 19 Sep |
| Facility list, test addresses | B1, B2, criterion 5 | 22 Sep |
| SMS template approved (wording in D4) | D4 | 26 Sep |
| Real test phone number | D4 evidence | 1 Oct |
| Answers to §4 | A1, A4, A5, C4, D1, E1, F1 | rolling; earliest 18 Sep |

---

## 4. Decisions that need Rob's written answer

Send as one message. Each has our recommendation so he can just say yes.

| # | Decision | Our recommendation | Shapes |
|---|---|---|---|
| 1 | Maps and geocoding provider | **Google Maps Platform** (Geocoding API + Maps JavaScript API) on a Google Cloud project Rob owns; monthly free credit covers launch volume; key restricted by referrer and by API. Mapbox is the alternative. | B1, C4 |
| 2 | SMS gateway | **Twilio**, A2P 10DLC, one local number or a Messaging Service | D4 |
| 3 | SMS wording | `New ThirtyDayHomes inquiry for {listing}. View it: {link} Reply STOP to opt out.` — no renter details in the text | D4 |
| 4 | Renter phone on the inquiry form | Optional | D1 |
| 5 | Admin copy of inquiry emails | Yes, to the business mailbox once it exists; until then the WordPress admin address | D3 |
| 6 | Grace period after a failed payment | 7 days (the code's current constant) | E1 |
| 7 | Nearest facilities on a property page | 3 within 15 miles, count configurable | B2 |
| 8 | Which edits to a **live** listing go back to review | Back to review: title, description, photos, address/ZIP/city/neighborhood, property type, bedrooms, bathrooms. Publish immediately: rent, deposit, fees, availability, stay length, amenities, utilities, pet policy, parking, sq ft, rooms, backyard, lease term, contact preferences | A4 |
| 9 | What is shown for location | Neighborhood + ZIP in text; on maps a **circle of roughly a quarter mile around a deliberately offset point**, never a pin; exact coordinates never leave the server | C4 |
| 10 | Email verification (#6) | M2. Unverified accounts can sign in and see the dashboard but cannot subscribe or submit a listing until verified; resend link available | F1 |
| 11 | Multi-listing discount (10 % for two, 15 % for three — from the 16 Aug comments) | **Not in the handoff.** Treat as a change request: it is a Stripe pricing change, not a site feature. Decide separately; do not build in M2 unless he adds it in writing | §6 |
| 12 | Listing allowance per plan | Confirm the numbers configured in Stripe; the enforcement messages quote them | E1 |
| 13 | Drive time beside distance | Distance only for M2. Drive time needs the Distance Matrix API, a second cost line; can be added later as a setting | B2 |

---

## 5. The build order — one task, one hand-over, one "100% OK"

Ordered by dependency and risk. Each task is a complete journey that the
user can check on localhost on its own. Do not merge tasks to save time;
a merged hand-over cannot be checked and cannot be approved.

Target pace, if reviews turn around the same day. Carrier approval for SMS
runs in parallel from day 1.

| Week | Dates | Tasks |
|---|---|---|
| 1 | 17–23 Sep | A1 · A2 · A3 · A4 · A5 |
| 2 | 24–30 Sep | B1 · B2 · C1 · C2 |
| 3 | 1–7 Oct | C3 · C4 · C5 · D1 · D2 |
| 4 | 8–14 Oct | D3 · D4 · E1 · F1 |
| 5 | 15–17 Oct | G1 — self-QA, walkthrough, design corrections, report |

Suite names below are new files in `plugins/thirtydayhomes-core/tools/`,
each added to `verify.bat`.

---

### Phase A — Listing management (landlord side)

#### A1 · Every listing field in the wizard, the review and the property page

**Goal.** A landlord can enter everything the contract lists, sees it all
on the review step, and a renter sees it with human labels on the page.
Closes Rob's #5 (fee fields) and #7 (utilities).

**Build.**
- Schema: add `_tdh_cleaning_fee` (number, pricing group). Change
  `_tdh_utilities` from textarea to a select — `yes` "Included", `no`
  "Not included", `partial` "Partially included" — with an optional
  `_tdh_utilities_note` (string) for "which ones"
- Wizard step 1 gains a "Pricing & fees" block: deposit, application fee,
  pet fee, cleaning fee (all optional, cleared when emptied, as deposit is
  today). Step 2 gains property details (sq ft, total rooms, parking,
  backyard, lease term), stay terms (utilities select + note, pet policy
  three-state) and **contact preferences** (name, email, SMS-capable
  phone, preferred method) prefilled from the account
- Review step lists every field with "Not given" for blanks, never a
  blank row
- Property page: fees table shows only fees that exist; utilities and pet
  policy as sentences ("Utilities partially included — heat and water")
- Fix the Fair Housing key mismatch (§2) and show the timestamp in the
  admin meta box

**Journey.** Entry from step 1 → the new fields explain themselves with
help text → Continue → review shows them → submit unchanged.
**Corner cases.** Zero fee vs no fee ("$0" is a statement; empty is
"None"); half bathrooms; 13-week stay; utilities "partial" with an empty
note (still valid); phone typed with spaces and dashes (normalised, shown
back tidy); a landlord who clears a fee on edit (deleted, not kept).
**Design.** New blocks use the existing `.lform-field` grid; select
chevrons already exist; help text under, not beside, on phones.
**Tests.** `verify-listing-form.php` grows: each new field saved, cleared,
rendered on review; utilities note only when partial.
**You check as** active landlord (`testuser1@example.com`): add a home,
fill every field, review, submit; open the public page as a visitor.
**Commit title.** `Every listing field reaches the wizard, the review and the page`

#### A2 · Photos: cover, order, alt text, compression

**Goal.** Photos can be reordered, one is the cover, each has a
description, and large phone photos are stored at a sensible size.

**Build.**
- Store order in attachment `menu_order`; cover = `_thumbnail_id`
- Step 3 gains per-photo: "Make cover", Move up / Move down (buttons, so
  it works by keyboard), Remove, and a short alt-text field ("Describe
  this photo for renters who cannot see it")
- Server side: `big_image_size_threshold` at 2000 px, JPEG quality 82,
  strip EXIF location data (a home photo carrying GPS is an address leak)
- Card and page read the cover; gallery order follows `menu_order`

**Corner cases.** Removing the cover promotes the next photo; the last
photo removed leaves a "Photo coming soon" state, not a broken image;
ten photos (limit stated); a 25 MB HEIC from an iPhone (named refusal);
reorder then Back (order persisted server-side, not only in the DOM).
**Design.** Thumbnails in the existing `.lform-preview-grid`; controls
appear on focus as well as hover; no drag-only interaction.
**Tests.** `verify-photos.php` (as built): order persists, cover switches,
alt saved, EXIF gone, HEIC refused, step 3 controls, gallery template.
**As built (17 Sep 2026).** Uploads are re-drawn before WordPress stores
them (`Listing_Photos::prepare_upload`) rather than via
`big_image_size_threshold`, because WordPress keeps the uploaded original
and that original would still carry GPS. Controls are always visible (not
on hover) in their own tiles. The property page gallery and viewer (R35
display) were built here, since "gallery order follows `menu_order`" needs
a gallery.
**You check as** active landlord: upload three, reorder, change cover,
remove one, check the card and page.
**Commit title.** `Photos get a cover, an order and a description`

#### A3 · Listing lifecycle in the dashboard: preview, pause, resume, delete, resubmit

**Goal.** From the dashboard a landlord can do everything to a listing
that the contract lists, and every state says what it means and what
happens next.

**Build.**
- New `class-tdh-listing-actions.php` (plugin) handling `listing_pause`,
  `listing_resume`, `listing_delete`, `listing_resubmit`; owner or staff
  only; nonce; redirects back with a flag, copy in the renderer
- ~~Preview~~ — **built early inside A1 on 17 Sep 2026 at the reviewer's
  request**: `Visibility::permits_preview()`, `TDH\Listing_Preview` bar
  (staff Approve / Request changes, landlord Edit), designed `404.php`.
  A3 only needs to reuse the bar for paused and resumed homes
- Pause ↔ Resume: `publish` ↔ `tdh_paused`. Resume from paused does not
  re-enter review
- Delete: in-product confirmation panel ("Delete Shadyside Loft? Its 4
  photos and 2 inquiries stay in your records for 30 days"), then trash,
  not hard delete; trashed listings are restorable by staff
- Changes requested: the dashboard shows the reason
  (`_tdh_rejection_reason`) with "Edit and resubmit"; resubmit sets
  pending and stamps `_tdh_submitted_at`; staff are notified
- Per-state line under each listing: "In review — usually within one
  business day", "Live since 3 Oct", "Paused by you", "Hidden — payment
  failed, update your card to restore"
- Pagination on the listing list

**Journey.** Each state has exactly one primary action beside it.
**Corner cases.** Pause during review (refused, explained); delete the
only listing (empty state returns, with Add your home); resume while
membership lapsed (refused, points at billing); wrong owner (same
response as missing); double-click Delete (second request finds nothing
to do, no error); Back after delete (no resubmission).
**Design.** Confirmation is a panel in the page, focus moves into it,
Escape cancels; destructive button uses `--color-danger`; status chips
use existing `.status` variants.
**Tests.** `verify-listing-actions.php`: each transition, each refusal,
preview visibility per role.
**You check as** active landlord, then as `admin`, then as a visitor
trying the paused listing's URL.
**As built (17 Sep 2026).** No separate `listing_resubmit` action:
"Edit and resubmit" opens the wizard's step 4, so a resubmission passes the
same checks and Fair Housing confirmation as a first submission. The
confirmation panel is a server-rendered state (`?delete=ID`), not a
script-only dialog. "Request changes" now requires a note (it had none).
Staff are emailed on every landlord submission, marked "Resubmitted" when
it returns. Staff restore trashed homes from wp-admin (restored as draft).
Tests: `verify-listing-actions.php` (76). The demo `Active landlord` persona now gets an active plan (it had none, so Resume never showed).
**Commit title.** `Landlords manage a listing's whole life from the dashboard`

#### A4 · Editing a live listing without silently unpublishing it

**Goal.** A landlord can edit a live listing. Minor edits go live at once;
material edits (decision 8) go back to review — and the landlord is told
**before** saving, not after.

**Build.**
- The wizard opens live listings for owners (today only drafts/pending)
- A `Listing_Form::material_fields()` list (from decision 8; filterable)
- On save of a live listing: diff the material fields; if any changed,
  show a confirmation step — "These changes take Shadyside Loft offline
  until it is reviewed again (usually within a day). Save and submit, or
  go back?" — then set pending; otherwise save and stay live with
  "Updated — live now"
- Staff edits keep the status (already true for submit; extend to saves)

**Corner cases.** Changing only photos (material); changing rent and
description together (material wins, one confirmation); confirm then
Back (no double transition); the listing is paused during the edit
(stays paused, no review); membership lapses mid-edit (draft saved,
submission refused with the billing message).
**Tests.** `verify-listing-actions.php`: minor edit keeps publish;
material edit needs confirmation and yields pending; staff edit keeps
status.
**You check as** active landlord: edit rent (stays live), edit title
(warned, goes to review); as admin: approve it again.
**As built (17 Sep 2026).** The "told before saving" is a real
confirmation page: the step's POST is held (transient, 10 min) and only
"Save and submit for review" saves it; "Go back and discard changes"
discards it. What actually sends a home to review is a comparison of the
stored material fields before and after the save, so the rule holds even
for a request that skips the page. Photo files cannot be held, so the photo step of a live home carries a
tick that unlocks the photo controls. A paused home's material edits are
remembered and Resume goes to review (the row says so first). Decision 8's
proposed list is the default; the filter `tdh_material_fields` takes Rob's
final answer. Tests live in `verify-listing-actions.php` (+48).
**Commit title.** `Edit a live listing: small changes go live, big ones ask first`

#### A5 · Availability: available-from plus blocked dates

**Goal.** A landlord marks when the home is free and blocks out the
periods it is rented; the property page shows it plainly. Foundation for
date search (C2).

**Build.**
- New `class-tdh-availability.php`: `_tdh_blocked_ranges` (array of
  `{from, to}` dates), validation (to ≥ from, no overlap, merged when
  adjacent), `is_free(listing, start, end)` honouring available-from,
  blocked ranges and minimum stay
- Wizard step 2 "Availability" block: available-from (already exists),
  plus "Unavailable dates" rows with Add / Remove
- Dashboard: a per-listing "Availability" quick edit so a landlord does
  not need the wizard to block a month
- Property page: "Available from 1 Nov · Unavailable 15 Dec – 5 Jan",
  and a simple month view (server-rendered, no library) marking blocked
  days

**Corner cases.** Range in the past (allowed, shown as history, not in
search); range that swallows available-from; single-day range; a range
typed backwards (swapped with a note, or refused — pick refuse, say why);
twelve ranges (list scrolls, no overlap between labels).
**Design.** Date inputs already carry the calendar icon fix; month view
uses tokens; reduced motion means no animated transitions between months.
**Tests.** `verify-availability.php`: validation, merge, `is_free()`
truth table.
**You check as** active landlord: add two ranges, save; as visitor: the
page shows them.
**As built (19 Sep 2026).** "Available from" moved from step 1 to a new
Availability section at the top of step 2, with the unavailable periods;
a blank date now clears it (known issue 5). Periods are stored as plain
lines in `_tdh_blocked_ranges` so the wp-admin box stays readable.
Backwards, half-filled, unreal or 3-years-ahead dates are refused by name
and kept in their row; overlapping or touching periods are joined and the
page says so; past periods are kept as history, never shown to renters;
limit 20. The dashboard quick edit is an in-page panel
(`?availability=ID`) on live, paused, in-review and changes-requested
rows. The property page shows the first real move-in day (skipping taken
periods and gaps shorter than the minimum stay), upcoming periods, and a
12-month calendar that scrolls sideways (Previous/Next with JS). Cards and
the price box use the same first move-in day. `Availability::is_free()` is
the rule C2 should call. Tests: `verify-availability.php` (96).
**Commit title.** `Landlords block out the dates a home is taken`

---

### Phase B — Location and proximity

#### B1 · Geocoding: addresses become coordinates, failures are visible

**Goal.** Saving a listing or a facility address fills latitude and
longitude automatically; when it cannot, staff see it and can fix it;
nothing with an unknown location is approved by accident.

**Build.**
- New `class-tdh-geocoder.php`: provider behind an interface (Google
  first, per decision 1); key from `TDH_MAPS_SERVER_KEY`; result cached in
  meta; called on save only when the address fields changed; sets
  `_tdh_geocode_status` ok / failed / manual; never blocks the save
- Facilities: address → coordinates on save; manual override stays
- Marketplace admin listings view: "Location not found" badge with a
  coordinates field for manual entry; the Approve button is disabled with
  the reason while status is failed
- Replace the facility delete `confirm()` with the in-product panel from
  A3
- A WP-CLI command `wp tdh geocode --missing` to backfill sample data

**Corner cases.** Provider down (status stays unknown, retried on next
save, staff badge says "not yet"); ambiguous address (take the top result
only if the provider marks it precise; else failed); a PO box; a key
missing from `wp-config.php` (a clear admin notice, not a fatal);
rate-limit response (backoff, logged).
**Security.** The exact coordinates are private meta already; keep them
`show_in_rest => false`.
**Tests.** `verify-geocode.php` with a fake provider: success, failure,
no-key, unchanged address does not re-geocode.
**You check as** admin: add a facility by address only, see coordinates
fill; as landlord: add a home with a bad address, see the staff badge.
**As built (19 Sep 2026).** `TDH\Geocoder` watches the address fields
wherever they are written and looks up once at the end of the request —
never in the way of the save; an unchanged address is not looked up again.
Only the building counts (a ZIP- or street-level answer is "not found").
Four states in words: Location found · set by hand · not found · not
checked yet. Only "not found" holds approval (queue button greyed with the
reason, preview bar offers Set location, server refuses); "not checked"
(no key, service down) never does. Staff set a location by pasting the
coordinates as Google Maps copies them, under the row; typed points win
until the address changes. A "not found" address is also said to the
landlord on the listing form. The facility save fills coordinates and says
how it went; facility delete now asks in the page. Empty/0,0 points no
longer produce distances. Built and tested without a key (fake provider);
the real Google path waits for Rob's project access and
`TDH_MAPS_SERVER_KEY`, then `wp tdh geocode` backfills. Tests:
`verify-geocode.php` (71).
**Commit title.** `Addresses turn into coordinates, and failures say so`

#### B2 · The three nearest facilities on the property page

**Goal.** A renter sees which hospitals are near and how far.

**Build.**
- `Proximity::nearest_n(listing, n, radius_miles)` reusing the cached
  approach; `tdh_listing_proximity` renders a list: name, type, distance
  ("0.8 mi"), no drive time (decision 13)
- Count and radius as options in the marketplace admin "Listing setup"
  view (defaults 3 and 15)
- Card band keeps showing the single nearest

**Corner cases.** No facilities within radius ("No major facilities
within 15 miles" — say it, do not hide the section); an un-geocoded
listing (section omitted, never "0.0 mi"); exactly one facility (singular
wording); an inactive facility (excluded).
**Tests.** `verify-proximity.php`: ordering, radius cut-off, inactive
excluded, count setting respected.
**You check as** visitor on a sample listing.
**Commit title.** `Property pages name the nearest hospitals`

**As built (20 Sep 2026, plugin 0.10.0 / theme 0.35.0).**
- `Proximity::nearest_n( id, count, radius )`; `render_list()` on
  `tdh_listing_proximity`. The card band is unchanged and keeps showing the
  single nearest with no radius.
- Lead line "3 medical facilities within 15 miles" (singular at one), then
  name, type in words and distance per row. No drive time.
- No location → nothing printed, the map note stands alone. Nothing in
  range → "No medical facilities within 15 miles of this home." Never a
  fallback to every facility, never "0.0 mi".
- Count and radius are options set on **Listing setup** (1–5 facilities,
  1–100 miles). Out of range is refused with the limit named and the typed
  numbers kept; anything else that writes them is clamped on read.
- Cache is now a list (`_tdh_nearest_facilities`, 10 rows), so changing
  either setting rebuilds nothing. It is also dropped when a coordinate is
  written outside a post save — which is what `wp tdh geocode` does, and
  what would otherwise have left live distances stale.
- New filter `tdh_facility_query_args`. 50 checks in `verify-proximity.php`.

---

#### R42b · Event and failure log with a staff screen (internal, pulled forward from D3)

**Goal.** Every important action and failure is written to one log staff
can read, so a problem is diagnosed from its line instead of reproduced by
hand. Milestone 2 acceptance #7 (email and SMS failures logged) is met by
the same table when D3 and D4 arrive.

**Build.**
- Table `wp_tdh_log` (Activator, dbDelta): time (UTC), level, feature,
  event, message, masked context JSON, user, object type + id, source
  `file:function`, request id so one request's lines group together.
- `TDH\Log::write()` and `debug/info/warning/error/critical()`; `mask()`
  for emails and phones; keys that look like secrets scrubbed to
  `[hidden]`; a feature registry (labels = the tabs) with filter
  `tdh_log_features`. Logging never throws and never blocks the action it
  describes.
- Lines added now to what exists: sign-in refused and throttled; member
  created, updated, deleted; listing submitted, approved, changes
  requested, rejected, paused, resumed, deleted, live edit sent to review;
  address lookup found / not found / no answer / key refused, location set
  by hand; facility saved, deleted; email sent / failed; contact form sent
  / failed; Stripe webhook received, applied, refused; hospitals setting
  changed.
- Screen in wp-admin → **Listings → Logs**, beside Email delivery and
  Payments, administrators only — not the client's portal (reviewer's
  decision during the check: "the client doesn't need this"). One tab per
  feature plus **System status**; filters level · text · last 24 h / 7 /
  30 / 90 days; 50 rows a page; **▸ View** expands the context without
  JavaScript; a worded empty state; WordPress's red bubble on the menu
  item with the week's errors.
- Silent during test runs (`TDH_LOG_SILENT`), so fixtures never become
  lines.
- Retention: daily event `tdh_log_purge`, 90 days, in batches.
- System status: environment and demo mode, maps key present and the last
  lookup, email last sent and last error, Stripe mode and last webhook,
  SMS (not built yet — D4), last purge and the next scheduled run.

**Corner cases.** A context value that cannot be JSON-encoded (stringified,
never fatal); a message over the column width (cut, full text kept in
context); a secret-looking key in context (scrubbed); a deleted user or
post (id kept, shown as "deleted"); no rows (empty state per tab, never a
blank table); a write that fails (swallowed, counted, never breaks the
action); a landlord at `?view=logs` (their own dashboard, no hint the view
exists); large tables (indexes on feature, level and time; purge in
batches of 1000).
**Tests.** `verify-log.php`: write and read, masking and scrubbing, the
registry, filters and paging, purge by age, error counts, hooks produce
their lines, the screen (tabs, rows, context, empty state, System status),
staff only.
**You check as** administrator (the screen), landlord (refused), and by
causing one failure — a wrong password — and finding its line.
**Commit title.** `Every important action and failure is logged, and staff can read it`

---

### Phase C — Search and results (renter side)

#### C1 · Filters, sorting, chips, clear-all, mobile drawer, URL state

**Goal.** The search page becomes a real search: price range, bedrooms,
bathrooms, property type, pet policy, sort — all in the URL, all working
together, with an empty state that names the constraint. Closes the pet
filter half of #5.

**Build.**
- `class-tdh-search.php` grows a `Filters` step at `pre_get_posts` 20:
  `min_price`/`max_price` (meta), `beds` (≥), `baths` (≥), `type` (term),
  `pets` (yes / considered / no), `sort` (newest, price-asc, price-desc;
  "closest" arrives in C3)
- `Render::filter_bar()`: a form that submits GET; chips for each active
  filter with an × that removes only that one; "Clear all"; result count;
  on phones a drawer (button "Filters (3)") with focus trap, Escape, and
  focus returned to the button
- Empty state names the filters: "No 2-bedroom homes under $2,000 that
  allow pets" with each chip removable right there
- Never fall back to all homes on zero matches (keep the `[0]` guard)

**Corner cases.** Min greater than max (swap silently and say so); a
filter value that is not in the list (ignored, not an error); pagination
keeps filters; a shared URL reproduces the search; the city archive
`/city/pittsburgh/` respects the same filters; browser Back restores the
previous filter state; the drawer open while rotating the phone.
**Design.** Chips, drawer and range inputs get tokens and focus rings;
no layout shift when the count updates.
**Tests.** `verify-search.php` grows: each filter alone, combined, zero
match, swap, pagination.
**You check as** visitor on desktop and phone width.
**Commit title.** `Search filters, sorting and an empty state that says why`

**As built (20 Sep 2026, plugin 0.12.0 / theme 0.37.0).**
- `Search::filters()` reads and validates `min_price`, `max_price`,
  `beds`, `baths`, `type`, `pets`, `sort` from the URL; `apply()` adds the
  meta and tax clauses and the order at priority 20, on `/homes/` and on
  the city, property-type, neighborhood and amenity archives.
- Prices the wrong way round are swapped and a notice says which range
  was used. Nonsense values are ignored (beds=abc, type=nonsense,
  pets=maybe, sort=foo, price 0 or beyond $100,000). A home with no price
  is left out of a price sort and a price range, and is back otherwise.
- `Render::filter_bar()` is one GET form (real labels, kept values, the
  keyword carried as a hidden field, "Show homes", "Clear all filters");
  `Render::filter_chips()` prints a chip per active thing — keyword, each
  filter, a non-default sort — whose × removes only that one; Clear all
  keeps the keyword.
- `template-parts/listing-results.php` is shared by the listing archive
  and three new taxonomy templates, so `/city/pittsburgh/` is the same
  search page (it used to fall through to the bare `index.php`). Count:
  "12 homes" · "3 homes matching “q”" · "3 homes match your filters".
  Empty: "No 2+ bedroom homes under $2,000 that allow pets matching “q”."
  with the chips right there and "Show all homes".
- `assets/filters.js` turns the form into a drawer on phones (backdrop,
  Escape, focus trap, focus back to the button, closes when the screen
  widens) and submits the sort on change. Without it the form is a stacked
  row that still works.
- Pagination keeps every filter (WordPress carries the query string).
  83 checks in `verify-search.php` (was 22). "Closest" sort is C3; dates
  are C2.
- **Fixed on the day it went live (21 Sep 2026, plugin 0.12.1).** A
  negative price had its minus sign stripped with the dollar sign and the
  commas, so `?min_price=-99` came back as a real "From $99" chip. A minus
  sign now refuses the value outright, and three checks cover it. Found by
  the break-it step of the check guide, not by the suite.
- The breadcrumb on those taxonomy archives reads "Home › Find a home ›
  Pittsburgh"; it used to fall back to the document title, which carried
  the site name into the trail.

#### C2 · Search by move-in and move-out dates

**Goal.** The hero's dates finally do something: only homes free for the
whole stay, and long enough for it, are shown. Removes the "arrives with
the search module" note.

**Build.**
- `Availability::is_free()` from A5 applied to the candidate set; results
  as `post__in` (or `[0]`)
- Card shows "Available for your dates" when dates are set; the page
  shows the requested stay against the calendar
- Validation: end after start, stay ≥ 30 days ("Stays are 30 days or
  longer — try a later move-out date")

**Corner cases.** Start only (treated as open-ended, min-stay applied);
dates in the past (refused, explained); a home free but for 29 days of a
30-day minimum (excluded, and the empty state says why); a blocked range
touching the requested start (boundary is inclusive — decide and test).
**Tests.** `verify-search.php`: truth table against A5's fixtures.
**You check as** visitor: home page dates → results; try a blocked window.
**Commit title.** `Date search shows only homes free for the whole stay`

**As built (21 Sep 2026, plugin 0.13.0 / theme 0.38.0).**
- `start` and `end` — the hero's own field names — are read and validated
  by `Search::stay()`, and are also two fields at the head of the filter
  bar ("Move in", "Move out"), filled from `Search::typed()` so a refused
  date stays on screen to be corrected.
- `Search::free_ids()` asks `Availability::is_free()` once per published
  home and sets `post__in`, intersected with the keyword's own narrowing
  (empty → `[0]`). It cannot be a meta query: the taken periods are lines
  of text and the shared turnover day lives in `fits()`. Past 500 homes it
  still answers correctly and logs `listings/date_scan_large`.
- A move-in with no move-out is open-ended: each home is asked whether it
  is free from that day for **its own** minimum stay, or 30, whichever is
  longer — a fixed 30 would hide every home that only takes longer lets.
- Refused, each with its own sentence: a move-out with no move-in; a
  move-in in the past (moved to today and said); a stay wholly past; a
  move-out on or before the move-in; under 30 nights; past the 3-year
  horizon. Nonsense is ignored silently, like every other filter. A
  refused stay never empties the page and never narrows it in silence.
- The count reads the dates back ("2 homes free 1 Nov – 5 Dec 2026"), the
  empty sentence carries them, and the two dates are **one** chip and one
  on the Filters badge, because to a renter they are one stay.
- A card in a dated search says "Free for your dates" and carries the
  dates on its link; the property page repeats the stay above the calendar
  with a tick or a cross, re-checked there rather than trusted from the URL.
- `assets/filters.js` keeps the move-out picker at least 30 days after the
  move-in but never clears a typed date; the minimum travels as
  `data-tdh-min-stay` so the number lives in PHP.
- 132 checks in `verify-search.php` (was 86). The placeholder notice
  "Filtering by move-in date arrives next" is gone from the results part.

#### C3 · Choose a hospital, sort by distance

**Goal.** A renter picks a facility and sees the closest homes first, with
the distance to **that** facility on every card.

**Build.**
- Filter bar gains a facility select (active facilities, grouped by
  city); `sort=closest` and `within` (miles)
- Distance computed per candidate listing to the selected facility in
  PHP; `post__in` in distance order; un-geocoded listings last with no
  distance shown; results larger than 500 fall back to a warning in the
  log (note the future custom-table upgrade path)
- Card band shows "1.2 mi from UPMC Mercy" for the chosen facility
- Keyword search matches facility names too, so the hero placeholder
  becomes true

**Corner cases.** No homes within the radius (empty state offers a
larger radius); facility with no coordinates (not offered); two
facilities with the same name in two cities (grouped); sort by closest
with no facility chosen (falls back to newest, says so).
**Tests.** `verify-search.php`: order, radius, un-geocoded last, name
match.
**You check as** visitor.
**Commit title.** `Search by hospital: closest homes first`

**As built (21 Sep 2026, plugin 0.14.0 / theme 0.39.0).**
- `facility` (a facility id), `within` (miles or `any`) and `sort=closest`
  in the URL. Choosing a hospital filters to the radius **and** makes
  closest the order; a sort picked afterwards still wins. `sort=closest`
  with no hospital falls back to newest and the page says why.
- `Search::near_facility_ids()` measures every published home to the
  **chosen** hospital with `Proximity::miles()`, sorts, and hands the
  ordered ids to `post__in` with `orderby => 'post__in'` — no second
  query. `Proximity::render_band()` now asks Search for the choice, so
  the card reads "1.5 mi from UPMC Mercy" for the hospital the renter
  picked, never for the home's own nearest. That mismatch was the demo's
  defect this task exists to remove.
- Un-geocoded homes are left out of a radius (a distance we cannot state)
  and go last under `within=any`, with no band rather than "0.0 mi".
- The select offers only hospitals with coordinates, grouped by city
  through the `tdh_city` taxonomy. The radius select is disabled until a
  hospital is chosen and says so in its own label.
- Empty: "No homes within 15 miles of UPMC Mercy." with a primary link
  naming the next radius out, and "any distance" past the widest step.
- The keyword matches hospital names, so the hero's "Neighborhood, ZIP,
  or hospital" is true at last.
- 178 checks in `verify-search.php` (was 132). **A trap found by the
  suite:** a registered boolean meta stores false as `''`, so a
  switched-off hospital read identically to one that never had the field;
  `metadata_exists()` tells them apart. Recorded in the handbook's
  mistakes list.

#### C4 · Map view and property-location map

**Goal.** Results can be seen as a map with approximate markers; the
property page shows an approximate area. Closes the mapping half of #10.

**Build.**
- Theme `assets/map.js`, loaded only on the archive and single templates
  when a key exists; browser key from `TDH_MAPS_BROWSER_KEY` (referrer
  restricted; a different key from the server one)
- Server sends **approximate** coordinates only: a deterministic offset
  of up to ~250 m derived from the listing id, rounded; the page draws a
  circle, never a pin. Exact values never reach the browser
- List / Map toggle on the results page, remembered in the URL
  (`view=map`); map markers open a compact card; list remains the
  default and the no-JS path
- Property page: one circle labelled "Approximate area"
- Marker and circle colours from tokens; reduced motion disables pan
  animation

**Corner cases.** No key configured (toggle hidden, list only, admin
notice); zero results in map view (empty state, not an empty map); 200
markers (clustered or capped with "showing 100 of 200 — narrow your
search"); keyboard user (list is fully usable; map controls are
enhancement); a listing without coordinates (in the list, not on the
map, with a note).
**Privacy check.** Page source and network responses contain no exact
coordinates and no address. This is a release blocker in the handoff.
**Tests.** `verify-search.php`: offset is deterministic and within bounds;
`verify-privacy.php`: exact `_tdh_lat` absent from page output.
**You check as** visitor; view source.
**Commit title.** `Map view with approximate locations, never exact ones`

**As built (21 Sep 2026, plugin 0.15.0 / theme 0.40.0).**
- New `TDH\Maps`: the browser key, the approximate point, the payload and
  the counts. `Maps::approximate()` moves the real point 80–250 m in a
  direction taken from `wp_hash()` and rounds to three decimals; the page
  draws a 400 m circle, wider than the error. **Deviation from the card,
  on purpose:** the card said to derive the offset from the listing id
  alone, which is reversible by anyone who reads the code; salting it with
  the site's own keys keeps it deterministic and unguessable.
- `Search::view()` reads `view=map`. It is not a filter: no chip, no
  badge, survives Clear all, and rides in `args()` so every chip and page
  link keeps the renter on the map. No key means no toggle and no map.
- `Render::view_toggle()` is two real links, not a script. In map view the
  cards are still rendered and clipped out of sight, so a screen reader
  and a failed map both still have the list.
- `assets/map.js` draws circles, never pins, with an info card built
  through the DOM so a home's name cannot break out of the markup. Street
  View is off — it would let someone walk the circle looking for the door.
- **The map is styled, not stock.** Google's default is built for finding
  a restaurant and every shop shouts over our homes, so businesses and
  transit are off and the ground is the site's own cream; hospitals stay,
  because on this marketplace they are why anyone opens the map, and parks
  stay because they tell a renter what a street feels like. Each home is
  two rings — a wide faint halo, which is the honest edge of what we know,
  and a tighter one for the eye to land on — under a navy price tag. The
  tag is a custom `OverlayView`, not a Google marker, for two reasons: a
  marker is a pin, and an overlay is ordinary HTML that takes the site's
  own type and colours.
- **Found by the browser pass:** when Google refuses a key it paints its
  own English "Oops! Something went wrong — see the JavaScript console"
  over the container. The panel is now emptied first and carries our own
  sentence and a "Show the list" link.
- `verify-privacy.php` grew from 9 to 32 checks: the published point is
  not the real one, is 50–370 m away, is the same on every request, and
  differs between homes at one address; the payload, the panel, the
  property-page circle and **the whole rendered property page** contain no
  exact latitude, no longitude and no street address, while the ZIP is
  still shown. `verify-search.php` 198 (was 178).
- **A trap it caught:** the first version of that page test called
  `the_post()` before including the template, so the template's own loop
  found nothing, the body never rendered, and every privacy assertion
  passed against an empty page. Recorded in the handbook's mistakes list.
- **The key cost a day, and the lesson is worth more than the fix.** The
  installed browser key was the wrong key entirely (the unrestricted Maps
  Platform one, read off a photo), and Rob's typed website key carried
  seven Cyrillic look-alikes plus a lowercase `l` where the real key has
  `I`. The way out was not guessing: Google answers `InvalidKeyMapError`
  for a key it has never seen and `ApiTargetBlockedMapError` for a real
  key aimed at the wrong service, so a single Geocoding request tells you
  which you are holding. Comparing the *working* server key against Rob's
  typed version showed he writes `l` for `I`; the same substitution on the
  website key produced one Google recognises. Map confirmed drawing on
  localhost, 21 Sep 2026.

#### C5 · Elementor widgets for search results and nearby facilities

**Goal.** Acceptance criterion 9: the M2 blocks are Elementor widgets that
render real data in the editor and on the front end.

**Build.**
- `class-tdh-search-results.php` widget over `Render::filter_bar()` + the
  results loop (controls: heading, default sort, per page, show map
  toggle)
- `class-tdh-nearby-facilities.php` widget over `Proximity::nearest_n()`
  for the single template (controls: heading, count override)
- Editor styles registered as the other widgets do; no editor-only CSS
- Seed the archive/single Elementor layouts to use them (importer,
  additive, edit-guarded)

**Corner cases.** Widget placed on a page that is not a listing (renders a
polite editor-only note, nothing on the front end); Elementor inactive
(shortcodes still work).
**Tests.** `verify-page-widgets.php` grows.
**You check as** admin in Elementor: edit the search page, change the
heading, see it live.
**Commit title.** `Search and nearby-facility blocks are Elementor widgets`

**As built (21 Sep 2026, plugin 0.16.0 / theme 0.41.0).**
- `Search_Results` and `Nearby_Facilities` in the ThirtyDayHomes category,
  confirmed in a real editor: the panel lists "Search results" and
  "Nearby hospitals" beside the nine existing widgets.
- **Criterion 9 is met literally: real data in the editor.** Checked in a
  real Elementor session — the canvas shows the heading typed in the panel,
  the live search bar and the live filter row with real homes under it.
  The first version of this task failed that: the results band rendered
  only on the listing archive, and an archive can never be an Elementor
  page without Pro, so in the editor it could only ever explain itself.
  It now runs its own query off the archive (`Search::own_query()`, flagged
  with `Search::OWN_QUERY` so the same filter rules apply), which also
  makes the card's `per_page` control meaningful again.
- The hospitals band still needs a home. With none in context a **visitor
  sees nothing**, and the editor previews the newest home that has a
  location above a line saying it is an example. Both halves checked in a
  real browser.
- The results widget renders the theme's own template part through
  `get_template_part()` with the editor's choices as `$args`, rather than a
  copy of the markup, so an Elementor page and `/homes/` cannot drift.
- `[tdh_search_results]` and `[tdh_nearby_facilities]` added as the
  primitives the widgets wrap, and listed in the admin shortcode reference.
  `Proximity::list_html()` now returns what `render_list()` printed, so the
  property page and the widget share one copy of the markup.
- **Two faults found by the reviewer, both real.** First, a page holding
  the results band was squeezed into the theme's narrow reading column and
  no Elementor setting could widen it; `tdh_is_wide_body_page()` now asks a
  filter and the plugin answers for its own block. Second, and worse, that
  page loaded neither `filters.js` nor `map.js`, so the Filters button did
  nothing on a phone and the map panel said "Loading the map" for ever.
  Both scripts now follow the block through
  `Search::page_holds_results()`, and three checks cover it.
- `verify-page-widgets.php` grew from 44 to 78 checks: registration and
  category, every control present, nothing rendered on the wrong page,
  everything rendered on the right one, an edited heading appearing, the
  switches removing their bands, the count override and its clamp, the
  shortcode matching the widget word for word, and that neither widget
  offers a control for data the site measures.

**Two deviations from the card, both deliberate.**
1. **No "default sort" control.** The sort is in the URL, where a renter
   set it, and a layout that overrode it would fight the page's own Sort
   by box. `per_page` is offered and works wherever the widget runs its
   own query.
2. **Nothing seeded.** The card says to seed the archive and single
   Elementor layouts. There are none to seed: those pages are theme PHP
   templates by design (handoff §3.1), and an Elementor layout for an
   archive needs the Theme Builder, an Elementor **Pro** feature this
   project has deliberately not used (`class-tdh-registrar.php` docblock).
   The widgets are available for any page someone chooses to build; there
   is no layout to put them in automatically.

---

### Phase D — The inquiry pipeline

The most specified feature in the contract. Order is fixed by it: **store
first, notify second**; the renter's success depends only on the store.

#### D1 · The inquiry form and its own success screen

**Goal.** A renter sends an inquiry from a property page and lands on a
success screen that says what happens next.

**Build.**
- New `class-tdh-inquiry.php`: handler for `tdh_inquire` on
  `template_redirect`; fields name, email, phone (per decision 4), move-in
  date, expected stay (30 / 60 / 90 days / 13 weeks / longer), message,
  consent checkbox carrying the rules version; honeypot; per-IP and
  per-(email, listing) rate limits; nonce; listing must be
  `Visibility::is_public()`
- Store as `tdh_inquiry` with the schema keys, `_tdh_status = new`,
  `_tdh_read = false`; fires `tdh_inquiry_received`
- Success is **its own screen** (`/homes/<slug>/?inquiry=sent` renders a
  distinct panel replacing the form): "Your message is with the owner of
  Shadyside Loft. They usually reply within a day by email or text." plus
  "Browse more homes"
- Errors keep typed values (the Contact form's stash pattern via
  `Accounts::visitor_key()`); the address is echoed as typed
- Hook `tdh_listing_inquiry_form` renders it; the placeholder notice goes

**Corner cases.** Listing paused between page load and submit (refused,
"this home is no longer available", other homes offered); double submit
(button disables; server treats an identical inquiry within ten minutes
as already sent); Back after success (no resubmission — POST-redirect-GET);
the owner inquiring about their own home (allowed, harmless);
5,000-character message (limit stated); expired nonce (message, values
kept).
**Design.** Sending state on the button; success panel uses success
tokens; the form is usable one-handed on a phone; the date field opens
the native picker.
**Tests.** `verify-inquiry.php`: store, refusals, dedupe, rate limit,
success flag.
**You check as** visitor; then confirm the record in wp-admin →
Inquiries.
**Commit title.** `Renters send an inquiry and land on a real confirmation`

#### D2 · Inquiries in the landlord dashboard and the marketplace admin

**Goal.** A landlord sees new inquiries, opens them, and the count clears;
staff see all of them.

**Build.**
- Landlord "Inquiries" view: newest first, unread emphasised, filter
  all / unread / archived, pagination; opening one marks it read and
  reveals the renter's contact details; Archive / Unarchive; the overview
  card shows the unread count and the nav a badge
- Marketplace admin "Inquiries" view: every inquiry with listing,
  landlord, received time, read state, delivery state (from D3)
- Ownership: `tdh_inquiry` capability mapping routes to the listing's
  author; wrong owner gets the same response as missing

**Corner cases.** Inquiry for a deleted listing (still readable, marked
"listing removed"); 300 inquiries (pagination, no slow query — index by
meta or add a custom table if the count query is slow); a landlord with
two homes (listing name on every row); contact-form messages (kind =
contact) stay staff-only.
**Design.** Read/unread as weight and a dot, not colour alone; empty
state "No inquiries yet — homes with photos and a description get the
most".
**Tests.** `verify-inquiry.php`: read marking, ownership, counts.
**You check as** the landlord who owns the sample home, then as admin.
**Commit title.** `Inquiries arrive in the dashboard, unread until opened`

#### D3 · Email to the landlord, with a delivery log and retries

**Goal.** Every inquiry emails the landlord (and optionally admin); every
attempt is logged; failures are visible to staff and invisible to the
renter.

**Build.**
- New table `wp_tdh_notifications` via `Activator::install_tables()`:
  id, inquiry_id, channel (email/sms), recipient (masked), status
  (queued/sent/failed), provider_ref, response (no secrets), attempts,
  created, updated
- New `class-tdh-notifications.php`: on `tdh_inquiry_received` send the
  landlord email synchronously (the record already exists), log the
  result; on failure schedule retries at +10 min and +60 min via
  `wp_schedule_single_event`; mark terminal failure after the third
- Recipient: the listing's `_tdh_contact_email` if set, else the account
  email; Reply-To the renter; subject names the listing; body links to
  the dashboard, not the renter's details in full
- Admin copy per decision 5
- Marketplace admin inquiries view shows a "Email failed" badge and the
  last response; a "Resend" action for staff

**Corner cases.** SMTP not configured (logged as failed with the reason;
staff notice); the landlord's contact email is invalid (fall back to the
account email, log both); cron disabled on the host (retries also run on
the next admin page load); the same inquiry logged twice (unique
constraint per inquiry + channel + attempt).
**Tests.** `verify-notifications.php` using `Mail::latest_capture()` and
a forced failure: log rows, retry scheduling, resend.
**You check as** visitor (send), then look in the temp mail capture
folder, then as admin see the log.
**Commit title.** `Inquiry emails are sent, logged and retried`

#### D4 · SMS: verified phones, consent, template, opt-out, log, test mode

**Goal.** A landlord who has verified a phone and opted in gets a text for
each inquiry; everything about it is logged; the site works fully with
SMS switched off.

**Build.**
- New `sms/class-tdh-sms.php` with a provider interface and
  `sms/class-tdh-twilio.php`; constants `TDH_SMS_ENABLED`,
  `TDH_TWILIO_SID`, `TDH_TWILIO_TOKEN`, `TDH_TWILIO_FROM`,
  `TDH_SMS_TEST_RECIPIENTS` (comma list; in test mode only these are
  texted, everyone else is logged as "test mode — not sent")
- Landlord profile: phone in E.164 (US normalisation), "Text me when I
  receive an inquiry" consent with the legal sentence, **verify by
  six-digit code** (10-minute expiry, five attempts, resend limited);
  unverified numbers are never texted; status shown plainly
- Template exactly as approved (decision 3); no renter data in the text
- Opt-out: Twilio handles STOP at the carrier; an inbound webhook REST
  route records it and the profile shows "Texts paused — reply START to
  resume"; a status-callback route updates the log row
- Rate limit per landlord per hour; beyond it, email only, logged
- Failure never affects the renter or the email

**Corner cases.** Number verified then changed (verification resets);
landlord opts out then a new inquiry arrives (email only, log says
opted out); provider outage (failed with response, retried once, staff
badge); a non-US number (refused at entry with the reason); test mode on
live by mistake (admin notice on every page while it is on).
**Evidence for acceptance.** One real inquiry → one real text on Rob's
test phone → screenshot + log row. Keep them in `docs/` for the M2
report.
**Tests.** `verify-sms.php` with a fake provider: normalisation, gating
on verification and consent, template, opt-out, rate limit, test mode.
**You check as** landlord: verify a phone (test mode, your own number),
then send yourself an inquiry as a visitor.
**Commit title.** `Landlords get a text for each inquiry, once they say so`

---

### Phase E — Membership enforcement

#### E1 · Grace period, hide, restore — and the dashboard explains it

**Goal.** Criterion 8. A failed payment starts a grace period; after it,
the landlord's homes are hidden without losing anything; paying restores
exactly the homes the system hid. The landlord always knows which state
they are in and what to do.

**Build.**
- New `class-tdh-enforcement.php` listening to `tdh_membership_changed`:
  → past-due: stamp `_tdh_grace_started`, listings stay live;
  → expired / none: every `publish` listing → `tdh_billing_hold` with
  `_tdh_held_at`; pending stays pending;
  → active: only `tdh_billing_hold` listings → `publish`; clear the grace
  stamp; **paused stays paused**
- Daily event `tdh_enforce_memberships`: past-due older than
  `Visibility::GRACE_DAYS` → hold; expiry passed without a webhook → hold
- Filter `tdh_inactive_member_ids`: users not active and not inside
  grace, so even a stray `publish` row from a lapsed member is hidden.
  Add the city archive to `is_listing_query()`
- Moderation refuses to approve while the owner's membership is inactive,
  with the reason
- Dashboard banner per state: "Payment failed — your 2 homes stay visible
  until 24 Sep. Update your card" (link to the Stripe portal); "Your homes
  are hidden until a plan is active"; after restore, "Your homes are back
  online"

**Corner cases.** Landlord pauses a home during grace (stays paused after
renewal); a new listing submitted during hold (draft allowed, submit
refused); webhook arrives twice (idempotent); renewal before the cron
runs (no hold ever applied); cancellation that runs to period end (live
until the date, then hold); data check — meta, terms, photos untouched
by hold/restore.
**Tests.** `verify-enforcement.php` driving `Membership::apply()`
directly: every transition, idempotency, the cron, data intact,
`tdh_inactive_member_ids`.
**You check as** the past-due persona in the "Viewing as" bar, then flip
to active and back.
**Commit title.** `Lapsed memberships hide homes after grace and restore them on renewal`

---

### Phase F — Reclassified and agreed extras

#### F1 · Email verification at registration (#6) — only after Rob's written OK

**Goal.** New accounts prove their address before they can pay or submit.

**Build.** Token email at registration (24-hour expiry), `verified_at`
user meta, banner with Resend (rate-limited), checkout and submit gated
per decision 10, verification link works while logged out and signs the
user in; existing accounts grandfathered as verified.
**Corner cases.** Expired link (fresh one offered); link clicked twice
(second says "already verified"); email changed in profile (re-verify);
mail capture in dev (the link is in the temp file).
**Tests.** `verify-portal.php` grows.
**Commit title.** `New landlords verify their email before they pay`

#### F2 · Anything from §6 that Rob approves in writing

Each becomes a task card here, with the same structure, before any code.

---

### Phase G — Close-out

#### G1 · Self-QA, walkthrough, design corrections, report

- Run the whole battery; walk every criterion in §7 as renter, landlord
  and admin on localhost, then on live after the user's pushes
- SMS evidence attached
- Frontend designer review; agreed corrections only
- Short client report (three sections, as the M1 one)
- Update §10 here and §5 of the M1 file; record versions

#### G2 · Design system — design review part 3, batch 1 of 4

Source: the master design prompt audit of 28 Sep 2026
(`D:\fahad vi backup\ThirtyDayHomes-UIUX-Audit.md`, plan in
`ThirtyDayHomes-Design-Guideline.md`). Every screen scored 5–8; the failed
MUSTs below capped each one at 7. Rollback: tag `design-v1-baseline`.

**Goal.** Tokens and CSS only, no change to what any page does: every
screen leaves the 7 cap in one pass.

**Build.**
- Type scale on the 12-step system: no token under 12 px (`--text-3xs`
  and `--text-2xs` were 10 and 11), `--text-sm` 13 → 14, `--text-lg` 17 →
  18, `--text-h5` 21 → 20, `--text-xl` 25 → 24, `--text-h4` 26 → 28; card
  facts, helper text, inbox previews and small meta move to 14 px
- Contrast: gold text `--color-gold-deep` #a68631 (3.5:1) → #7a6222
  (5.8:1); `--color-gold-ink` → #775f1f; muted text → #5b6774; subtle →
  #66717e; every pill is tint + dark same-hue text ≥ 4.5:1
- Two-tone text: a new `--text-body` (#3e4956) for running text, headings
  stay ink; inputs never inherit browser black
- Lining, tabular numerals on every serif figure (prices, stats, dates)
- Shadow tokens on a soft, navy-tinted scale (sm / md / lg); the mobile
  nav's black 44 % shadow goes; listing cards carry the soft shadow at rest
- One filled primary per view: landlord card actions become an outlined
  card action plus text links ("View live page" is a link, "Edit" the
  action on a live home); the gold header CTA is outlined on Sign in and
  Create account
- Header: "Renter FAQ" → "How it works", "List your property" → "Pricing",
  the current page underlined in gold with `aria-current`; the footer
  wordmark is not underlined
- Hero: "Search homes" always the solid gold button; dates optional
- Pills 12 px, sentence case (no CSS capitalisation)
- No invented social proof: the card never prints a rating, the Rating
  field is removed, and an upgrade step saves then clears `_tdh_rating`
  and the subjective sample badges on every home (restorable from the
  saved option)
- American spelling in the six remaining strings

**Journey.** Unchanged for every role; entry, orientation and the primary
action get clearer. **Corner cases.** Re-shot at 320 for the new 12–14 px
floors; a home with no badge shows none; a missing token falls back to
inherit, never to unstyled output. **Design.** §15 of the master prompt
scored per screen; target 9. **Tests.** Existing suites (the manage-card
suite learns the new action class); `verify.bat` green; log count
unchanged. **You check as** visitor, `testuser1@example.com`, admin.
**Commit title.** `Design system: type floor, contrast, shadows and one primary per view`

#### G3a · Renter journey — design review part 3, batch 2 (first half)

Source as G2. The renter's path: search, the cards, the property page and
the home page. The information and account pages follow as G3b.

**Goal.** The search has one form and one filled button; a card reads
price → title → place → facts; the property page keeps the price and
**Ask the owner** in reach on every screen; nothing changes which homes
are found or what a form saves.

**Build.**
- **One search form.** `Render::filter_bar()` prints the keyword row
  inside the filter form (the `search-bar` row keeps its class for the
  widget switch), one filled **Show homes**, Clear beside the keyword; the
  filter panel becomes `.filter-drawer` inside the form (the phone drawer,
  with its own Show homes only there); "Sort by" leaves the filters for
  `Render::sort_control()` beside the result count, its own GET form
  carrying the current search; filters.js follows
- Results page: a `tdh-results` body class trims the gap under the
  banner; the no-homes-at-all state offers **List your home**
- **Card anatomy** (master prompt §9): rent largest top-left with the
  availability pill top-right (Available now · Available 6 Nov · Free for
  your dates), then title, place with pin, facts as icon + bold number +
  grey unit, the hospital band, and a **View home** text link pinned to the
  bottom
- **Property page:** facts as one line ("2 bedrooms · 1 bathroom · 1,050
  sq ft · 5 rooms") instead of four tiles; "Everything you need" split into
  **Included** (ticks) and **House rules** (neutral); the side column holds
  price, fees and a gold **Ask the owner** button and is sticky (about
  420px, so it can pin); the inquiry form moves under Close to care at
  `#inquire`; on phones a fixed bar shows the rent and **Ask the owner**
  until the form is in view (`detail.js`); the hospital heading says the
  real farthest distance ("3 medical facilities within 1.6 miles") as a
  sentence-case h3; "Phone (optional)" in words; a shorter consent line
- **Home page on phones:** the four audience cards as a 2 × 2 of icon and
  title; the three owner stats in one row

**Journey.** Renter: hero → results (one button) → sort beside the count →
card (price first) → home (price and Ask the owner always in reach) →
form → sent. Nothing else moves. **Corner cases.** No homes at all → a
button; no photo → placeholder; one facility → singular; a home with no
open dates → pill says so; the drawer without JavaScript stacks in place;
the sticky column never covers the form because the form is no longer in
it. **Design.** Re-scored per screen; target 9 on /homes/ and the property
page. **Tests.** verify-search (sort assertions move to `sort_control()`,
10 labelled fields), verify-inquiry (the pin is allowed now that the form
is outside the column), verify-proximity (real distance in the heading).
**You check as** visitor, `testuser1@example.com`.
**Commit title.** `Renter journey: one search form, price-first cards, a property page that keeps Ask the owner in reach`

#### G3b · Information and account pages — design review part 3, batch 2 (second half)

Source as G2. Pricing, About, How it works, Contact, Sign in, Create
account and the three legal pages. Nothing changes what a form saves or
who may do what.

**Goal.** Every page ends in one gold primary and, where two audiences
meet, an outlined second door; a stranger on Sign in knows it is for
landlords; a legal page reads as a legal page; nothing meant for staff is
printed to a visitor.

**Build.**
- **Pricing:** buttons say **Start with 1 home / 2 homes / 3 homes** and a
  line under each says what happens next and the monthly price ("Create
  your account, then pay $49 a month by card. Cancel any time." /
  "You'll pay $125 a month by card, through Stripe. Cancel any time.");
  button and line pinned to the card foot so the three line up; a
  **More than 3 homes? Contact us** line under the grid; the "prices not
  final" sentence is shown to staff only, as a staff note — the public
  keeps the plain discount line (prices await Rob, register). Card order
  stays ascending on every width (the sequence is the argument)
- **About:** the two doors end in a gold **Find a home** and an outlined
  **See membership options**, pinned level; the "Draft copy" line is
  staff-only
- **How it works:** the two track links become the same gold / outlined
  pair, pinned level across the columns; three owner questions join the
  FAQ (cost, review, pausing — all facts the product already keeps);
  the dark band's **Contact us** is full width on phones
- **Contact:** on phones the form comes first and the promise panel
  follows it; "Prefer email?" with the support address in the promise
  panel; chips keep 4.5:1
- **Sign in / Create account:** H1 **Landlord sign in**, and under the
  form "Looking for a home? You don't need an account — Find a home";
  Create account's subtitle names the price ("Free to open. Plans from
  $49 a month when you publish."); Phone and Company single column, the
  phone with a "why we ask" line; **Show / Hide** on password fields
  (`auth.js`, a plain link without it); 20px gold checkboxes in 44px rows;
  white bordered inputs; the breadcrumb leaves the auth shell; the panel
  logo is hidden on phones (the header has it) and both columns align to
  the top on desktop so the two headlines share a line
- **Legal pages:** a compact navy header without the photograph; a
  **Last updated** line from the page's own modified date; the two raw
  URLs in the Terms become links (`Legal_Pages` filters `the_content` on
  the seeded pages, and the seed itself is corrected); the "Still to
  come" / "Draft copy" notes are shown to staff only; no jump list (each
  page has two sections at most)

**Journey.** Visitor: any information page → one obvious door → Find a
home or the plans; landlord-to-be: Pricing → Start with N homes → Create
account (knows the price) → pay; renter on Sign in → told they need no
account → Find a home. **Corner cases.** Staff see the notes visitors do
not; a page with no contact page has no More-than-3 line; no JavaScript →
the password field is a plain field; a legal page edited in wp-admin keeps
its own date; the filter touches only the three seeded pages. **Design.**
Re-scored per screen; target 9. **Tests.** verify-page-widgets (staff-only
notes, owner FAQ, door classes), verify-portal (Landlord sign in, renter
line, toggle markup), verify.php (legal filter links and note for visitor
and staff). **You check as** visitor, then `tdh_audit_admin` for the
staff-only notes.
**Commit title.** `Information and account pages: one gold door per page, landlord sign-in, legal pages that read as legal pages`

#### G4a · Landlord portal screens — design review part 3, batch 3 (first half)

Source as G2. The landlord's portal: navigation, Overview, My listings,
Inquiries, Membership, Account details. The listing wizard follows as G4b.
Nothing changes what a landlord may do or what any form saves.

**Build.**
- **Phone navigation:** below 48rem a fixed bottom tab bar — Overview ·
  Listings · Inquiries · Plan · Account, icon and word, `aria-current`,
  the unread count on Inquiries; the sidebar and its bare chevron are
  hidden there and the top bar carries the logo
- **Overview:** H1 "Overview" (it matched nothing in the nav); figures in
  the sans face with lining numerals and a normal line height, "2 of 3";
  2 × 2 tiles on phones; avatar initials from first and last name
- **My listings:** status chips with counts (All · Live · In review ·
  Changes requested · Paused · Draft; zero ones hidden) that filter the
  list; no doubled "Your listings" heading — the allowance sits in the
  chip row; each card says "12 views · 0 inquiries"; on phones Pause and
  Delete sit in a labelled **More** menu (open without JavaScript)
- **Inquiries:** tabs only once there is an inquiry, with counts; the
  empty inbox ends with one action, "Improve your listings"
- **Membership:** the facts in one column on phones with values that no
  longer overprint; plan with its monthly price; "2 of 3 homes used";
  **Manage billing** with a line saying what the Stripe page does; the
  support line only when that page is unavailable; the band hidden here
  while the plan is simply active (it repeated the facts); an upgrade line
  at the limit
- **Account details:** one 600px column; white bordered fields; Show /
  Hide on the password fields; **Send code** waits for a number and the
  consent tick and says so; full-width buttons on phones

**Not in this task:** separate saves for details and password (the handler
takes one form; noted for later); card brand, last four and invoice list
on the page (they need the Stripe API; the Stripe page shows them).
**You check as** `tdh_audit_landlord` at 1440 and 390.
**Commit title.** `Landlord portal: a tab bar on phones, figures that read, My listings with filters, a membership page about the plan`

#### G4b · The listing form — design review part 3, batch 3 (second half)

Source as G2. The four steps of Add / Edit a home. Nothing changes what is
saved, what is required or what goes back to review.

**Build.**
- **A named stepper** (1 Basics · 2 Features · 3 Photos · 4 Review) in
  place of the thin bar; the current step marked; finished steps are links
  from the review page, where there is nothing unsaved to lose
- **One column at 640px:** only short related fields pair (city + ZIP,
  bedrooms + bathrooms, the fees, square feet + rooms, phone + preferred
  contact)
- **Units inside the fields:** "$" before every amount, "/ month" after
  rent, "sq ft" after square feet
- **Helper text under its field** at 14px, not right-aligned on the label
  line; placeholders read "e.g. …"
- **A live home's note in one line** with "Which changes need a review?"
  opening the list; the main button on a live or paused home says
  **Save and continue**
- **Step 2:** a running total beside Amenities ("12 selected")
- **Step 3:** the live-home permission as a full-width tappable panel; a
  locked drop zone says why; **Remove photo** in red; the cover says
  "Cover photo" as plain text, not a button-shaped block; captions as
  two-line boxes; the drop zone in the landlord's words; a live
  description counter; a nudge under five photos
- **Step 4:** the home's state as a pill at the top; a **Worth fixing**
  card (no amenities, no mobile number, under five photos, a short
  description) with a link to each; the cover photo and the opening of the
  description before the summary; buttons full width on phones, the main
  one on top

**Not in this task:** steppers for bedrooms and bathrooms (the number
fields already take arrows and typing); a separate "save and stay" on steps
2 and 3 (the handler moves on after a save; the next page confirms it);
hiding "Which utilities" for "Not included" (it would change what is saved:
"tenant pays electric" is a real answer there).
**You check as** `testuser1@example.com`: a new home, and Edit on a live one.
**Commit title.** `Listing form: a named stepper, one column with units, a review that says what is worth fixing`

#### G5a · The administrator's portal screens — design review part 3, batch 4 (first half)

Source as G2 (guideline batch 4, items 4.1–4.6). The six portal screens the
team works in: Overview, Listings, Members, Facilities, Inquiries, Listing
setup. Nothing changes who may do what, what any form saves, or what a
landlord or renter sees.

**Build.**
- **One primary per screen, in the header row on the H1 baseline:** **Add
  listing**, **Add member**, **Add facility** as filled buttons; the "+"
  cards go. The button opens the form panel (with `?add=1` when there is
  no script) and puts the cursor in the first field; the panel has Cancel
- **Overview:** the pending tile is the action tile, first, tinted while
  there is work ("2 homes waiting · Review now") and quiet when there is
  none; every tile links to its filtered list; **Approve** and **Request
  changes** on the queue rows, as on Listings; the health figures in the
  sans face; "Past due" says "needs follow-up" when above zero
- **Listings:** the queue's Approve is a green outlined button so the
  header keeps the one filled one; a waiting home is not listed twice
  while the queue shows it; the six location sentences collapse to the
  pill plus **Set location** on the row, with one banner above the list
  ("6 homes have no map point yet — they won't appear in distance search.
  Show them") and a **Needs location** chip; every chip carries its count;
  **Edit** and **View** at the end of each row; Approve and Request
  changes stacked full width on phones; the coordinates box says "e.g."
- **Members:** search by name or email and status chips with counts
  ("All 7 · Active 3 · No plan 4"), a count line, and a zero-results line
  that offers to clear the search; plan slugs become names ("Standard
  plan"); "No plan" as a neutral pill; a member over their allowance
  reads "7 homes · plan covers 2" with an **Over limit** pill; round
  avatars from first and last initials; the email on its own line with an
  ellipsis; the whole row opens the member at 2.75rem
- **Facilities:** a pill only when a facility is hidden from search or has
  no map point; otherwise the useful facts — "Hospital campus · 320 E
  North Ave, Pittsburgh 15212" and "Shown on 5 property pages"
- **Inquiries:** "4 messages · 3 unread"; unread rows in bold with a
  **New** pill (words, never the gold dot alone); the time on the right
  ("2 h ago", "28 Sep"); the renter's first line as the preview; the whole
  row is the link, no underline, names as typed
- **Listing setup:** the four cards become one compact list (icon · name ·
  "8 configured" · **Manage**, which opens the working manager for an
  administrator, or says who changes them for other staff); no "Milestone
  2" wording and no footer note; sans titles; the hospitals form at 40rem
  with narrow number fields carrying "facilities" and "miles" inside; the
  button says **Save hospital settings**

**Not in this task:** the wp-admin screens (Logs, the Listings table, the
Elementor notice) — G5b; the "Viewing as" persona on the review bar (local
review tool only); the live seed data ("Test Clinic", "fxnchf") — Rob's
OK, in the Later list.
**You check as** `instaquirk` at 1440 and 390.
**Commit title.** `Administrator portal: one primary per screen, an overview that acts, lists with counts, search and row actions`

#### G5b · wp-admin for the site administrator — design review part 3, batch 4 (second half)

Source as G2 (guideline items 4.7–4.8). The two WordPress screens only an
administrator opens: Listings › Logs and the Listings table. Nothing
changes what is logged, what a listing stores, or who may do what; a staff
member without `manage_options` is sent to the portal's Listings instead
of the WordPress table, which does the same job worse for them.

**Build.**
- **The Elementor notice** ("Want to shape the future of web creation?")
  dismissed once for the whole site, so no staff screen opens with a
  purple button as its loudest control
- **Logs:** the eleven feature tabs grouped into six (Homes & locations ·
  Members & payments · Messages · Sign-in · Settings · System), plus All
  and System status; on phones the groups are a **Section** select beside
  Show; a feature key in the address (`?tab=email`) still works. **Show**
  defaults to warnings and errors when any exist in the period, with a line
  saying so ("12 warnings and errors in the last 7 days · Show everything");
  warning and error rows carry a 3px amber or red left edge as well as
  their pill. The Details column goes: the whole line opens its context.
  Times in Eastern Time, named ("4:26 AM ET"), with UTC on hover. Raw
  status keys in a message read as words ("past due", "paused")
- **Listings table:** a Status pill in the portal's words after Title,
  **Landlord** (display name, or "No landlord") in place of Author, **Rent**
  right-aligned, **Map point** (Set / Not found / Not set), a 48px photo
  column, one **Updated** date; the City column dropped while there is one
  city. On phones the photo and the status pill stay beside the title while
  the rest fold under WordPress's own "Show more details"
- **Staff who are not administrators** opening the WordPress Listings
  table are sent to the portal's Listings

**Not in this task:** the other wp-admin screens (Email delivery, Payments,
Security — each already one form); the landlord's view of wp-admin
(they never reach it).
**You check as** `instaquirk` at 1440 and 390.
**Commit title.** `wp-admin: grouped Logs that lead with warnings, a Listings table with status, landlord, rent and map point`

---

## 6. Requests register — nothing gets lost

Every ask from the client's side since the handoff, with its source and
where it lands. The lead's instruction on 14 Sep: write them all down,
calmly; each review round brings more. Add to this table the day a new
one arrives, before anyone builds it.

**What the client has already answered lives in
[`docs/CLIENT-SETUP-AND-DECISIONS.md`](docs/CLIENT-SETUP-AND-DECISIONS.md)**
— the accounts he has created, the details supplied for the A2P carrier
registration, all sixteen decisions with which are settled and which are
not, and the credentials to rotate at hand-over. Read it before asking him
anything: most of it has been answered already, some of it twice. No
password, key or EIN is written there, by rule 11.

| # | Request | Source | Class | Lands in |
|---|---|---|---|---|
| R1 | Scale beyond Pittsburgh | Rob review Sep, #1 | M1 | Live |
| R2 | Remove audience card links | Rob review Sep, #2 | M1 | Live |
| R3 | Find a Home → search bar + properties | Rob review Sep, #3 | M1 | Live |
| R4 | Single Family House type | Rob review Sep, #4 | M1 | Live |
| R5 | Cleaning / pet / application fees | Rob review Sep, #5 | M2 | A1 |
| R6 | Pet-friendly filter | Rob review Sep, #5 | M2 | C1 |
| R7 | Email verification | Rob review Sep, #6 | M2 — started 24 Sep 2026 on the reviewer's go-ahead; Rob's written yes still to record | F1 |
| R8 | Utilities Yes / No / Partial | Rob review Sep, #7 | M2 | A1 |
| R9 | Photo upload | Rob review Sep, #8 | Fixed | Live |
| R10 | How does an admin approve | Rob review Sep, #9 | Answered | — |
| R11 | Calendar (landlord-managed availability + date search) | Rob review Sep, #10; committed in the 9 Sep email | M2 | A5, C2 |
| R12 | Mapping (map/list search, property-location map) | Rob review Sep, #10 | M2 | C4 |
| R13 | About before Find a home in nav | Rob comments 16 Aug | M1 | Live |
| R14 | Renter FAQ | Rob comments 16 Aug | M1 | Live |
| R15 | Larger logo / wordmark | Rob comments 16 Aug | M1 | Live |
| R16 | Start and end dates on the home page | Rob comments 16 Aug | Fields M1 (live); filtering M2 | C2 |
| R17 | Property-type filter | Rob comments 16 Aug | M2 | C1 |
| R18 | Closest-first ordering for location/ZIP | Rob comments 16 Aug | M2 | **C6** (wrongly recorded against C3 until 22 Sep; see R44) |
| R19 | List and map views with approximate markers | Rob comments 16 Aug | M2 | C4 |
| R20 | Multi-listing discount 10 % / 15 % | Rob comments 16 Aug | **Change request** — not in the handoff; Stripe pricing | §4 decision 11 |
| R21 | Application fee, pet fee, refundable deposit on listings | Rob comments 16 Aug | M2 | A1 |
| R22 | Available-from plus unavailable date ranges | Rob comments 16 Aug | M2 | A5 |
| R23 | Expanded details: parking, sq ft, rooms, backyard, safety, laundry, kitchen, workspace, utilities | Rob comments 16 Aug | Amenities live; fields M2 | A1 |
| R24 | Rules-and-regulations reminder at inquiry; store accepted version | Rob comments 16 Aug | M2 | **D1, both halves**: the consent box stores `_tdh_rules_version`, and the reminder is a link to the Terms. The property page previously carried "Rules and regulations must be reviewed before sending an enquiry" — an instruction pointing at nothing, which would have read as delivered |
| R25 | Click-to-call support number on Contact | Rob comments 16 Aug | M1 (demo number) | Replace number before launch — M3 |
| R26 | Audience section for four groups | Rob comments 16 Aug | M1 | Live |
| R27 | Signed-in user on the login page goes straight to the dashboard | UX review 9 Sep, mistake 1 | Standard | Check in every portal task |
| R28 | Membership panel has a manage/cancel control | UX review 9 Sep, mistake 2 | M1 (Stripe portal link) | Verified in E1 (24 Sep 2026): the membership screen keeps it; the past-due band opens the same portal |
| R29 | No wp-admin links in the portal | UX review 9 Sep, mistake 3 | Standard | All tasks |
| R30 | Responsive means interaction, not width | UX review 9 Sep, mistake 4 | Standard | C1 drawer, A3 panels |
| R31 | Human labels, never stored keys | UX review 9 Sep, mistake 5 | Standard | All tasks |
| R32 | Success needs its own screen | UX review 9 Sep, mistake 6 | Standard | D1, A3 |
| R33 | Editing a live listing must not silently push it back to review | UX review 9 Sep, mistake 7 | M2 | A4 |
| R34 | Facility selector in the filter bar | DEVELOPMENT_PLAN §6.3 | M2 | C3 |
| R35 | Gallery with lightbox on the property page | DEVELOPMENT_PLAN §6.4 | M2 | A2 (data), C5/theme (display) |
| R36 | Drive time beside distance | DEVELOPMENT_PLAN §6.4 | Deferred | §4 decision 13 |
| R37 | Full moderation workflow (queue, preview, rejection reason, audit) | 9 Sep email | **M3** | — |
| R38 | Finish M2 fast; do not let it run monthly | Lead, WhatsApp 14 Sep | Process | §5 pace table |
| R39 | Capture every extra ask so none is missed | Lead, WhatsApp 14 Sep | Process | This table |
| R40 | "7 of 2 used" reads as broken when an account holds more homes than its plan allows (e.g. after moving to a smaller plan) — say it plainly | Reviewer, A4 localhost check 19 Sep | M2 | Built in E1 (24 Sep 2026): "7 homes · plan covers 2" plus what to do, on My listings, the membership screen and the staff Members list |
| R41 | Sign in with email **or username** — the box only took an email, though WordPress accepts both | Reviewer, A5 live check 19 Sep | Fix (reviewer's instruction) | Done 19 Sep: "Email or username", text field |
| R42 | Agent-based testing workflow: log every important action and failure, a separate test environment, a testing menu in the dashboard, and one click that runs every test case | Team lead, WhatsApp 20 Sep; reviewer agreed | Internal engineering (not Milestone 2 scope, not billed to the client) | Four parts, see below |
| R42a | Run every suite automatically on each push, before the deploy | — | Internal | **Done 20 Sep**: `.github/workflows/tests.yml`. First run exposed a skip recorded as FAIL and a refusal check that borrowed a fixture; both fixed. **Run #2 green on the clean runner, all 14 suites** (20 Sep). **Gate on from 20 Sep**: `deploy.yml` runs the suites as its first job and deploys only if green; no separate push trigger, so the suites run once per push |
| R42b | An event and failure log (time, who, what was attempted, what happened) with a staff screen | — | Internal, but M2 already requires it for email and SMS | Pulled forward on the reviewer's word ("R42b next", 20 Sep). **Built 20 Sep**, awaiting "100% OK"; D3 and D4 add their lines to it |
| R42c | A staging copy of the live site, password-protected, with sending switched off | — | Internal | Waiting on the Hostinger login and a subdomain |
| R42d | A "run the tests" menu inside WordPress | — | Internal | **Refused on live** — the suites create and delete listings, users and media. Staging only, behind a constant that is never set on production |
| R43 | Rotate the Geocoding server key before hand-over — the real key was committed as sample data in `verify-log.php` (commit 7f90d5d) and is in the repository's history. Replaced with an invented value 21 Sep; the live key still needs replacing | C4 review, 21 Sep | Security | Before G1 |
| R49 | A live `wp-config.php` backup was committed by accident in `cbaf6a3` and pushed (database password, WordPress keys, Maps keys; no Twilio token). Removed in `c891456` and ignored for good. The reviewer decided on 26 Sep 2026 **not to rotate** the database password or the keys; the repository is private | Incident, 26 Sep | Security — decided | Revisit at hand-over with R43 |
| R50 | A place typed in the search box was looked up anywhere in the US, so Pittsburgh neighbourhoods that share a name elsewhere lost: "Oakland" answered "No homes within 9 miles of Oakland, CA", Lawrenceville went to Georgia, Mount Washington to Massachusetts | G1 end-to-end walk, 26 Sep | M2 (C6 corner case) | Fixed 26 Sep 2026 (plugin 0.24.2): Google is asked to prefer a service area built from the client's own hospitals, and an answer far outside it is refused so the words are searched instead; old cached answers are never read again |
| R51 | The Fair Housing, Privacy and Terms pages still show "Draft copy, to be reviewed and approved before launch" / "Still to come" notes. The final wording must come from the client's attorney (contract §9: legal guidance for Terms, Privacy, Fair Housing) | G1 contract check, 26 Sep | Client dependency — launch (M3) | Ask Rob for the approved texts; replace the notes when they arrive |
| R52 | Live SMS said "Not set up" to Rob: the Twilio lines in live `wp-config.php` sat inside the `/* … */` comment block they were copied with, so only `TDH_SMS_ENABLED` was read. Fixed on the server 27 Sep 2026 (comment and the duplicate `TDH_SMS_ENABLED` removed). The same day, Logs → System status was found still saying SMS "Not built yet" whatever the settings; it now reads them (0.24.5) | Rob's screenshot, 27 Sep | Fixed | Rob repeats the text-alert steps; his screenshot is criterion 6 |
| R53 | Rob asked for the American spelling on the button: "inquiry", not "enquiry" (28 Sep 2026). Every word the site shows, sends or logs now says inquire / inquiry (form, button, emails, texts, dashboard, logs); internal CSS class names keep the old spelling | Rob, WhatsApp 28 Sep | Done (0.24.6 / 0.50.9) | — |
| R54 | Rob asked for the shortest password to be 8 characters, not 12 (WhatsApp, 2 Oct 2026); the team lead agreed. One constant, `Accounts::MIN_PASSWORD`, now drives sign-up, reset, profile, the `minlength` and the "At least 8 characters" hint; sign-in throttling is unchanged | Rob, WhatsApp 2 Oct | Done (0.24.14) | — |
| R56 | Team lead: "Within" (distance) looked broken — it stayed locked after a hospital was chosen until Show homes was pressed (4 Oct 2026). It now unlocks the moment a hospital is chosen or a place typed, starts at the team's radius, locks again when both are cleared; screen readers hear why it is locked; no-script behaviour unchanged | Team lead, WhatsApp 4 Oct | Done (0.24.16 / 0.56.4) | — |
| R55 | Rob asked to load his 14 real addresses so he can see the map and hospital distances working (WhatsApp, 2 Oct 2026). New Import Demo Content step "Homes from your address list" reads a private file uploaded next to wp-config.php (addresses never enter git) and makes one live home per address under his account, with sample rent, rooms and photo, each saying "Sample details"; Rob replaces them | Rob, WhatsApp 2 Oct; user 3 Oct | Done (0.24.15) | User uploads `rob/tdh-address-list.php` to live and runs the step; Rob's allowance raised to 15 |
| R44 | **A ZIP or area search must return the nearest homes, not an empty page.** Rob typed 15226, which has no homes in it, and got "No homes matching 15226". This is not a new ask: it is R18 from 16 Aug, it is item 6 of the original feedback list, and the live Renter FAQ already tells renters "When you search by location or ZIP code, homes appear closest to farthest." C3 delivered the hospital sort and the register wrongly recorded R18 as covered by it. **A defect against our own published copy, not an extra** | Rob, WhatsApp 22 Sep; reviewer agreed the same night | M2 — fix | **Built 22 Sep** (C6), awaiting "100% OK" |
| R45 | **A2P 10DLC brand and campaign were never submitted.** §3 item 2 put the deadline at 17 Sep; it is 22 Sep. Rob gave the legal name, EIN, address and contact on 18 Sep and nothing was filed. Carrier vetting is 1–3 weeks and nobody can shorten it. Without approval, D4 cannot send, criterion 6 cannot be met, and the M2 payment gate's "real-phone SMS test evidence supplied" cannot be produced. **This is the only open item that can slip the whole milestone on its own** | Register audit against the handoff, 22 Sep | M2 — blocking dependency | **Approved 25 Sep 2026** (campaign CM0479506a…, filed 24 Sep). Next: incoming webhook, live constants in test mode, Rob sets up alerts, real-phone evidence |
| R46 | **The privacy policy has no SMS section.** The live page at `/privacy/` is still the placeholder that *says* a lawyer must cover "SMS consent, message frequency, opt-out and data retention" — it does not contain them. Carriers normally reject an A2P campaign whose privacy policy has no mobile-number clause, so this blocks R45, which blocks D4. Handoff §9 also asks for client legal guidance on SMS consent and opt-out | Register audit against the handoff, 22 Sep | M2 — blocks R45 | **Built 23 Sep 2026.** Rob approved our wording by WhatsApp on 22 Sep (Yes, 5:11 pm), having been offered his attorney first and declined. The seed now carries a real **Text messages** section — `We only send text messages about inquiries on your property. We never sell or share phone numbers. Reply STOP to any text and we will stop sending them.` — above the placeholder note, so a carrier's reviewer does not read a consent clause under the word `Placeholder`. His words are used exactly, not tidied. Frequency, the HELP keyword, `Message and data rates may apply.` and a retention line were added on the reviewer's instruction to use our own judgement rather than go back to him again — they are the clauses carriers look for that his sentence does not cover, and each is a statement about what the software does, held true by a check. Retention is worded to what the site ACTUALLY does: a deleted listing deliberately keeps its messages, so `deleted with the listing` would have been a promise the code breaks. Reaches live by re-running Listings → Import demo content → **Pages and menus**, which updates the page unless somebody has hand-edited it, in which case it reports the page as protected and skips it. **Continued 24 Sep 2026:** Twilio's campaign form itself names what the two pages must say — privacy: the registered brand, what is collected and how it is used, and *We do not sell or share your SMS opt-in data or personal information with third parties for marketing purposes* word for word; terms: titled *Terms & Conditions* or *Terms of Service*, with an SMS Terms section, *message and data rates may apply* and the brand. Neither live page had it. Added to the seed with the owner's sentence untouched; Terms retitled from *Terms of Use* to *Terms of Service* (the sign-up link and its refusal renamed with it); every clause a check in `verify.php` (63, +16). Reaches live by the same Pages and menus import |
| R47 | **No staging environment.** Handoff §2 makes "Staging first" a non-negotiable principle and every Milestone 2 acceptance line reads "work on staging"; the payment gate expects a client walkthrough there. Every M2 task so far has been accepted on the live client site. R42c has been waiting on a Hostinger login and a subdomain since 20 Sep | Register audit against the handoff, 22 Sep | Contract principle | Raise with the team lead alongside the stuck mailbox order; needed before the M2 walkthrough |
| R48 | **The landlord is never emailed when their home is approved or sent back.** `tdh_listing_approved` and `tdh_listing_changes_requested` fire, but only the log listens. A landlord learns their home went live — or that staff asked for changes, with a note to act on — only by signing in and looking at the row. Staff, by contrast, are emailed on every submit. Two short emails, both with a link to My listings; the "changes" one carries the note word for word | Phase A journey pass, 23 Sep 2026 (pillar 1, Completion) | Not in any M2 card — the reviewer decides | Cheap once decided: one listener each, the D3 mail path, two checks |

---

## 7. Acceptance map — criterion → tasks → evidence

| Criterion | Tasks | Evidence at close-out |
|---|---|---|
| 1 Create, preview, submit a complete listing | A1 A2 A3 A5 | Walkthrough as landlord; `verify-listing-form`, `verify-listing-actions` |
| 2 Moderation state visible to both | A3 A4 (+ M1 moderation) | Landlord dashboard + marketplace admin screenshots |
| 3 Approved property in search and on its page | C1 B2 A1 | Visitor walkthrough at three widths |
| 4 Filters, sorting, list/map, empty states together | C1 C2 C3 C4 | `verify-search`; shared URLs reproduce searches |
| 5 Facility distances accurate | B1 B2 C3 | Table: test address → facility → miles vs a map check |
| 6 Inquiry stored, dashboard, email, SMS to a real phone | D1 D2 D3 D4 | Log rows + phone screenshot |
| 7 Failures logged, safe feedback, no lost inquiry | D3 D4 | Forced-failure test output; staff badge screenshot |
| 8 Lapse hides, renewal restores | E1 | `verify-enforcement`; persona walkthrough |
| 9 Elementor widgets render real data | C5 | Editor and front-end screenshots |
| 10 No critical/high defects | G1 | Defect list with severities |

---

## 8. How a task moves — the protocol

1. **Open** this file. Take the next task in §5 whose dependencies are
   done. Say in one line what you are about to build. Nothing else starts.
2. **Read** the task card, the files it names, and §0b. **Write the pillar
   plan** (`AGENTS.md` §4.4) — journey and states, corner cases, labels and
   UI — before any code.
3. **Build** — business logic in the plugin, presentation in the theme,
   CSS scoped and token-based, a `verify-*.php` suite, `verify.bat` green.
   Bump the plugin `Version:` header **and** `const VERSION`; bump
   `TDH_THEME_VERSION` **and** the `style.css` header if the theme changed.
4. **Walk it** as every role in the card. Fix what the walk finds.
5. **Mirror** every changed file to `New folder\thirtydayhomes`.
6. **Hand over** in the §0b format. Then **stop**. No commit, no next
   task.
7. **On "100% OK":** commit that task alone, with the prepared message.
   The user pushes from GitHub Desktop.
8. **After the push** (user): LiteSpeed → Purge All; Tools → Import Demo
   Content → "Pages and menus" only if the task seeds pages, terms or
   layouts; spot-check the changed screens on thirtydayhomes.com; paste
   any new `wp-config.php` constants the task introduced.
9. **Update §10** here — status, date, versions — in the same commit or
   the next.
10. **Update the client report** (rule 14) once the task is live: move it
    to *Completed*, add its live check, rebuild the .docx.
11. **Tick `MILESTONE-2-CHECKLIST.md`** as each gate passes, with the date,
    and correct `PROJECT-HANDBOOK.md` wherever the task made it untrue.

If a task turns out to need something not in its card, stop and say so;
do not widen it. If the reviewer finds a problem, fix that problem only,
re-hand-over the same task.

---

## 9. Essentials carried over from Milestone 1

The full list is `MILESTONE-1-CLOSEOUT.md` §2, §3, §7. The ones that
cost the most when forgotten:

- **Push = deploy.** No staging. `.github/workflows/deploy.yml` rsyncs
  `main` to production on every push
- **PHP files:** editor tools or `[System.IO.File]::WriteAllText` with
  `UTF8Encoding($false)`. Never PowerShell `Set-Content` — a BOM has
  broken live twice. Shell scripts stay LF
- **Version pairs** must agree or the build fails / browsers cache old CSS
- **`git` is not on PATH:** use GitHub Desktop's
  `%LOCALAPPDATA%\GitHubDesktop\app-*\resources\app\git\cmd\git.exe`;
  commit messages via `-F file`
- **Tests:** `plugins\thirtydayhomes-core\tools\verify.bat`
  (`TDH_NOPAUSE=1` when scripted). WP-CLI:
  `D:\xampp\php\php.exe D:\xampp\wp-cli.phar <cmd> --path=D:\xampp\htdocs\thirtydayhomes`
- **Local accounts:** `testuser1@example.com` is an active landlord with
  quota 2; `admin` is the administrator; the "Viewing as" bar switches
  persona without a password. Passwords are not kept in the repo — the
  reviewer has them, or reset one with `wp user update`
- **Staff gate** is `Accounts::is_staff()` — the marketplace capability,
  never `manage_options`
- **Private pages** go in `No_Cache::PRIVATE_KEYS` or LiteSpeed serves one
  landlord's page to the next
- **`WP_Query` ignores an empty `post__in`** — pass `[0]` for "nothing"
- **The mirror folder is its own git repo** on the user's GitHub; commit
  there separately or it drifts
- **Never paste secrets into chat**, files or commits

---

## 10. Progress

Update when a task changes state. Dates absolute.

| Task | Status | Localhost OK | Committed | Live | Notes |
|---|---|---|---|---|---|
| Day-1 actions (§3) | in progress | — | — | — | Request list sent to Rob 17 Sep; Cloudflare/DNS login asked for |
| A1 fields | **Live** | 17 Sep 2026 | 17 Sep 2026 | 17 Sep 2026 | Also: Back saves, "Save and return to review", listing preview + designed 404 (pulled forward from A3 on request) |
| A2 photos | **Live** | 17 Sep 2026 | 17 Sep 2026 | 17 Sep 2026 | Order/cover/alt, uploads cleaned (upright, ≤ 2000 px, GPS stripped), HEIC refused (kept by reviewer's decision); property page gallery + "Show all photos" viewer (R35 display pulled in) |
| A3 lifecycle | **Live** | 17 Sep 2026 | 17 Sep 2026 | 17 Sep 2026 | Pause/resume/delete (in-page confirm), note required on Request changes, Edit and resubmit, staff email on submit, state line + one action per row, pages |
| A4 live edits | **Live** | 19 Sep 2026 | 19 Sep 2026 | 19 Sep 2026 | Built on decision 8's proposed list (filter `tdh_material_fields`); Rob's answer changes the list only. R40 logged during the check. Two TEST homes left on live on purpose — delete before G1 |
| A5 availability | **Live** | 19 Sep 2026 | 19 Sep 2026 | 19 Sep 2026 | Step 2 Availability section, My listings quick edit, property page calendar; `is_free()` ready for C2 |
| Phase A pass | **Live** | 23 Sep 2026 | 23 Sep 2026 | 23 Sep 2026 | A journey and corner-case walk over A1–A5 on the reviewer's request (plugin 0.22.2; no theme change). The suites already proved most of §4.2, so the walk went where they had not. Three defects fixed, each now a check: **moderation had no status check** (§18 issue 9) — a queue page left open while the landlord deleted the home would, on Approve, set a trashed post to `publish` and bring it back live; a second Approve re-stamped "Live since"; a stale Request changes took a live home down — both actions now refuse anything not `pending` with a `not_pending` notice; **the wizard handler let `full` through** (§18 issue 4) — a crafted step 1 at the allowance created a draft past it, though the gate already answers `''` for an existing draft so nothing legitimate needed the exception; **an expired page mid-wizard** sent the landlord to step 1 whatever step they were on and said "your work is saved as a draft" on a home that might be live — it returns to the posted step now, home carried, saying only that this step was not saved. Walked at 390 and 1280 as a landlord through the real login: My listings with a one-word 60-character title and a 250-character staff note, the delete and availability panels, every wizard step for a live home and a draft with gaps — nothing overflows. Found but not built, because no card asks for it: **the landlord is never emailed on approval or on changes requested** (R48). Not done: the 125 % zoom pass. Wizard suite 153 (+5), listing-actions 129 (+5) |
| Phase B pass | **Live** | 23 Sep 2026 | 23 Sep 2026 | 24 Sep 2026 | The same walk over B1 (map locations) and B2 (nearest hospitals). **No defect found in the product.** The suites already prove the four location states, the approval hold and its greyed button, typed points, the paused service, the missing key, the cache, plurals and the staff setting's limits; the code read confirmed three behaviours no check named — a location action on a deleted or unknown id answers "missing", *Look up the address again* asks Google even for an unchanged address and takes the answer, and a refused-key notice disappears on the next successful lookup — so those are checks now, with a `find_everything` switch on the suite's fake gateway (geocode suite 88, +4). Walked at 390 and 1280 through the real login as staff (the queue row with Set location open: chip, reason, greyed Approve, address on file, Google Maps link; Facilities with its delete panel; Listing setup), as the landlord (step 2 with "We couldn't find this address on the map… If it is right, our team places it by hand during review") and as a visitor (Close to care: three hospitals, type in words, 0.1 / 1.0 / 1.4 mi; search cards) — nothing overflows. On the way, `verify-portal.php`'s "cannot be counted by probing ids" check turned out to run only when the site happened to hold a pending home — it makes its own now, so the total no longer moves with the site's data. Test files only; no version bump. Not done: the 125 % zoom pass |
| Phase C pass | **Live** | 23 Sep 2026 | 24 Sep 2026 | 24 Sep 2026 | The same walk over C1–C6 (plugin 0.22.3, theme 0.48.2). The search suite already proves the filters, the chips, the dates and their refusals, the hospital and the typed place, the map's privacy and the widgets, so the pass probed the URL corners and the page itself. **Two defects, fixed:** a page number past the end — `/homes/page/9/`, or page 3 of a search a new filter shrank to one page — answered a **404 saying "This home isn't available right now"**, the not-found written for a missing home; it goes back to the first page of the same search now, filters kept, and a city page past its end goes back to that city; and a long keyword with no spaces pushed the chip, the count and the "No homes matching" heading off the side of a phone — a keyword is capped at 100 characters and those three wrap anywhere. **One improvement:** a neighbourhood, city, type or amenity page with nothing on it now says "No homes in South Side yet" instead of the site-wide "No homes are listed yet". Probed and found right: whitespace-only and script-tag keywords, quotes, nonsense prices, `beds=2.5`, `within=0`, a same-day and a backwards stay, `sort=closest` with nothing to be close to, map view with zero results (no empty map), a term with no homes. Walked at 390 and 1280 as a visitor: default, chips, empty, dated, hospital, typed place, long keyword, map, page 2, the city archive, a refused stay, and on the phone the filter drawer — opens, holds focus, Escape closes it and returns focus. On the way: `verify-proximity.php` assumed the hospital setting was at its defaults and deleted both options in its cleanup — the reviewer's 9-mile radius from the Phase B check made it fail two checks and then wiped the setting; it pins the defaults for the run and restores what it found; and `verify-portal.php`'s "no invented 284" failed on any substring "284" (a nonce, an id in a link), which ids in the 18000s made a matter of time — it looks for 284 as a number on screen now. Search suite 249 (+8), proximity 53 (+1). Not done: the 125 % zoom pass |
| B1 geocoding | **Live** | 20 Sep 2026 | 20 Sep 2026 | 20 Sep 2026 | Client's key installed on localhost and live (`TDH_MAPS_SERVER_KEY`); 5 hospitals and the real test addresses resolved. Live check found two things, fixed the same day (plugin 0.9.1 / theme 0.34.1, awaiting "100% OK"): a never-looked-up record claimed a failed lookup, and the phone row's chip crushed the name and the sentence |
| B2 nearest facilities | **Live** | 20 Sep 2026 | 20 Sep 2026 | 20 Sep 2026 | Card band unchanged; property page lists the nearest 3 within 15 miles with the type in words; count and radius set on Listing setup (1–5, 1–100); no location = no section, nothing in range = says so |
| R42b event log | **Live** | 20 Sep 2026 | 20 Sep 2026 | 20 Sep 2026 | Internal, pulled forward from D3: `wp_tdh_log`, `TDH\Log` listening to the existing actions, wp-admin → Listings → Logs (administrators; moved out of the client's portal on the reviewer's word) with a tab per feature and System status, 90-day purge, silent during test runs; 75 checks |
| C1 filters | **Live** | 21 Sep 2026 | 21 Sep 2026 | 21 Sep 2026 | Price range, bedrooms, bathrooms, type, pets, sort — all in the URL; chips with ×, Clear all, count, empty state that names the constraint, phone drawer; the city/type/neighborhood archives became the same search page; 83 checks. The reviewer's break-it step found a negative price being turned positive; fixed and pushed the same day (0.12.1, 86 checks) |
| C2 date search | **Live** | 21 Sep 2026 | 21 Sep 2026 | 21 Sep 2026 | The hero's dates finally filter: only homes free for the whole stay, and long enough for it. Two date fields at the head of the filter bar, one chip for the pair, "Free for your dates" on the card, the stay repeated above the property calendar. Past, backwards, under 30 nights and beyond the horizon are each named rather than dropped in silence; 132 checks |
| C3 facility sort | **Live** | 21 Sep 2026 | 21 Sep 2026 | 21 Sep 2026 | Pick a hospital and the homes near it are listed closest first, each card measuring to **that** hospital; a radius that can be widened from the empty state; the keyword finds hospital names. Un-geocoded homes are left out of a radius and last at any distance. 178 checks |
| C4 map | **Live** | 21 Sep 2026 | 21 Sep 2026 | 21 Sep 2026 | List / Map on the results page and a circle on the property page, both drawn around a point moved 80–250 m and salted so it cannot be reversed; never a pin. No key, a refused key or no JavaScript leaves the list whole and says so in our own words. 32 privacy checks, 198 search checks. The browser key in wp-config was the wrong key; corrected 21 Sep and the map now draws |
| C5 widgets | **Live** | 21 Sep 2026 | 22 Sep 2026 | 23 Sep 2026 | Committed `6c7ba77` on the reviewer's "100% OK", 22 Sep. "Search results" and "Nearby hospitals" in the ThirtyDayHomes category, both reading live data and both silent on a page they do not belong to, where the editor gets a line and a visitor gets nothing. Two shortcodes added as the primitives. 71 checks. Nothing seeded: the archive and single pages are theme templates and an Elementor layout for them needs Pro |
| C6 area search | **Live** | 22 Sep 2026 | 22 Sep 2026 | 23 Sep 2026 | R44/R18. A postcode or town typed into the box is looked up as a **place**, not matched as text, and the results become every home near it, closest first, with a radius the renter can tighten or drop. Rob's 15226 — a postcode holding no homes — answered "No homes matching 15226" and now answers "5 homes within 15 miles of 15226". Each card says how far, and still says how far from care. A home's name, a hospital's name and nonsense all keep searching by text; a chosen hospital always wins; a home that names the place but was never geocoded is kept and put last rather than dropped. One lookup per term, then a month of cache; no key, a refused key or a slow service falls back to the old text search rather than to an error. `TDH_OFFLINE` keeps every suite away from the live service. 236 search checks, 84 geocode checks |
| D1 inquiry form | **Live** | 22 Sep 2026 | 22 Sep 2026 | 23 Sep 2026 | A renter writes to the owner from the property page and lands on its own success screen naming the home. Store first, notify second: `tdh_inquiry_received` is where D3 and D4 hang, and nothing on it can turn a saved enquiry into a failed one. Refused by state, never by identity — a home paused mid-typing says so; a landlord may enquire about their own home. Consent stores the rules version (R24) and the reminder became a link to the Terms, replacing a line that told renters to review rules it never showed. Double press, Back and Forward all re-send nothing (POST-redirect-GET, plus a 10-minute same-person-same-home window). 64 checks |
| D2 dashboard inquiries | **Live** | 22 Sep 2026 | 22 Sep 2026 | 22 Sep 2026 | Committed `68903f2` on the reviewer's "100% OK", pushed, and deployed — theme 0.45.0 confirmed serving on `/account/`. Read the version on an account page, never the home page: the host caches the home page and it still advertised 0.44.0 long after the deploy landed, which reads exactly like a failed deploy. Landlord inbox at `?view=inquiries`: All / Unread / Archived, 20 a page, unread as a word and a weight plus a counted badge, every row naming its home, open to reveal the renter's contact details, Archive and back again. Staff read the same message inside the portal — the wp-admin link is gone (R29). Ownership routes by the home's author; a wrong owner is answered exactly as a missing id. Three bugs found by the walkthrough fixture rather than the suite: enquiries about a deleted home vanished (`'any'` excludes trash), every inbox link pointed at the home page (`Accounts::url('dashboard')` is not a key), and the badge counted the message being read. Logging added for D1 as well, which had shipped with none. 101 checks |
| D3 email + log | **Live** | 22 Sep 2026 | 23 Sep 2026 | 23 Sep 2026 | Every enquiry emails the landlord and every attempt is written down. Listens to `tdh_inquiry_received`, so a mail server that hangs can never cost a renter their message and the renter is never told whether it worked. The email deliberately leaves out the renter's phone number and their message — an inbox is a less careful place than the dashboard — and Reply-To carries the renter with `,;<>"` stripped, because `wp_mail()` splits it on commas. Retries at +10 min and +60, then gives up and says so; `sweep()` on `admin_init` covers a host whose cron only fires on a page view. Staff get a **Delivery** block with the badge in words, the address, what the mail server said, and **Send it again**; the list badges only the ones in trouble. Copies to a second address are built and **off by default** — the register's decision 5 says on, to the WordPress admin address, and the reviewer chose off, so this needs a word. Four defects the forced-failure probe found before any of it shipped: a row that had given up still accepted a fourth attempt, each failure left its cron event behind so retries stacked, the sweep used the first backoff for every attempt (spending all three tries inside ten minutes), and the body's blank lines were being filtered out with the optional ones so the email arrived as one block. A fifth came out of the phone walk: the badge squeezed the renter's name onto two lines on the one row that needed reading. A journey-and-corner-case pass on 23 Sep found four more: the send ran inline between storing the enquiry and the renter's success screen, so a slow mail server left the renter waiting up to Smtp::TIMEOUT's fifteen seconds (booked through cron now — a real enquiry through the real form returns in 2.3 s and the landlord is still emailed); the staff list drew a flat twenty with no pager and no count, so a failure past the twentieth message could never be seen, which is the badge's whole purpose; a staff copy that failed was silent; and Resend froze with no sign of life. Fixing them introduced two of their own, both caught first: wp_json_encode's double quotes closed the onsubmit attribute and left the script loose in the tag, and sweeping queued rows would have handed D4's future sms rows to wp_mail() with a phone number in the To field. 167 checks |
| D4 SMS | **Live** (texting off until the carrier approves) | 23 Sep 2026 | 23 Sep 2026 | 23 Sep 2026 | Landlords get a text for each enquiry, once they say so. Off until the landlord enters a number, ticks the consent line and types back the six-digit code we text them — a **Text message alerts** card on Profile, with the state in words (Not set up · Code sent — check your phone · On · Number verified, alerts off) and the phone field moved into it out of the details grid. Listens to `tdh_inquiry_received` at priority 20 and shares D3's table under `channel = sms`, booked through cron; one retry at +10 min, then given up and shown to staff as "Text failed". The text names the home and links to the message — never the renter's name, phone or words — and ends "Reply STOP to opt out"; STOP by webhook pauses, START resumes, both checked against Twilio's signature, and an undelivered status callback marks the row given up. A landlord who never opted in, or who turned texts off on the card, gets no row at all; unverified, opted out by STOP, unconfigured and over 20 texts an hour each write a `skipped` row that names the reason. Codes are stored hashed, expire in 10 minutes and lock after 5 wrong tries; a new one no sooner than 60 s and at most 5 an hour. Changing the number anywhere resets the verification. `TDH_SMS_ENABLED` unset registers nothing but that listener and the card says texts are not available yet; `TDH_SMS_TEST_RECIPIENTS` skips every other number at send time and says so in wp-admin; no Twilio constants on a non-production site captures the text to disk the way mail is. **The real text to Rob's phone waits for the A2P campaign (R45)** — everything up to the carrier is proven against a fake provider. Committed `a75a1b4` on the reviewer's "100% OK", 152 checks. **Journey and corner-case pass the same day (0.22.1 / 0.48.1, committed on a second "100% OK"):** seven more, all fixed and each now a check — the sweep retried a failed text after two minutes instead of the promised ten; a home's name reached the text, and D3's email subject, as HTML entities (`Landlord&#8217;s`), and a long or curly-quoted name made a two-segment text; Verify pressed twice said "expired" on a card already On; a STOP that Twilio handled but our webhook never saw would have failed every later text for ever (error 21610 → paused now), and the paused card implied that setting up again restarted texts when only START does; Send code and the other gateway-calling buttons gave no sign of life while Twilio answered; Twilio's queued/sent reports wrote three log lines per text; and the *Please sign in* return link doubled the site folder on a sub-directory install. 180 checks |
| E1 enforcement | **Live** | 24 Sep 2026 | 24 Sep 2026 | 24 Sep 2026 | Committed `344261f` on the reviewer's "100% OK"; deploy green, theme 0.49.0 served, no home hidden on live (every landlord owning a home is Active).  Criterion 8 (plugin 0.23.0, theme 0.49.0). New `TDH\Enforcement`. A failed payment starts a 7-day grace clock once (a retried charge never restarts it); nothing is hidden and every dashboard view says "Your 2 homes stay visible until 1 Oct 2026. Update your card and nothing changes." with **Update your card** opening Stripe directly when the landlord has a Stripe customer. After grace, or at once for an ended plan (expired, or cancelled past its end date even with no webhook), the daily job moves every live home to `tdh_billing_hold` — content, meta, photos and terms untouched, paused / in review / drafts left alone — and the band says how many are hidden and that nothing is deleted; renters get a 404 and search drops them. Payment restores exactly the held homes (a paused home stays paused) and the next dashboard view says once "Payment received — your 2 homes are back online." While unpaid, submit is refused with the draft kept, and staff cannot approve the landlord's pending home: queue row "Membership inactive" with Approve greyed and pointing at the reason, preview bar with only Request changes, a forged approve refused `owner_inactive`. A stray live row is still hidden by `tdh_inactive_member_ids`; the city archive is now a listing query (§18 items 1 and 2). Staff are never held. **R40:** an account over its plan reads "7 homes · plan covers 2" and says "To add another, delete 6 or move to a larger plan." **R28** verified: the membership screen keeps Manage or cancel membership. Log lines grace_started, billing_hold, billing_restored. The suite calls the job with its own landlords only, never the site-wide sweep. Two older suites (inquiry, preview) made homes for landlords with no plan; their fixtures pay now. Walked at 390 and 1280 through the real login as a past-due landlord (grace, hidden, restored), as staff (queue and preview) and as a visitor (404, search). The **Failed payment** persona in the Viewing-as bar is really past due now (its note said billing was inert). A home with no landlord (Unassigned) is never held and never waits on a plan for approval, and a hidden home's row says why in the band's words (payment failed, or plan ended). 74 checks; 19 suites, 1,855 checks |
| F1 email verification | **Live** | 24 Sep 2026 | 24 Sep 2026 | 24 Sep 2026 | Committed `b92fd57` on the reviewer's "100% OK"; deploy passed on a rerun (host-key step, transient); theme 0.50.0 served; live email reaches a Gmail inbox.  Started on the reviewer's go-ahead ("if Rob asked for it, start"); Rob's own written yes is still to be recorded. New `TDH\Email_Verification` (plugin 0.24.0, theme 0.50.0). Sign-up emails a link and says so; until it is followed a band on every dashboard view names the address and what waits on it, with an outlined **Send a new link** (60 s apart, 5 an hour, each new link kills the old); the pricing page says the same as information, not as an error; starting a plan and submitting a home are refused with the reason, the draft kept. The link works signed out and signs the landlord in; twice says already confirmed and signs nobody in; expired asks for a new one and signs nobody in; forged, unknown, or sent to an address the account no longer has reads "not valid" alike. Only an HMAC of the token is stored. A landlord's own email change is confirmed again; every send goes to the account's current address. Absent = confirmed, so older accounts, staff-made members and personas need no migration; staff are never asked. Walked through the real sign-up form at 390 and 1280 and the emailed link opened in a second, signed-out browser. The walk moved "Send a new link" to an outlined button so it no longer competes with Choose plan, and turned the pricing line from a red error into information. verify-email-verification 48; 20 suites, 1,903 checks |
| G1 close-out | **In progress** — design review parts 1 (committed ``cbaf6a3``) and 2 handed over | 24 Sep 2026 | | | Self-QA against the ten criteria recorded in the checklist §3 (24 Sep 2026). **Design review, part 1** (plugin 0.24.1, theme 0.50.1): a scripted walk of 21 public screens at 320 / 390 / 768 / 1024 / 1280 / 1920 and 18 signed-in screens (landlord and staff, every dashboard view, the four wizard steps, the preview) found **no sideways scroll, no PHP output, no shortcode artefacts and no console errors** anywhere. Fixed: stand-alone text links were 21–28px tall (header and footer menus, Clear all filters, View all homes, Back to homes, the search Clear, the About and How it works links, the dashboard's Sign out and View all, the preview bar's link, the checkbox rows) — each now has a 2.25rem box, and the footer's row gap was tightened so the phone footer stays compact; the staff Members and Facilities edit forms had labels not tied to their fields (16 fields), and the wizard's Description box had none — screen readers now read each field's name. F1 follow-up: someone else signed in who opens a spent link is told "That email address is already confirmed", not "Your". The reviewer's own phone and tablet check found two more, fixed: the home page's date search kept its four desktop columns on tablets and phones (the date variant's two-class selector outranked the responsive rules), so at 320 the dates and the Search button fell off the card — it now stacks on phones and, on tablets, puts Where on its own row, the two dates side by side and Search full width; and the headings "Housing that works as hard as you do." and "Your property works harder with longer stays." read "worksas" and "harderwith" on phones, because the desktop line break is hidden there — `Render::heading_with_breaks()` now always puts a space before a break, for Elementor-edited headings too. Left as they are, on purpose: card titles (28px headings, the card photo is the bigger target), inline names in lists, the map's price pills and Google's own map links. **Part 2** (theme 0.50.2): a keyboard walk of 16 screens — every public page, the landlord dashboard, listings, profile and wizard, the staff queue, Members and Facilities — tabbing through every stop: each one shows a focus ring except the home page's search fields, whose inputs drop their outline to sit flush in the card; the whole field now shows the ring. Escape closes the phone menu, the dashboard menu and the filter drawer and returns focus to the button. 125 % zoom: 1280 at 125 % is the 1024 layout (clean in part 1) and 1920 at 125 % is 1536, walked clean. **End-to-end journey** (26 Sep 2026): one run through the real forms as three people — a new landlord signs up and confirms the email link, pays (Stripe stood in locally), submits a complete home; staff approve it from the queue; a renter finds it by keyword, opens it (Close to care, no street address), sends an enquiry and sees Message sent; the landlord is emailed and texted and finds it unread in the inbox; then a failed payment (visible in grace, band with the date), an ended plan (404 to renters, gone from search, "nothing is deleted") and a payment (back online, visible again). 28 of 28 steps. It found R50. **Live acceptance walk** (26 Sep 2026, reviewer, docs/M2-LIVE-CHECK.md): steps 1–3 and 5a passed on live; it found three presentation defects, each fixed and live — the home search showed the browser's black calendar glyph beside the gold icon (``30f7615``, theme 0.50.3: icons in round sand wells, the whole date field opens the calendar); card photos stopped short of the card edge and the hover zoom never ran, because the sizing rule named only a direct child of .property-img while the photo sits inside its link (``a266257``, 0.50.4); and a bare scrollbar sat under the availability calendar (``54d3cf7``, 0.50.5); and a home still "Location not checked yet" — saved before the live Maps key existed — could be approved with no location, against the client report's "a home without a location is not approved": Approve now looks such a home up first and holds it back if Google cannot find it (plugin 0.24.3, geocode suite 90, +2). On the reviewer's request the landlord's "Your listings" rows became cards (theme 0.50.6): a 136×104 photo, the name in the display face, the rent as a bold figure beside the place with a pin, the state line muted, and the actions under a hairline; confirm and availability states tint the card instead of a halo; on a phone the quiet actions sit two to a row. The phone walk at 320 found the filter drawer's two dates clipped to "mm/dd/yyy"; below 24rem each now takes the full row (theme 0.50.7). Deleting the test sign-up showed the staff Members screen still asked through a browser confirm() pop-up — against the rule every other delete follows; it now asks inside the page like Facilities and My listings: the member opens, "Delete NAME?", what happens to their homes, Delete member / Keep them, Escape keeps them (plugin 0.24.4, portal suite 89, +3); the reviewer found the first version squeezed beside Send password reset, so while a delete is asked the question has the row to itself, pink with a red edge like a landlord's delete, on Members and Facilities alike (theme 0.50.8). The deploy's host-key step failed twice (Hostinger SSH, transient) and passed on rerun. Still to do in G1: the real-phone SMS evidence, Rob's walkthrough and written acceptance. 20 suites, 1,904 checks |

| G2 design system | **Live** — batch 1 of 4, committed `d5598ab` on the reviewer's "100% OK", pushed and checked live (theme 0.51.0 served, menu renamed, ratings gone) | 29 Sep 2026 | 29 Sep 2026 | 29 Sep 2026 | Design review part 3, from the master-design-prompt audit of 28 Sep 2026 (every screen scored 5–8; seven failed MUSTs capped each at 7; plan in `D:\fahad vi backup\ThirtyDayHomes-Design-Guideline.md`). Tokens and CSS, no change to what a page does (plugin 0.24.7, theme 0.51.0). **Type:** no step under 12px — the 10 and 11px tokens resolve to 12, 13 → 14, 17 → 18, 21 → 20, 25 → 24, 26 → 28; 23 meta/helper rules (card facts, field help, inbox previews, plan notes) moved to 14. **Contrast:** gold text #a68631 (3.5:1) → #7a6222 (5.8:1), gold-ink → #775f1f, muted → #5b6774, subtle → #66717e; filter labels ink at 12/600. **Two-tone text:** new `--text-body` #3e4956 on `body`, headings keep ink, headings inside the twelve dark bands inherit, inputs never fall back to browser black. **Figures:** lining tabular numerals on every serif number (prices, stats, dates, the empty-state heading), the card price and the landlord's rent in ink. **Shadows:** three navy-tinted tokens (the mobile nav's black 44 % goes), listing cards carry the soft one at rest. **One primary per view:** landlord card actions are an outlined `manage-primary` plus text links — Edit is the action on a live home and "View live page" a link; on Sign in and Create account the header's gold CTA is outlined (`tdh-page-<seed>` body classes). **Header:** "Renter FAQ" → "How it works", "List your property" → "Pricing" (importer and a 0.24.7 upgrade step for existing menus), the current page underlined in gold, the footer wordmark not underlined. **Hero:** "Search homes" always the solid gold button, dates optional, the `require_dates` switch removed from the widget and shortcode. **Pills:** 12px, semibold, labels as written (no CSS capitalisation). **No invented social proof:** the card never prints a rating, the Rating field is gone, and the upgrade step saved then cleared `_tdh_rating` and the "Guest favorite" / "Top location" sample badges (3 homes locally; restorable from `tdh_design_v2_removed_meta`). `maybe_upgrade()` moved from `admin_init` to `init` so the step runs on the first request after a deploy. American spelling in the last six strings (centers, neighborhood, canceling, recognize). Rollback: tag `design-v1-baseline` (74bf0c1). Before/after at 1440 and 390 for 20 screens; dark bands checked. 19 suites, 1,856 checks, log count unchanged |

| G3a renter journey | **Live** — batch 2 of 4, first half, committed `e978bc3` on the reviewer's "100% OK", pushed, LiteSpeed purged, theme 0.52.0 served on `/`, `/homes/`, the property page and `/pricing/`; the Oakland Townhouse reads "3 medical facilities within 1.7 miles" | 29 Sep 2026 | 29 Sep 2026 | 29 Sep 2026 | Design review part 3, the renter's path (plugin 0.24.8, theme 0.52.0). Nothing changes which homes are found or what a form saves. **One search form:** the keyword row and the filter panel are one GET form with one filled **Show homes** (`Render::filter_bar()` prints the `search-bar` row; the panel is `.filter-drawer` inside the form); the phone drawer keeps its own Show homes because the keyword row's is behind it, and without JavaScript the panel stacks with its button as the form's one. **Sort by** left the filters for `Render::sort_control()` beside the result count — its own small GET form carrying the whole search as hidden fields, submitted on change (a Sort button for no-script). Property type takes two tracks like the hospital. A `tdh-results` body class trims the gap under the banner; the no-homes-at-all state offers **List your home**. **Cards** read rent → availability pill (Available now · Available 6 Nov · No open dates right now · Free for your dates; green / sand / grey, always words) → name → place → facts (icon, bold number, unit; "1.5 bathrooms" and "1 bathroom" both right now) → hospital band → **View home** at the foot (title for screen readers). **Property page:** facts as one line with singular units; "Everything you need" split into **Included** (ticks) and **House rules** (pets, minimum stay, neutral marks); the inquiry form moved into the main column at `#inquire`; the side column (price, fees, gold **Ask the owner**) is ~420px and pins; phones get a fixed bottom bar with the rent and the button; `detail.js` hides the button and the bar while the form or the footer is on screen and moves focus to the first field after the jump; "Phone (optional)"; a two-sentence consent line; "Your message goes straight to the owner…" under Send; the hospital heading is an h3 naming the real farthest distance, rounded up (`Proximity::within_phrase()`: "3 medical facilities within 1.7 miles"). **Home page on phones:** the four audience cards as a 2 × 2 of icon, eyebrow and title (the paragraphs hidden under 700px — R2 stands, no links), the owner's two figures side by side. **From the reviewer's walk the same day:** the dropdown lists themselves (sort, filters, the inquiry form's stay) are drawn as theme cards — sand tint and gold tick on the current choice, muted disabled rows — in browsers with the customizable select (`appearance: base-select`, Chrome 135+; others keep the native list, nothing changes what a select does); a staff radius off the standard steps (9 miles locally) is now offered and selected instead of the browser showing "5 miles" under "within 9 miles"; the 320px bar puts "/ month" under the rent. Tests: verify-search follows the split (sort assertions on `sort_control()`, 10 labelled fields, hidden keyword when the row is off, the off-step radius), verify-inquiry's layout block rewritten for the new invariant (form in the main column, the column pins, a bar for phones), verify-proximity reads the real distance. Before/after at 1440 and 390 in `D:\fahad vi backup\design-shots\g3a`. 20 suites, 1,931 checks, log count unchanged (185) |

| G3b information and account pages | **Live** — batch 2 of 4, second half, committed `13ff174` and the sign-in follow-up `e1cafef` on the reviewer's "100% OK", pushed, LiteSpeed purged; theme 0.53.0 served on all seven pages, no "Draft copy", "not final" or "Still to come" to a visitor, the Terms links clickable, "Prefer email?" showing on live Contact | 29 Sep 2026 | 29 Sep 2026 | 29 Sep 2026 | Design review part 3, the pages around the renter journey (plugin 0.24.9, theme 0.53.0). Nothing changes what a form saves or who may do what. **Pricing:** buttons **Start with 1 / 2 / 3 homes** with a line under each saying what happens and what it costs ("Create your account, then pay $49 a month by card. Cancel any time." signed out; "You'll pay $125 a month by card, through Stripe." signed in), button and line in one `.plan-action` pinned to the card foot; **More than 3 homes? Contact us** under the grid; the "prices not final" sentence is a **staff note** now — visitors keep the plain discount line (prices still await Rob); card order stays ascending on every width. **About:** the doors end in a gold **Find a home** and an outlined **See membership options**, pinned level; "Draft copy" is a staff note. **How it works:** the track links are the same gold / outlined pair, level across the stretched columns; three owner questions in the FAQ (cost read from `plans()`, what happens after submitting, pausing); **Contact us** full width on phones. **Contact:** the form first on phones, the promise panel after it (seam moved to its top); "Prefer email?" with the site's configured From address when one is set (`tdh_contact_email` filter; nothing printed when none). **Sign in / Create account:** **Landlord sign in** with "Looking for a home? You don't need an account — Find a home" under the form; the subtitle names the price ("Plans from $49 a month"); Phone and Company single column, the phone with "Only for text alerts about inquiries…"; **Show / Hide** on every password field (`assets/auth.js`, `aria-pressed`, focus stays in the field; a plain field without the script); 20px gold checkboxes in 44px rows; white bordered inputs; no breadcrumb in the form column; the panel's second logo removed so the two headlines share a line. **Legal pages:** compact navy header without the photograph; **Last updated** from the page's modified date; new `Legal_Pages` module filters `the_content` on the three seeded pages — bare URLs become links (the stored Terms still had two), the "Still to come" / "Draft copy" notes and the heading over them are staff-only and labelled; the seed itself now carries links. No jump list (two sections at most). Tests: verify-page-widgets +7 (visitor vs staff, door and track classes, owner FAQ, Start buttons), verify-portal +5, verify.php +6 (the filter against the stored page). Before/after at 1440 and 390 in `D:\fahad vi backup\design-shots\g3b` (plus staff views). 20 suites, 1,949 checks, log count unchanged (185). **Follow-up on the reviewer's "can it be more beautiful?" (same day):** the auth split no longer stretches to the viewport, so the navy panel ends where the form ends; the panel gets a foot (the site's own line) pinned to its bottom and a third point on Sign in ("Inquiries in one place"); "Forgotten your password?" moved onto the Password label row, leaving two calm lines under the button; the form column centres in the row. Walk 55/55, battery unchanged |

| G4a landlord portal screens | **Live** — batch 3 of 4, first half, committed `51693db` on the reviewer's "100% OK", pushed and deployed; theme 0.55.1 served, the tab bar and More menu code present on live | 29 Sep 2026 | 29 Sep 2026 | 29 Sep 2026 | Design review part 3 (plugin 0.24.10, theme 0.54.0). **Phone navigation:** below 48rem a fixed bottom tab bar (Overview · Listings · Inquiries · Plan · Account, word under every icon, `aria-current`, unread count), the sidebar and its chevron hidden, the logo in the top bar in ink and deep gold. **Overview:** H1 "Overview"; figures in DM Sans with lining numerals and line height 1.2 ("2 of 3"); tiles 2 × 2 on phones; initials first + last ("JA"). **My listings:** status chips with counts that filter the list (`?show=`, kept by actions and paging; zero statuses hidden; "No homes in this status. Show all homes"), the allowance in the chip row, no doubled heading; each card "N views · N inquiries" once it has been public; Pause and Delete in a `<details>` **More** — in the row on a desktop, a closed labelled menu on phones (auth.js), open without a script. **Inquiries:** tabs only after a first inquiry, each with its count; the empty inbox ends with "Improve your listings". **Membership:** facts as a `<dl>`, one column on phones, no overprinting; the plan with its monthly price from `plans()`; "2 of 3 homes"; room left / "Compare plans" at the limit; **Manage billing** with what Stripe's page does, the support line only when it is unavailable; the band hidden here while the plan is simply active. **Account details:** one 600px column, white bordered fields, 14px helpers, Show/Hide on both password fields (New first, Current last), **Send code** held until a 10-digit number and the tick with the reason beside it, full-width buttons on phones. Not done, and why: separate saves for details and password (one handler), card brand and invoices on the page (Stripe API; Stripe's page shows them). verify-portal +12. Walk 26/26 through the real login at 320/390/768/1440. 20 suites, 1,962 checks, log count unchanged (185) |

| G4b listing form | **Live** — batch 3 of 4, second half, committed `3c6d1a1` on the reviewer's "100% OK", pushed and deployed; theme 0.55.1 served with the stepper and review styles | 29 Sep 2026 | 29 Sep 2026 | 29 Sep 2026 | Design review part 3, the four steps of Add / Edit a home (plugin 0.24.11, theme 0.55.1). Nothing changes what is saved, what is required or what goes back to review. **Stepper:** 1 Basics · 2 Features · 3 Photos · 4 Review in place of the thin bar (`<ol class="lform-progress lform-steps">`, `aria-current="step"`, ticks on finished steps); finished steps are links only on the review page, where nothing unsaved can be skipped; on phones only the current step keeps its name. **Fields:** one column; only short related fields pair (`lform-field--half`: city + ZIP, bedrooms + bathrooms, the four money fields, square feet + rooms, mobile + preferred contact); "$" inside every amount, "/ month" after rent, "sq ft" after square feet (`.lform-unit`); helper text under its field at 14px; placeholders "e.g. …"; "Halves allowed, e.g. 1.5" under bathrooms. **Live or paused home:** the note is one line with "Which changes need a review?" opening the list; the main button says **Save and continue**. **Step 2:** a running amenity total beside the heading ("None selected yet", "1 selected", "12 selected"). **Step 3:** the live-home permission as an amber panel that turns green when ticked; a locked drop zone says "Tick the box above first…"; **Remove photo** in red; the cover reads "✓ Cover photo" as words; captions are two-line boxes; the drop zone says "Room for N more of 10"; the description counts "N of 1,500 characters" as it is typed; under five photos a nudge (never a rule). **Step 4:** the home's state as a pill (Draft · not on the site yet / Waiting for review / Live on the site / Paused / Changes requested / Hidden); the required gaps first, then a **Worth fixing** card (no mobile number while email is the contact, no amenities, no or under five photos, no or a very short description), each a link to its step; the cover photo, title and two lines of the description before the summary; buttons full width on phones with the main one on top. Not done, and why: steppers on bedrooms and bathrooms (the number fields take arrows and typing); a "save and stay" on steps 2–3 (the handler moves on; the next page confirms); hiding "Which utilities" for "Not included" (it would change what is saved). Tests: verify-listing-form +23, verify-listing-actions +3. Walk 36/36 through the real login at 320/390/768/1440 (a new draft, then Edit on a live home). 20 suites, 1,988 checks, log count unchanged (186 — the extra row is the reviewer's own sign-in) |

| G5a administrator's portal screens | **Live** — batch 4 of 4, first half, committed `644f51d` on the reviewer's "100% OK", pushed and deployed; theme 0.56.0 served with the admin block, auth.js with the Add-panel code | 30 Sep 2026 | 30 Sep 2026 | 30 Sep 2026 | Design review part 3, the team's six portal screens (plugin 0.24.12, theme 0.56.0). Nothing changes who may do what or what any form saves. **One primary per screen** on the heading's line: Add listing, **Add member**, **Add facility** (the "+" cards are gone; the button opens the form panel with the cursor in Name, `?add=1` without a script, Cancel closes it). **Overview:** the pending tile first, tinted while there is work ("1 home waiting · Review now"), every tile a link to its filtered list; the queue rows carry Approve and Request changes (`mk_queue_row()`, shared with Listings); health figures in the sans face, "needs follow-up" beside Past due when above zero. **Listings:** the queue's Approve is a green outlined button (the header keeps the one filled navy); a waiting home is not listed again under All listings; each row's location sentence collapsed to the pill plus Set location, with one banner ("6 homes have no map point yet — they won't appear in distance search or be approved until they have one. Show them") and a **Needs location** chip (`?needs=location`); every chip counts (`wp_count_posts`); Edit and View/Preview at the end of each row; "No landlord yet"; the coordinates box says "e.g."; the reason waits inside the Set-location panel. **Members:** search (name, email, username) and status chips with counts; "7 members" / "3 members match 'jo'" / "No members match … Show all members"; plan slugs → "Standard plan", "Three-home plan" (a plan named only by its size is not repeated beside the usage); **No plan** neutral; **Over limit** beside "7 homes · plan covers 2"; round avatars from first and last initials; the email on its own line; the row is 2.75rem. **Facilities:** a pill only for no map point or **Hidden from search**; otherwise "Hospital campus · 320 E North Ave, Pittsburgh 15212" and "Shown on 4 property pages" (`Proximity::usage_counts()`, the pages' own answer). **Inquiries:** "4 messages · 3 unread · newest first"; each row one link, bold with a **New** pill when unread, the time on the right ("2 hours ago", "23 Sep"), no gold dot. **Listing setup:** one list (icon · name · "8 configured" · **Manage** → the term manager for whoever may edit terms, else "Changed by the site administrator"); no "Milestone 2" wording, no footer note; the hospitals form at 40rem with "facilities" and "miles" inside narrow fields; **Save hospital settings**. Not done, and why: the "Viewing as" persona on the review bar (a local review tool); the seed data ("Test Clinic", "fxnchf") — Rob's OK, Later list; the wp-admin screens — G5b. Tests: verify-portal +19 and two updated. Walk 43/43 through the real login at 320/390/768/1440. 20 suites, 2,007 checks, log count unchanged by the suites and walks (192 — the six rows since G4b are the reviewer's own sign-ins and address lookups). From the reviewer's walk the same day: the Members search icon centred on the field, the field tinted at rest and white on focus like every other field; the sidebar toggle's chevron centred in a square button (both portals); the Facilities list opens with "6 facilities · all shown in search". A richer row and then a card grid for Facilities were tried and taken out again at the reviewer's request — the plain rows stay. Walk 44/44 |

| G5b wp-admin for the site administrator | **Live** — batch 4 of 4, second half; the last design task, committed `037f6aa` on the reviewer's "100% OK", pushed and deployed (run #101); the public pages answer 200 with no PHP output, wp-admin sends a signed-out visitor to the sign-in page, and the deployed `verify.bat` lists `verify-admin.php` | 30 Sep 2026 | 30 Sep 2026 | 30 Sep 2026 | Design review part 3, the two WordPress screens (plugin 0.24.13; the theme is untouched). Nothing changes what is logged, what a listing stores or who may do what. **Elementor's opt-in notice** dismissed once for the site (`Listing_Table::quiet_elementor()`, the option its own "No thanks" writes). **Logs:** the eleven feature tabs are six groups plus All and System status (`Log_Screen::groups()`: Homes & locations · Members & payments · Messages · Sign-in · Settings · System; a feature added by filter gets its own tab; `?tab=email` still works; `Log::query()` takes several features); on phones the groups are a **Section** select beside Show; **Show** defaults to warnings and errors when the period has any, with a lead line ("12 warnings and errors in the last 7 days. Show everything") and `?level=all` respected; warning and error rows carry an amber or red left edge as well as their pill; the Details column is gone — the line itself is the `<details>` and opens its context, with a chevron; times in Eastern Time named ("4:26 AM ET", `Log_Screen::zone()`, filter `tdh_log_timezone`), UTC on hover; stored keys in a message read as words (`words()`: past_due → past due, tdh_paused → paused…); "Sign-in · admin · admin" says the actor once. **Listings table** (`Admin\Listing_Table`): photo · Title · **Status** pill in the portal's words · **Landlord** (a name linking to their homes, or "No landlord") · **Rent** ($2,400, right-aligned, sorts as a number) · **Map point** (Set / Not found / Not set) · Property type · Neighborhood · **Updated** (one date, the time on hover); Author, Date and City gone; no " — Pending" after a title; on phones the photo stays beside the title and the status sits straight under it (WordPress's off-screen Edit / Trash links no longer reserve 70px of blank there); those links and the other columns show when the row is expanded with WordPress's own "Show more details". **Staff without manage_options** opening the WordPress table are sent to the portal's Listings (`staff_destination()`). Not done, and why: the other wp-admin screens (Email delivery, Payments, Security — each one form already); a WordPress-side "Assign a landlord" (the portal's job). Tests: new `verify-admin.php` (28), verify-log +9 and one updated; `verify.bat` runs 21 suites. Walk 18/18 through the real login at 1440 and 390, including a non-administrator staff member sent to the portal. 21 suites, 2,044 checks, log count unchanged (192) |

Versions: plugin 0.24.16 · theme 0.56.4 (R56 Within unlocks at once, 4 Oct 2026; homes-list page numbers styled; R55 homes from the address list and R54 shortest password 8, 3 Oct 2026; theme 0.56.2 hospitals card, 30 Sep 2026)
