# Milestone 2 — checklist

**What this is:** the tick-list for Milestone 2. One place to see what is
done, what is waiting on whom, and what is left before the client can
accept the milestone and pay.

**How to use it**

- Tick `[x]` only when the thing has actually happened, and add the date.
- A task's "Check on localhost" boxes are ticked by the reviewer, not the
  developer. The developer's own testing is not a tick.
- The plan behind every item is `MILESTONE-2-PLAN.md` (task cards in §5).
  The whole project is explained in `PROJECT-HANDBOOK.md`.
- Update this file in the same commit as the work it records.

Last updated: 20 September 2026.

---

## 1. Before and alongside the code

### Actions

- [ ] Milestone 1 approval recorded on Upwork
- [x] Request list sent to Rob on WhatsApp (17 Sep 2026)
- [ ] Twilio account created in Thirty Day Homes LLC's name, login received
      — login received 17 Sep 2026 (account email is Platinum Level Property
      Services; asked Rob which legal business registers, to upgrade from
      trial and add a card, and for EIN, address and contact). Rob says he
      upgraded it (18 Sep 2026). Legal name (Thirty Day Homes LLC), EIN and
      business address received 18 Sep 2026 — kept in the WhatsApp group,
      not in the repo. Contact person received 18 Sep 2026 (Rob, Managing
      Member); contact email confirmed as the login email ("platinum…"),
      18 Sep 2026. Before the A2P *campaign*
      is submitted, the site's privacy policy needs an SMS section (mobile
      numbers not shared or sold) or carriers usually reject it
- [ ] A2P 10DLC brand + campaign registration submitted (1–3 week approval)
- [x] A2P registration approved — campaign `CM0479506a…` **Approved** in Trust Hub → Registrations → A2P 10DLC Campaigns (seen 25 Sep 2026; filed 24 Sep). Next: incoming webhook on the Messaging Service, the four constants in live wp-config (test mode to Rob's number first), Rob sets up alerts, the real-phone evidence
- [x] Google Cloud account login received (18 Sep 2026) — billing on it
      not yet confirmed. The password Rob sent is refused by Google ("wrong
      password", 19 Sep 2026) — it is most likely his Twilio password, not
      his Gmail one. Asked Rob (19 Sep 2026) to skip the password: create
      the project "ThirtyDayHomes", grant our team Gmail the Owner role
      (IAM → Grant access), and send a screenshot of the billing page
- [ ] Our Gmail added to Rob's Google Cloud project as Owner — refused
      19 Sep 2026: his project ("My First Project") enforces Google's
      "Domain Restricted Sharing" policy, so outside Gmail addresses cannot
      be added. Instead Rob was sent written click-by-click steps
      (19 Sep 2026): check billing, turn on the Geocoding and Maps
      JavaScript APIs, create two restricted keys, send them. No call
      possible on our side
- [x] Billing confirmed on (20 Sep 2026) — full account activated, $300
      trial credit unused, expires 17 Dec 2026
- [x] Maps server key and browser key received (20 Sep 2026, from Rob's
      screenshots) and pasted into `wp-config.php` **on localhost**
      (`TDH_MAPS_SERVER_KEY`, `TDH_MAPS_BROWSER_KEY`). Proven working: the
      5 hospitals were looked up from their addresses, and a real test
      address resolved and measured 1.3 mi to AHN Allegheny General
- [ ] The same two lines added to `wp-config.php` **on live** (Hostinger
      file manager), then `wp tdh geocode` run there
- [x] Rob confirms the key restrictions (20 Sep 2026, screenshot of the
      Credentials page): server key → Geocoding API; website key → Maps
      JavaScript API. He also sent both keys as text. The server key
      matches what is installed (live lookups prove it). **The website key
      as text differs from the value read off his earlier photo** — before
      task C4 (the map), copy the "ThirtyDayHomes website" key text from
      WhatsApp into `TDH_MAPS_BROWSER_KEY` on localhost and live. A third
      unrestricted "Maps Platform API Key" exists in his project; we do
      not use it
- [x] Business sending address created — Rob chose support@thirtydayhomes.com
      (18 Sep 2026). The Hostinger mailbox exists but shows "Email is not
      working": the domain's DNS is in Cloudflare, so the mail records must
      be added there. Needs the Cloudflare login.
- [x] DNS login received (21 Sep 2026): Rob sent the Cloudflare login and
      the login of the Gmail it is registered under (a second Gmail, not
      the Google Cloud one) — kept in the WhatsApp group, not in the repo.
      Public DNS on 20 Sep 2026: nameservers Cloudflare, site proxied, **no
      MX records at all**; domain registered 3 Aug 2026 at Spaceship. First
      thing after signing in: invite our own team email as a Cloudflare
      member (Administrator) so we never depend on his login again
- [x] Our own Cloudflare account joined Rob's account as **Super
      Administrator** (21 Sep 2026), so DNS work no longer depends on his
      login. His Gmail still cannot be opened from here: Google sends the
      verification code to his other address
- [x] MX and SPF added in Cloudflare and live worldwide (21 Sep 2026):
      `mx1.hostinger.com` (10), `mx2.hostinger.com` (20), and
      `v=spf1 include:_spf.mail.hostinger.com ~all`. All three are "DNS
      only" (grey cloud), never proxied. The site's own A and CNAME stay
      proxied
- [x] DMARC added and live (21 Sep 2026): TXT `_dmarc` =
      `v=DMARC1; p=none; rua=mailto:support@thirtydayhomes.com`. Monitoring
      only for now; tighten to `p=quarantine` once DKIM is in place and the
      reports come back clean
- [x] **Resolved 28 Sep 2026** (was blocked 21 Sep): Rob had bought a Standard Business Email plan; support@ was in his account all along, invisible to our shared access. **Blocked 21 Sep 2026:** the mailbox itself cannot be created.
      Hostinger has no email subscription for the domain, only Business
      Web Hosting (renews 23 Jul 2028), and every attempt to claim the
      included free business email fails with "There is an active order
      for this Domain". A stuck or half-finished order from Rob's own
      attempt is the likely cause. Handed to the team lead to clear with
      Hostinger support. **Support must not change the nameservers** —
      they are Cloudflare's and the live site depends on them
- [x] DKIM added (28 Sep 2026): TXT `hostingermail1._domainkey` in Cloudflare, value from Rob's Custom DKIM page, live on Cloudflare's own nameservers; Rob pressed Verify. First saved as `hostingemail1` (missing r) and corrected the same night.
- [x] Mailbox `support@thirtydayhomes.com` exists (Standard Business Email, to 3 Sep 2027, in Rob's platinumlevelpropertyservices@gmail.com Hostinger account, which our shared access does not show) and works both ways: Rob sent a test from Webmail and our reply went back (28 Sep 2026).
- [x] Hospital list received and confirmed (18 Sep 2026) — 11 hospitals in
      Pittsburgh, Greensburg and Washington, **all 11 on the site**:
      `client-data/hospitals.md`
- [x] Test addresses received (18 Sep 2026) — 14, including Greensburg and
      Washington: `client-data/private/test-addresses.md` (not in git). The
      two missing street types confirmed the same day
- [x] Test mobile number received (18 Sep 2026) — Rob's own number, kept
      out of the repo
- [ ] Rob's written OK that email verification (#6) is Milestone 2 — the reviewer said to build it (24 Sep 2026); Rob's own yes still to record

### Rob's answers to the questions sent on 17 Sep 2026

Record the answer, or "OK" if he accepted the proposal.

| # | Question | Proposal sent | Answer |
|---|---|---|---|
| 7 | SMS wording | "New ThirtyDayHomes inquiry for [home name]. View it: [link]. Reply STOP to opt out." | **OK** (18 Sep 2026) |
| 8 | Grace period after failed payment | 7 days, then hide | **OK** (18 Sep 2026) |
| 9 | Nearest hospitals on the property page | 3 within 15 miles, distance only | **OK** (18 Sep 2026) |
| 10 | Location on maps | Approximate area, never exact | **OK** (18 Sep 2026) |
| 11 | Renter phone on inquiry form | Optional | **OK** (18 Sep 2026) |
| 12 | Copy of inquiry emails | To Rob — which address? | Rob still thinking (18 Sep) |
| 13 | Edits to a live home that go back to review | Title, description, photos, address, type, bedrooms, bathrooms | Rob still thinking (18 Sep) — A4 is built on this proposal |
| 14 | Email verification | Part of Milestone 2 | Rob still thinking (18 Sep) |
| 15 | Multi-listing discount (10 % / 15 %) | Not in scope; quote separately if wanted | **Hold off** (18 Sep 2026) |
| 16 | Listings allowed per plan | Rob to confirm | Rob still thinking (18 Sep) |

### Client updates Rob asked for (agreed 16 Sep 2026)

- [ ] Weekly update — Tue 23 Sep 2026
- [ ] Weekly update + video meeting (10 business days in) — Tue 30 Sep 2026
- [ ] Weekly update — Tue 7 Oct 2026
- [ ] Weekly update — Tue 14 Oct 2026

---

## 2. Tasks

Each task follows the same gate, in this order:

1. Built, tests green, files mirrored, hand-over sent with the localhost guide
2. Reviewer checks every "Check on localhost" box below
3. Reviewer writes **"100% OK"**
4. Committed (by the developer, that task only)
5. Pushed by the reviewer; deploy workflow green
6. LiteSpeed → Purge All; checked on thirtydayhomes.com
7. `Milestone-2-Report.docx` updated (task moved to "Completed")

### A1 · Every listing field in the wizard, the review and the page

Status: live and checked on thirtydayhomes.com, 17 Sep 2026.

Check on localhost

- [x] Step 1 shows "Rent & fees" (deposit, application, cleaning) and
      "Inquiries" with name and email already filled in (17 Sep 2026)
- [x] A wrong mobile number (`123`) is named, and the rest of the step stays
      saved (17 Sep 2026)
- [ ] An unfinished draft's step 4 shows "A few things before review" with
      links, and Submit is greyed out
- [x] Step 2 shows Utilities and Pets as three cards each (17 Sep 2026)
- [x] Choosing "Not allowed" hides the pet fee; "Allowed" brings it back
      (17 Sep 2026)
- [ ] Continue without a Utilities answer is stopped by the browser
- [x] Step 4 lists every answer in five groups, each with an Edit link
      (17 Sep 2026)
- [x] Submit for approval works (17 Sep 2026)
- [x] After approval, the public page shows the cleaning fee, the utilities
      line, "Parking: …" and "Backyard: …" (17 Sep 2026)
- [ ] No "Pet fee" row on a home that does not allow pets
- [ ] Phone width: the Utilities and Pets cards stay three across
- [ ] From step 4, Edit "The home", change an answer, press **Back** →
      the change is kept (fix of 17 Sep 2026)
- [x] From step 4, Edit a group → the main button says "Save and return to
      review" and lands back on step 4 with the change shown (17 Sep 2026)
- [x] Staff: clicking a waiting home in the approval queue opens the real
      page with the sand "Preview" bar, Approve and Request changes
      (17 Sep 2026)
- [x] Approve from that bar lands on Listings with the approved message
      (17 Sep 2026)
- [x] Landlord: their own pending home shows "In review" and "Edit listing"
      (17 Sep 2026)
- [x] Signed out: the same preview link shows the designed "This home isn't
      available right now" page (17 Sep 2026)
- [x] Phone: the preview bar's buttons sit side by side, one line each
      (17 Sep 2026)
- [x] A home with no description has no empty "About this home" heading
      (17 Sep 2026)

Gate

- [x] 100% OK (17 Sep 2026)
- [x] Plan documents committed (CLAUDE.md, MILESTONE-2-PLAN.md,
      MILESTONE-2-CHECKLIST.md, PROJECT-HANDBOOK.md, MILESTONE-1-CLOSEOUT.md,
      tools/client-report/) (17 Sep 2026)
- [x] A1 committed (17 Sep 2026)
- [x] Pushed, deploy green (17 Sep 2026)
- [x] Purged and checked on live (17 Sep 2026)
- [x] Client report updated (17 Sep 2026) · [ ] sent to Rob (reviewer
      sends later, with more tasks in it)

### A2 · Photos: cover, order, alt text, compression

Built and self-checked 17 Sep 2026 (563 checks green; browser click-through
of arrows, Make cover, description, Remove, upload of a 4032×3024 phone
photo and a HEIC). Reviewer's localhost check passed 17 Sep 2026.
HEIC: reviewer decided to **keep the refusal** (no in-browser conversion);
iPhone uploads from the phone itself arrive as JPG.

Check on localhost
- [x] Upload photos, reorder them with the buttons, order is kept (17 Sep 2026)
- [x] "Make cover" changes the photo on the card and the page (17 Sep 2026)
- [x] Removing the cover makes the next photo the cover (browser check, 17 Sep 2026)
- [x] Each photo takes a description, shown as alt text and caption (17 Sep 2026)
- [x] A very large phone photo is stored at a sensible size — 4032×3024
      → 1500×2000 upright, 392 KB → 236 KB, no EXIF (17 Sep 2026)
- [ ] Everything works by keyboard alone — not walked separately; every
      control is a native button, input or dialog
- [x] Property page: cover + four photos, "Show all photos" opens every
      photo in order with its description (17 Sep 2026)
- [x] Phone: the page shows the cover with "Show all photos" (17 Sep 2026)
- [x] A HEIC photo is refused with the iPhone setting to change (browser check, 17 Sep 2026)
- [x] At 10 photos the dropzone says all 10 are in use (17 Sep 2026)
- [x] Removing ticked photos then Continue → Back shows "4 of 10" (17 Sep 2026)

Gate: [x] 100% OK (17 Sep 2026) · [x] committed (17 Sep 2026) · [x] pushed (17 Sep 2026) · [x] live checked (17 Sep 2026) · [x] report updated (17 Sep 2026)

Live check 17 Sep 2026: plugin 0.5.0; upload, arrows, Make cover, Remove
all; preview gallery with "Show all 3 photos" on desktop and phone; test
home and its photos deleted.

### A3 · Preview, pause, resume, delete, resubmit

Built and self-checked 17 Sep 2026 (639 checks green; browser: every row
state, Pause → Resume, delete confirmation desktop and phone, paused
preview with Resume, changes-requested wizard, staff note form and its
empty-note refusal). Reviewer's localhost check passed 17 Sep 2026. During
it, the demo "Active landlord" persona turned out to have no plan (so no
Resume); fixed, and the phone layout of the rows was redesigned on request.

Check on localhost
- [x] Preview a draft: only the owner and staff can see it — built early
      inside A1 (17 Sep 2026)
- [x] Pause a live home: renters get "not available"; Resume brings it back
      (17 Sep 2026)
- [x] Delete asks for confirmation inside the page, not a browser pop-up;
      Keep it cancels (17 Sep 2026)
- [x] "Changes requested" shows the reason and an "Edit and resubmit" path
      (reviewer saw the note on the row; staff note form browser-checked by
      the developer, 17 Sep 2026)
- [x] Every listing state has a one-line explanation and one main action
      (17 Sep 2026)
- [ ] Another landlord's listing cannot be touched — covered by
      `verify-listing-actions.php`, not walked in the browser
- [x] Phone: rows, main button and delete panel fit at 320px (17 Sep 2026)

Gate: [x] 100% OK (17 Sep 2026) · [x] committed (17 Sep 2026) · [x] pushed (17 Sep 2026) · [x] live checked (17 Sep 2026) · [x] report updated (17 Sep 2026)

Live check 17 Sep 2026: plugin 0.6.0; as testuser24 a "TEST – delete" home
was submitted; as admin Request changes refused an empty note, then sent one;
the landlord saw the note, resubmitted; admin approved; Pause → Resume;
phone layout at 320px; Delete with in-page confirmation; trash emptied.
Rob's "Shady Fun in the Sun" and the other waiting homes were not touched.

### A4 · Editing a live listing

Built 17 Sep 2026; reviewed by independent readers (30 findings, the real
ones fixed with a check each); 688 checks green; browser: live note, the
confirmation page on desktop and phone (and after a refresh), Go back and
discard changes, the row after confirming, the photo-step tick, step 4
with no Submit, the paused notice and Resume → review. Reviewer's
localhost check passed 19 Sep 2026. During it, "7 of 2 used" on an
over-plan account was noticed and logged as R40 (lands in E1). Decision 8
is still open with Rob; the list of fields that go back to review is the
one we proposed to him.

Check on localhost
- [x] Changing the rent keeps the home live ("Saved. Anything you changed
      here is live now.") (19 Sep 2026)
- [x] Changing the title shows the confirmation page first; "Go back and
      discard changes" keeps the old title (19 Sep 2026)
- [x] "Save and submit for review" saves it and the home shows In review
      with "Your changes to the title are being checked" (19 Sep 2026)
- [x] The photo step of a live home is locked until the tick
      (browser-checked by the developer, 17 Sep 2026)
- [x] Step 4 of a live home has no Submit, only "View live page"
      (browser-checked by the developer, 17 Sep 2026)
- [x] A paused home saves changes and stays paused; Resume then goes to
      review (19 Sep 2026)
- [ ] Staff edits keep the status — covered by `verify-listing-actions.php`,
      not walked in the browser
- [x] Phone: the confirmation buttons stack full width at 320px (19 Sep 2026)

Gate: [x] 100% OK (19 Sep 2026) · [x] committed (19 Sep 2026) · [x] pushed (19 Sep 2026) · [x] live checked (19 Sep 2026) · [x] report updated (19 Sep 2026)

Live check 19 Sep 2026: plugin 0.7.0. As admin, "TEST – delete admin" was
approved and its title edited: it stayed Live, no confirmation (staff).
As testuser24, "TEST 2 – delete" was submitted and approved; rent change
stayed live; title change showed "Before this is saved" (desktop and phone
at 320px); discard kept the old title; "Save and submit for review" → In
review with "Your changes to the title are being checked", and the admin
saw it under "Waiting for approval". Rob's "Shady Fun in the Sun" and the
other waiting homes were not touched.

**Left on live on purpose** (reviewer may test again): "TEST – delete
admin" (instaquirk, Live) and "TEST 2 – delete new" (testuser24, In
review). Delete both, then empty the listings trash, before G1 close-out.

### A5 · Availability: blocked dates

Built 19 Sep 2026; 96 new checks (`verify-availability.php`), all suites
green; browser: step 2 section, a refused period kept in its row, the
quick-edit panel, the property page calendar and cards — desktop, 390px
and 320px. Reviewer's localhost check passed 19 Sep 2026. During it, the
success message sat above the row the page jumped to, unseen; a save now
lands at the top with the message (like Pause and Resume).

Check on localhost
- [x] Add two unavailable date ranges; they save and show on the page
      (19 Sep 2026)
- [x] A range typed backwards is refused with a reason — the browser stops
      it first; a half-filled period gets the page's own message beside the
      row (19 Sep 2026)
- [x] Overlapping ranges are merged (joined), and the page says so
      (19 Sep 2026)
- [x] The dashboard quick edit changes availability without the wizard
      (19 Sep 2026)
- [x] The property page says when a renter can move in, and the calendar
      marks taken days; "Available from 3 Nov" with 1–5 Nov taken shows
      "Available from 6 Nov 2026" (19 Sep 2026)
- [x] Phone: the date rows stack, the panel fits, one month at a time
      (19 Sep 2026)

Gate: [x] 100% OK (19 Sep 2026) · [x] committed (19 Sep 2026) · [x] pushed (19 Sep 2026) · [x] live checked (19 Sep 2026) · [x] report updated (19 Sep 2026)

Live check 19 Sep 2026: plugin 0.8.0. As admin, step 2 of "TEST – delete
admin" saved a period (live now); its card says "Available now" and its
page shows "Unavailable: 20 – 31 Oct 2026", the calendar and the month
buttons. As testuser24, the Availability panel on "TEST 2 – delete new"
(in review) said "Renters will see, once it's live"; Save showed the
message at the top; phone 320px fine. Rob's homes not touched. During it
the reviewer asked for sign-in by username (R41).

### Phase A · journey and corner-case pass (23 Sep 2026)

On the reviewer's request, A1–A5 walked against pillars 1 and 2 as every
role, the suites' own labels used as the map of what was already proven.
Plugin 0.22.2, no theme change. Wizard suite **153** (+5), listing-actions
**129** (+5); 18 suites, **1751 checks**, `verify.bat` green, log rows
unchanged by the run.

Check on localhost
- [x] A queue page left open while the landlord deleted the home: Approve
      no longer brings a trashed home back live; it says the home is no
      longer waiting for review (23 Sep 2026)
- [x] Approve on a home already live changes nothing — "Live since" keeps
      its date; Request changes from a stale page cannot take a live home
      down (23 Sep 2026)
- [x] A crafted step 1 posted at the plan's limit creates no draft — back
      to the gate (23 Sep 2026)
- [x] A wizard page left open past its nonce returns to the step that was
      posted, with the home, saying that step was not saved — not to step 1
      calling a live home "a draft" (23 Sep 2026)
- [x] Widths 390 and 1280, as a landlord through the real login: My
      listings with a one-word 60-character title and a 250-character staff
      note, the delete and availability panels, every wizard step for a live
      home and for a draft with gaps, the overview — nothing overflows, the
      long word wraps, "5 answers still needed" pluralises (23 Sep 2026)

**Three defects, all fixed and each now a check:**

| What | Why it mattered |
|---|---|
| Moderation had no status check (§18 issue 9) | Approve on a stale page set a trashed post to `publish`: a home the landlord had deleted came back, live. A second Approve re-stamped "Live since" to today; a stale Request changes took a live home down |
| The wizard handler let `full` through the gate (§18 issue 4) | A crafted step 1 at the allowance created a draft past it. The gate already answers `''` for an existing draft, so nothing legitimate needed the exception |
| An expired page mid-wizard went to step 1 and said "your work is saved as a draft" | Wrong step, and untrue on a live home. It returns to the posted step now, home carried, saying only that this step was not saved |

**Found, not built — no card asks for it:** the landlord is never emailed
when their home is approved or sent back; only the log listens to those
two actions (R48, the reviewer decides). **Not done:** the 125 % zoom pass.

Walked by the reviewer as well: a test home approved from a stale staff
page answered "no longer waiting for review" and nothing went live.

Gate: [x] 100% OK (23 Sep 2026) · [x] committed `87bb374` · [x] pushed (23 Sep 2026) · [x] live checked (23 Sep 2026) · [ ] report updated

### B1 · Geocoding

Built 19 Sep 2026; 71 new checks (`verify-geocode.php`, fake provider),
all suites green; browser: the no-key notice, "Location not found" row,
greyed Approve, Set location (refused and saved), preview bar, facility
in-page delete, landlord notice — desktop and 390px. Waiting for the
reviewer's localhost check. Reviewer's localhost check passed 20 Sep 2026.
During it the phone layout of the portal rows was tightened on request
(name keeps its width, address wraps, smaller chips aligned to the title).
The real Google lookup can only be checked once Rob's project access and a
maps key exist (the first item below).

Check on localhost
- [x] Adding a facility by address fills its coordinates — proven with the
      client's real key on 20 Sep 2026: all 5 hospitals found from their
      addresses (`wp tdh geocode --type=facility --all`)
- [x] A listing with a bad address shows "Location not found" to staff
      (20 Sep 2026)
- [x] Approve is disabled for that listing, with the reason (20 Sep 2026)
- [x] Set location: words are refused, pasted coordinates are saved and
      Approve works again (20 Sep 2026)
- [x] With no maps key configured, staff see a clear notice, nothing breaks
      (20 Sep 2026)
- [x] Deleting a facility asks inside the page (no browser pop-up)
      (20 Sep 2026)
- [x] Phone: listings and facilities rows read cleanly at 320px and 375px
      (20 Sep 2026)

Checked on thirtydayhomes.com, 20 Sep 2026 (plugin 0.9.0): clearing a
hospital's coordinates and saving looked the address up again ("its location
was found from the address"); a new facility added by address filled its own
coordinates, identical to localhost; the in-page delete asked and removed it;
"Set location" saved pasted coordinates and the public card then read
"0.6 mi from UPMC Mercy"; two real homes already show 0.3 mi and 0.4 mi;
phone at 320px and 375px clean.

Found during the live check and fixed the same day (plugin 0.9.1, theme
0.34.1): a record that had never been looked up said "The map service
didn't answer", although nothing was asked, and pointed at a "Try again"
button that is labelled "Look up the address again"; and on a phone the
status chip took the row's width, leaving the name three letters wide and
the sentence eight characters wide. The wording now names the control on
screen and no longer repeats the chip beside it; on a phone every listing
row is the same small grid (photo · name with its status · the home's line ·
a location note across the full width), the status filters wrap instead of
hiding behind a scrollbar, and the location note is one tinted block ending
in its action. 76 checks in `verify-geocode.php`.

Check on localhost (the fix)
- [ ] A home that was never looked up reads "Not looked up yet", not a
      failure, and points at "Set location"
- [ ] 320px: the name, the chip and the sentence each have room; no
      sideways scroll on Listings, Facilities and Members
- [ ] Facilities and Members rows are unchanged from before

Gate: [x] 100% OK (20 Sep 2026) · [x] committed (20 Sep 2026) · [x] pushed (20 Sep 2026) · [x] live checked (20 Sep 2026) · [x] report updated (20 Sep 2026)

### B2 · Nearest hospitals on the property page

Built 20 Sep 2026; 50 new checks (`verify-proximity.php`), all suites
green; browser: the property page with and without a location, the staff
setting, desktop and 390px. Waiting for the reviewer's localhost check.

Check on localhost
- [x] A listing shows up to three nearest hospitals with distances
      (20 Sep 2026)
- [ ] A listing with none within 15 miles says so
- [ ] An inactive hospital is not shown
- [ ] A hospital with no coordinates is not shown, and never at "0.0 mi"
- [ ] A home whose address was not found shows no list at all — the map
      note stands alone, with no empty box
- [x] The card on the search page still shows the single nearest
      (20 Sep 2026)
- [x] Listing setup: changing the count to 1 and the distance to 2 changes
      every property page; 12 facilities is refused and names the limit
      (20 Sep 2026 — the browser stops 12 before the server does; the
      server refusal is covered by the suite)
- [ ] Phone (390px): the name wraps, the distance keeps its own column

Checked on thirtydayhomes.com, 20 Sep 2026 (plugin 0.10.0): The Oakland
Townhouse lists UPMC Presbyterian 0.3 mi, UPMC Shadyside 1.3 mi and UPMC
Mercy 1.6 mi; the section ends cleanly into the footer; Listing setup shows
the setting at 3 and 15.

Gate: [x] 100% OK (20 Sep 2026) · [x] committed (20 Sep 2026) · [x] pushed (20 Sep 2026) · [x] live checked (20 Sep 2026) · [x] report updated (20 Sep 2026)

### Phase B · journey and corner-case pass (23 Sep 2026)

B1 (map locations) and B2 (nearest hospitals) walked against pillars 1
and 2 as every role, on the reviewer's request. **No defect found in the
product.** Test files only; geocode suite **88** (+4); 18 suites,
**1755 checks**, `verify.bat` green, log rows unchanged by the run.

Check on localhost
- [x] Staff: a location action for a deleted or unknown home answers
      "That listing no longer exists" and writes nothing (23 Sep 2026)
- [x] Staff: "Look up the address again" asks Google even for an
      unchanged address and takes the answer — the fingerprint is for
      saves, not for a person who pressed the button (23 Sep 2026)
- [x] Staff: the refused-key notice at the top disappears on the next
      successful lookup (23 Sep 2026)
- [x] Widths 390 and 1280, through the real login: the queue row with Set
      location open (chip, reason in words, greyed Approve, address on
      file, Google Maps link), Facilities and its in-page delete panel,
      Listing setup; the landlord's step 2 with the "couldn't find this
      address" note and "our team places it by hand"; the property page's
      Close to care (three hospitals, type in words, 0.1 / 1.0 / 1.4 mi)
      and the search cards — nothing overflows (23 Sep 2026)

Already proven by the suites and re-read rather than re-tested: the four
location states and their words, only "not found" holds approval, typed
points win and a new address replaces them, the 60-second pause and the
"tried again on the next save" wording, no key, private coordinates, the
cache dropped on any save, "1 medical facility" / "1 mile", the 1–5 and
1–100 limits named when refused, a landlord cannot change the setting.

**Also fixed on the way:** `verify-portal.php`'s "a pending listing cannot
be counted by probing ids" ran only when the site happened to hold a home
in review, and skipped in silence otherwise — the full run came out one
check short the moment no test home was pending. It makes its own now.

Walked by the reviewer as well: Set location under the row, "Look up the
address again" answering in words, typed words refused with the text kept,
the setting saved and read back on a property page, 9 facilities refused.

**Not done:** the 125 % zoom pass.

Gate: [x] 100% OK (23 Sep 2026) · [x] committed `63843f1` · [x] pushed (24 Sep 2026) · [x] live checked (24 Sep 2026, test files only) · [ ] report updated

### Phase C · journey and corner-case pass (23 Sep 2026)

C1–C6 walked against pillars 1 and 2 as a visitor, on the reviewer's
request. The search suite already proves the filters, chips, dates and
their refusals, the hospital and the typed place, the map's privacy and
the widgets, so the pass went to the URL corners and the page itself.
Plugin 0.22.3, theme 0.48.2. Search suite **249** (+8), proximity **53**
(+1); 18 suites, **1764 checks**, `verify.bat` green, log rows unchanged
by the run.

Check on localhost
- [x] http://localhost/thirtydayhomes/homes/page/9/ goes back to the
      first page of homes — not to "This home isn't available right now"
      — and http://localhost/thirtydayhomes/homes/page/2/?beds=1 keeps the
      bedrooms filter on the way back (23 Sep 2026)
- [x] http://localhost/thirtydayhomes/city/pittsburgh/page/9/ goes back to
      Pittsburgh's first page, not to /homes/ (23 Sep 2026)
- [x] A first page with no results is the empty state, never a redirect;
      a home that does not exist stays a real not-found whatever page
      number rides on it (23 Sep 2026)
- [x] http://localhost/thirtydayhomes/neighborhood/south-side/ says "No
      homes in South Side yet" (23 Sep 2026)
- [x] A 150-character keyword with no spaces: the chip, the count and the
      "No homes matching" heading wrap; nothing scrolls sideways at 390;
      the term is capped at 100 characters (23 Sep 2026)
- [x] Probed and right: whitespace-only, a script tag and quotes in the
      box; nonsense prices, `beds=2.5`, `within=0`; a same-day and a
      backwards stay explained; `sort=closest` with nothing to be close to
      explained; map view with zero results draws no empty map
      (23 Sep 2026)
- [x] Widths 390 and 1280: default, chips, empty, dated, hospital, typed
      place, long keyword, map, page 2, city archive, refused stay; on the
      phone the filter drawer opens, holds focus, Escape closes it and
      returns focus to the button (23 Sep 2026)

**Two defects, fixed and each now a check:**

| What | Why it mattered |
|---|---|
| A page number past the end answered a 404 saying "This home isn't available right now" | The not-found page is written for a missing home; a renter following a stale page link, or standing on page 3 when a filter shrank the search to one page, was told a home was gone. It goes back to the first page of the same search now, filters kept — a city page to its own first page |
| A long keyword with no spaces pushed the chip, the count and the empty heading off a phone | Sideways scroll at 390. The term is capped at 100 characters and the three places it is quoted wrap anywhere |

**One improvement:** a neighbourhood, city, type or amenity page with
nothing on it says "No homes in South Side yet", not the site-wide "No
homes are listed yet", which says more than is true.

**Also fixed on the way:** `verify-proximity.php` assumed the hospital
setting was at its defaults and then **deleted both options** in its
cleanup — every full run on a machine where staff had changed Listing
setup failed two checks and wiped the setting. The reviewer's 9-mile
radius from the Phase B check showed it. It pins the defaults for the run
and puts back exactly what it found, and says so in a check. And
`verify-portal.php`'s "no invented 284 anywhere" failed on any *substring*
"284" — a nonce, an id in a link, a price — which post ids in the 18000s
made a matter of time; it now looks for 284 as a number on screen.

**Not done:** the 125 % zoom pass.

Walked by the reviewer as well (23 Sep): a page past the end back to the
first page with its filter, a city page back to the city, "No homes in
South Side yet", the long keyword wrapping, the phone drawer.

Gate: [x] 100% OK (24 Sep 2026) · [x] committed `ea83c7a` · [x] pushed (24 Sep 2026) · [x] live checked (24 Sep 2026: theme 0.48.2 on `/account/`, no warnings) · [ ] report updated

### R42b · Event and failure log with a staff screen (internal)

Built 20 Sep 2026 on the reviewer's word ("R42b next"); 75 new checks
(`verify-log.php`), all 15 suites green. First hand-over put the screen in
the marketplace portal; the reviewer's check moved it to wp-admin
(Listings → Logs) — the portal is the client's, the log is ours — and
found the test suites' fixtures filling the log (202 lines), so suites are
now silent. Browser: All, a feature tab, System status and the empty state
at 1280px and 390px. Waiting for the reviewer's localhost check.

Check on localhost
- [ ] wp-admin → **Listings → Logs** exists, with a red bubble; the
      marketplace portal has **no** Logs item
- [ ] The screen has a tab per feature and System status
- [ ] A wrong password on the sign-in page appears under Sign-in as a
      warning within seconds, with the email masked (`l***@…`)
- [ ] Approving or pausing a home appears under Listings, naming the home
- [ ] "View" opens the details in place; no secret or full email anywhere
- [ ] "Errors only" hides warnings; a search that matches nothing says so
- [ ] System status names each service with a worded state; SMS says
      whether it is on, test mode and its number, and the last text sent
      (0.24.5 — it said "Not built yet" after D4 shipped; found on live
      27 Sep 2026)
- [ ] Running `verify.bat` adds **no** lines to the log
- [ ] Phone (390px): each line is a small card; nothing scrolls sideways

Checked on thirtydayhomes.com, 20 Sep 2026 (plugin 0.11.0, deploy #43 —
the first deploy gated by the suites): Listings → Logs opens for the
administrator, and the first real line was the reviewer's own wrong
password, IP masked. The `where` column read `pluggable.php:do_action`
(WordPress plumbing); the source finder now skips core frames so it names
the plugin code instead — follow-up awaiting "100% OK".

Gate: [x] 100% OK (20 Sep 2026) · [x] committed (20 Sep 2026) · [x] pushed (20 Sep 2026) · [x] live checked (20 Sep 2026) · [x] report — internal, not in the client report

### C1 · Filters, sorting, chips, mobile drawer

Built 20 Sep 2026; `verify-search.php` grew from 22 to 86 checks, all
suites green; browser: the bar, chips and count, the empty state, the
swapped-prices notice, the city archive, the phone drawer open and closed
at 1280px and 390px. **Live and checked on thirtydayhomes.com,
21 Sep 2026** (commits 2234787 and 1791dff).

**One defect reached live and was fixed the same day.** The break-it step
of the check guide found that `?min_price=-99` had its minus sign stripped
along with the dollar sign and the commas, so a value nobody could have
meant came back as a real "From $99" chip. A price with a minus sign is
now refused outright, and the suite gained three checks that would have
caught it. Lesson recorded: test the negative, not only the nonsense.

Check on localhost
- [x] Price, bedrooms, bathrooms, type and pets each filter correctly
      (21 Sep 2026, verified on live: sort low to high gave 1,000 → 1,895
      → 2,450 → 3,200 and high to low the reverse; "No pets" returned one
      home, worded "1 home matches your filters")
- [x] Filters combine; each chip removes only its own filter; Clear all
      works (21 Sep 2026)
- [x] No match says which filters caused it, and never shows every home —
      "No homes over $90,000. Loosen a filter, or clear them all to see
      every home." (21 Sep 2026)
- [x] Copying the URL into a new tab reproduces the search (21 Sep 2026)
- [x] Phone: the filter drawer opens, traps focus, closes with Escape —
      reviewer checked at 320px (21 Sep 2026)

Gate: [x] 100% OK (21 Sep 2026) · [x] committed (21 Sep 2026) · [x] pushed (21 Sep 2026) · [x] live checked (21 Sep 2026) · [x] report updated (21 Sep 2026)

### C2 · Date search

Built 21 Sep 2026; `verify-search.php` grew from 86 to 132 checks, all
suites green. Browser-checked at 1280, 390 and 320 px. **Live and checked
on thirtydayhomes.com, 21 Sep 2026** (commit 677b403, deploy #48).

Check on localhost
- [x] Home page dates show only homes free for the whole stay (21 Sep
      2026, and confirmed again on live: 4 homes narrowed to 2 for
      1 Nov – 5 Dec 2026, each card reading "Free for your dates")
- [x] A stay under 30 days is refused with a reason — "Stays are 30 days
      or longer — try a later move-out date. We searched without dates."
      (21 Sep 2026)
- [x] A home blocked inside the dates is excluded, and the empty state
      says why — "No homes over $90,000 free 1 Nov – 5 Dec 2026."
      (21 Sep 2026)
- [x] Every other refused stay says what happened: a past move-in moved
      to today, a move-out with no move-in, a backwards pair, and a date
      past the three-year horizon (21 Sep 2026, all four read on live)
- [x] The property page repeats the stay above the calendar with a tick
      or a cross, and shows nothing when there are no dates (21 Sep 2026)

Gate: [x] 100% OK (21 Sep 2026) · [x] committed (21 Sep 2026) · [x] pushed (21 Sep 2026) · [x] live checked (21 Sep 2026) · [x] report updated (21 Sep 2026)

### C3 · Search by hospital

Built 21 Sep 2026; `verify-search.php` grew from 132 to 178 checks, all
suites green. Browser-checked at 1280, 390 and 320 px. **Live and checked
on thirtydayhomes.com, 21 Sep 2026** (commit f1fe0fc, deploy #50).

Check on localhost
- [x] Choosing a hospital lists the closest homes first (21 Sep 2026;
      confirmed again on live: 4 homes near UPMC Mercy at 0.6 then
      1.6 miles)
- [x] Each card shows the distance to the chosen hospital, not to its own
      nearest one (21 Sep 2026)
- [x] Typing a hospital name in the search box finds nearby homes
      (21 Sep 2026)
- [x] The radius narrows, and an empty result offers the next radius out
      by name — "Look within 10 miles instead" (21 Sep 2026)
- [x] "Closest" with no hospital shows the newest and says why
      (21 Sep 2026)
- [x] A hospital switched off for renter search, or one with no
      coordinates, is never offered and is refused if put in the URL by
      hand (21 Sep 2026)

Note: facility ids differ between localhost and live (UPMC Mercy is 150
locally, 43 on live), so a hand-written `facility=` link does not carry
between them. The select always writes the right id.

Gate: [x] 100% OK (21 Sep 2026) · [x] committed (21 Sep 2026) · [x] pushed (21 Sep 2026) · [x] live checked (21 Sep 2026) · [x] report updated (21 Sep 2026)

### C4 · Map view

Built 21 Sep 2026; `verify-privacy.php` grew from 9 to 32 checks and
`verify-search.php` from 178 to 198, all suites green. Browser-checked at
1280 px, with the map drawing.

**The browser key was wrong, by one character, and that cost a day.**
`TDH_MAPS_BROWSER_KEY` held the *Maps Platform API Key* transcribed from a
photo, and Google answered `InvalidKeyMapError` — the reply reserved for a
key it has never seen, as opposed to `ApiTargetBlockedMapError` for a real
key pointed at the wrong service. Rob's typed website key was wrong too:
it arrived with seven Cyrillic look-alikes (А Е С М В і) and a lowercase L
where the real key has a capital I. The correction came from comparing the
*server* key we already had working against the one he typed: he writes
`l` where the key has `I`. Applying that single substitution produced a
key Google recognises. Installed on localhost 21 Sep 2026; the old file is
kept as `wp-config.php.bak-c4`.

- [x] The same corrected key added to `wp-config.php` on live (21 Sep
      2026); the live map draws.
- [ ] Ask Rob to confirm the website key's *application* restriction lists
      `thirtydayhomes.com/*` (its API restriction is already Maps
      JavaScript API only, which is correct).
- [ ] **Rotate the server key.** The real one was committed as sample data
      in `verify-log.php` (commit 7f90d5d) and is in the repository's
      history. It is replaced with an invented value as of 21 Sep 2026,
      but the old key still exists in Rob's project. Before hand-over: Rob
      creates a new key restricted to the Geocoding API, we install it in
      both `wp-config.php` files, he deletes the old one.

Check on localhost
- [x] List / Map toggle works and is kept in the URL (21 Sep 2026)
- [x] Map shows approximate areas, never exact pins — five circles across
      Pittsburgh on the results map, one over Oakland on the property page
      (21 Sep 2026)
- [x] Page source contains no street address and no exact coordinates —
      every published point has two or three decimals against the real
      seven, and each real value was searched for and is absent
      (21 Sep 2026)
- [x] With no key, or a key Google refuses, only the list shows and
      nothing breaks — seen for real twice, with our own wording and a
      "Show the list" link rather than Google's console message
      (21 Sep 2026)

Live check, 21 Sep 2026 (deploy #52, commit 4693aec)
- [x] The map draws on thirtydayhomes.com: four homes, four price tags,
      gold areas on the quiet cream base
- [x] A price tag opens a card with the name, place, price, the area note
      and a working close button
- [x] **The release blocker holds on live.** Every published point has at
      most three decimals; no coordinate with five or more decimals
      appears anywhere on the results map or on a property page

Gate: [x] 100% OK (21 Sep 2026) · [x] committed (21 Sep 2026) · [x] pushed (21 Sep 2026) · [x] live checked (21 Sep 2026) · [x] report updated (21 Sep 2026)

### C5 · Elementor widgets for search and nearby hospitals

Built 21 Sep 2026; `verify-page-widgets.php` grew from 44 to 71 checks,
all suites green. The editor was opened in a real browser to confirm it.

**Nothing is seeded, and this is not an omission.** The card asks for the
archive and single Elementor layouts to use the widgets. There are none:
those pages are theme PHP templates by design (handoff §3.1), and an
Elementor layout for an archive needs the Theme Builder, an Elementor
**Pro** feature this project deliberately does not use. The widgets are
available for any page someone builds.

Check on localhost
- [x] Both widgets appear in the ThirtyDayHomes category in Elementor —
      "Search results" and "Nearby hospitals", read out of the real panel
      (21 Sep 2026)
- [x] Real listings and distances render inside the editor — the canvas
      showed the search bar, the live filter row and real homes, and the
      hospitals band previewed a real home with its distances
      (21 Sep 2026)
- [x] Changing a heading in the editor shows on the page — "Homes for
      your stay" typed into the panel appeared in the canvas (21 Sep 2026)
- [x] The hospitals band shows nothing to a visitor when there is no home
      to measure from, and an example plus an explanation in the editor —
      both halves checked in a real browser (21 Sep 2026)
- [x] With Elementor switched off the two new shortcodes still render
      (21 Sep 2026)
- [x] A page built with the widget gets the full width, the filter drawer
      works on a phone, and asking for the map draws it — all three were
      broken at first and are now covered by checks (21 Sep 2026)
- [x] Nothing extra is loaded on a page without the widget (21 Sep 2026)

- [x] The map draws on a widget page, not only on Find a home — five price
      tags, no console errors (21 Sep 2026)
- [x] Widths 320, 390, 768, 1024, 1280, 1920: no sideways scroll at any of
      them, cards one / two / three across as the width allows
      (21 Sep 2026)
- [x] Keyboard: every control reachable in a sensible order, each with a
      visible focus indicator (a gold glow on fields, an outline on
      buttons and links), and the disabled radius correctly skipped
      (21 Sep 2026)

Roles walked: administrator and staff in the editor; logged-out visitor on
a page built with the widget, at every width above. Landlords and renters
are unaffected — no portal screen changed.

**Known limits, none of them faults:** nothing is seeded, because the
archive and single pages are theme templates and an Elementor layout for
them needs Pro; there is no "default sort" control, because the sort is in
the URL where a renter set it; and the hospitals band cannot be placed on
a real property page for the same Pro reason, so in the editor it previews
a real home and says it is an example.

Gate: [x] 100% OK (22 Sep 2026) · [x] committed `6c7ba77` · [x] pushed · [x] live checked (23 Sep 2026, per plan §10) · [x] report updated (24 Sep 2026)

### C6 · A postcode or area finds the nearest homes (R44 / R18)

Built 22 Sep 2026. `verify-search.php` grew from 198 to 236 checks and
`verify-geocode.php` from 74 to 84; all 14 suites green, 1233 checks.

**Why this is a fix and not a feature.** Rob searched 15226 on the live
site and got "No homes matching 15226". Every home is in a different
postcode, so almost any real postcode answered nothing — while the live
Renter FAQ already promised "when you search by location or ZIP code,
homes appear closest to farthest". It was logged as R18 on 16 Aug and
wrongly recorded as delivered by C3.

**What changed.** A term that names a place — a postcode, a town, a
neighbourhood, a county — is now looked up as a point on the map instead
of being matched against stored text, and the results become every home
near that point, closest first. Everything else still searches by text.

Check on localhost
- [x] `?q=15226`, a postcode holding no homes, answers "5 homes within 15
      miles of 15226" instead of an empty page (22 Sep 2026)
- [x] Each card says how far it is from the postcode, and still says how
      far it is from care (22 Sep 2026)
- [x] The radius wakes up without choosing a hospital: 5 miles gives 1
      home, 15 gives 5, "Any distance" gives all 7 (22 Sep 2026)
- [x] "Any distance" puts the two homes that were never geocoded last,
      with no invented number beside them (22 Sep 2026)
- [x] `?q=Pittsburgh` keeps the homes that say Pittsburgh but have no
      coordinates — last, rather than dropped (22 Sep 2026)
- [x] A home's name (`?q=Zephyr`), a hospital's name (`?q=Mercy`) and
      nonsense (`?q=zzqqxx`) all still search by text; nonsense still
      answers "No homes matching “zzqqxx”", never every home
      (22 Sep 2026)
- [x] A chosen hospital always wins over anything typed in the box
      (22 Sep 2026)
- [x] An impossible filter over a postcode reads "No 9+ bedroom homes
      within 15 miles of 15226." and offers "Look within 25 miles
      instead" (22 Sep 2026)
- [x] A sort the renter picks afterwards still wins over closest
      (22 Sep 2026)
- [x] The map view draws the same five homes (22 Sep 2026)
- [x] Widths 1280 and 390: no sideways scroll, the distance line reads
      cleanly above the hospital band (22 Sep 2026)
- [x] Keyboard: with a postcode typed the radius is reachable and the
      sort reads "Closest to 15226"; with nothing typed the radius is
      disabled and correctly skipped, and the sort reads "Closest first"
      (22 Sep 2026)
- [x] No suite can reach Google: `TDH_OFFLINE` is set by `verify.bat` and
      by CI, and a check proves the live service is unreachable during a
      run even on this machine, which holds the real key (22 Sep 2026)

Roles walked: logged-out visitor and renter (the search is public and
read-only). Landlord, staff and administrator screens are untouched. No
role boundary moved: the lookup runs on the server and the browser never
receives a home's exact coordinates.

**Known limits, none of them faults:** the lookup needs the Geocoding key,
and with no key, a refused key or a slow service the search falls back to
the old text matching rather than to an error — silently to the renter,
with a line in the event log for us. One lookup per term is then cached
for a month, so the cost is a few requests a day, not one per search.

**Fixed after the first hand-over (22 Sep 2026, theme 0.42.1).** The
reviewer spotted that the distance row sat hard against the facts above it
and its pin was off the text's centre. The row is rendered inside
`.property-body`, but every rule for it was scoped to `.property-card`, so
the stylesheet matched nothing and the row came out unstyled. Every other
check passed, because the markup and the words were right — only the
pairing of selector to parent was wrong. Now scoped to `.property-body`,
with 12px above and below, and two checks added that fail if the two ever
disagree again.

Gate: [x] 100% OK (22 Sep 2026, after the reviewer's own walk at 1280, 80 % zoom and 375) · [x] committed `3d602c6` · [x] the spacing fix, and the truncated radius hint the reviewer found on live, committed `fcb92f6` · [x] pushed (C5, C6 and the spacing fix are live; the hint fix is waiting) · [x] live checked 22 Sep · [ ] report updated

### D1 · The enquiry form and its own success screen

Built 22 Sep 2026. New suite `verify-inquiry.php`, 64 checks; all 15
suites green, 1304 checks. Plugin 0.18.0, theme 0.43.0.

**The order is the feature.** Store first, notify second. The renter's
success depends on one thing — the record existing — so D3's email and
D4's SMS hang off `tdh_inquiry_received` and cannot turn a saved enquiry
into a failed one. That is the contract's own rule: "a notification
failure must not silently discard a successfully stored inquiry."

Check on localhost
- [x] A visitor sends an enquiry and lands on its own confirmation screen,
      naming the home — walked in a real browser at 1280 and 390, no
      console errors (22 Sep 2026)
- [x] The form is gone from that screen, replaced rather than left sitting
      underneath, and offers "Browse more homes" (22 Sep 2026)
- [x] A mistake keeps everything typed and names the field, not the form —
      including the address exactly as typed, so a near-miss like
      "dana@example" is not wiped (22 Sep 2026)
- [x] Back and Forward after sending re-send nothing: the only enquiry POST
      the browser made was the original one (22 Sep 2026)
- [x] The same person writing to the same home twice inside ten minutes is
      told it was sent, and the landlord gets one enquiry, not two
      (22 Sep 2026)
- [x] A different person, or the same person about a different home, is a
      new enquiry (22 Sep 2026)
- [x] An enquiry about a paused home is refused and says the home came off
      the market (22 Sep 2026)
- [x] A move-in date in the past is refused with its own reason; the field
      cannot even offer one (22 Sep 2026)
- [x] Six enquiries in an hour from one address is stopped, and the sixth
      is told why (22 Sep 2026)
- [x] Every control has a real `<label for>`, checked as a rule rather
      than a count (22 Sep 2026)
- [x] A refused field points at an explanation that exists — the first
      version pointed at `tdh-err-move_in` while the error was
      `tdh-err-move-in`, so a screen reader heard nothing and the screen
      looked fine (22 Sep 2026)
- [x] The rules reminder is a link a renter can follow, and the accepted
      version is stored (R24, both halves) (22 Sep 2026)
- [x] A refused enquiry hands its typed values back once and never again,
      so the next visitor cannot read them (22 Sep 2026)

**Design reworked after the first look (22 Sep 2026).** The reviewer said
it did not look good, and two things were wrong:

- The terms line borrowed the theme's `.fine`, which is a **flex row**
  built for an icon beside a word. One sentence with a link in the middle
  came out as three columns: "Sending an enquiry accepts our | house rules
  and terms | , as they stand today." It has its own class now, and a
  check fails if it ever borrows that one again.
- The site-wide field padding is built for a full-width form. In a 340px
  column it gave **60px-tall inputs and a 970px form** — a wall of empty
  rectangles that pushed Send below the fold. Tightened to 44px inputs and
  an 829px form, with a rule above the heading so the form reads as a
  separate thing to do rather than one more row of the fee table. The font
  stays at 16px: below that, iOS zooms the page in on focus, and a form
  that jumps on tap is worse than a tall one.

**Split into two cards on the reviewer's call (22 Sep 2026).** The price
and fees are something a renter *reads*; the form is something they *do*.
In one container the form read as the last row of the fee table — and the
single card had reached **1178px**, taller than a laptop viewport, so the
`position: sticky` on it could never engage and the price scrolled away
regardless. Now:

- **Price card** — 329px, keeps the border and shadow, and genuinely pins:
  measured at top 956 → 556 → 156 → 20px while scrolling, still on screen
  at 1200px down, which was impossible before.
- **Enquiry card** — lighter border, no shadow, plainly secondary. One
  primary thing per screen, and the price is it.
- **Nothing is pinned**, and that is a decision, not an omission. Both
  cards scroll with the page. On a phone they stack, as before.
- Five checks added: the two cards exist in that order, the enquiry hook
  fires inside the second, the stylesheet scopes the form to that same
  card, nothing in the column is pinned, and nothing caps its height.

**Why nothing is pinned — measured, not assumed.** The column is
**1207px** and a laptop viewport is **~900px**. An element taller than the
screen cannot be pinned without paying somewhere, and over four attempts
this task paid every price there is:

| Attempt | What it cost |
|---|---|
| One 1178px card, `position: sticky` | `sticky` cannot pin what does not fit, so it never pinned at all — the price scrolled away on every home, and had done since before this task |
| Split, pin the price card only | The form beneath slid up *behind* the pinned card, hiding the fields being filled in, and it released early because the column was only as tall as its content |
| Pin the column, cap it, scroll inside | Reachable, but the card was sliced off mid-field at the cap — and with the inner scrollbar hidden nothing said it continued, so it read as a rendering fault. This is the one the reviewer saw |
| Pin the column by its bottom edge | Correct in principle; Chrome declines to engage bottom-sticky for a grid item taller than the scrollport, so it simply never pinned |

Plain scrolling costs nothing: the renter scrolls to the form, fills it in
and sends. **Reviewer's decision, 22 Sep 2026, after being shown the
measurements and the three alternatives.** To pin anything here later, the
column has to be made short enough to fit the screen first — the way to do
that is to move the form out of the sidebar and leave the 329px price card
in it alone.

*(One of those rounds was mine: I misread "the enquiry card doesn't need
sticky" as applying to the whole column and removed the pinning before it
had been decided.)*

The success screen gained from it too — the price card stays put while
only the form is replaced by the confirmation.

**Known limits, none of them faults:** nothing is emailed or texted yet —
that is D3 and D4, and the event they listen to is already firing. The
enquiry is visible in wp-admin → Inquiries; the landlord's dashboard view
is D2. The rules version is a constant (`Inquiry::RULES_VERSION`), bumped
by hand when the Terms change, because deriving it from a page's modified
date would silently re-date every past agreement.

**Found during the reviewer's own walk, and left for D2 on purpose.** The
wp-admin inquiry screen shows `Listing: 3329` and `Expected stay: 30` —
stored keys, not words, which is what rule R31 exists to prevent. Both
come from A1's generic readonly meta box, so they affect every field of
that kind, and D2 is the task that builds the real inquiry screens. Not
touched here.

**"Visibility: Public" on that screen is WordPress's wording for a
`publish` status, not a statement about the front end.** Proved rather
than assumed: the type is `public => false`, `publicly_queryable =>
false`, `show_in_rest => false`; the renter's email, phone and message
are in no REST response; and over real HTTP as a logged-out stranger
`?p=<id>` is 404, `/wp-json/wp/v2/tdh_inquiry` is 404, and a site search
for the renter's name returns nothing. A search for their *address*
looks like it echoes it — that is the search term from the URL landing in
the page title and the RSS link, not a stored record.

Gate: [x] 100% OK (22 Sep 2026) · [x] committed `9556eea` · [x] pushed · [x] live checked (23 Sep 2026, per plan §10) · [x] report updated (24 Sep 2026)

### D2 · Inquiries in the dashboards

Built 22 Sep 2026. `verify-inquiry.php` 75 → **101 checks**. Walked in a
real browser signed in through the login form as a fixture landlord with
23 messages across 3 homes.

**Logging, which D1 shipped without.** `Log` listened to the Contact page
but to nothing in the inquiry pipeline, so an enquiry arrived, was stored
and left no trace at all. Three events now fire and `Log` subscribes:
`tdh_inquiry_received` (info), `tdh_inquiry_refused` (**warning** — each
one is a renter who could not reach a landlord) and `tdh_inquiry_filed`.
The renter's address reaches the log **masked**.

Check on localhost
- [x] Inbox at `?view=inquiries` with All / Unread / Archived; the unread
      count on its own tab and in the nav, and the two agree (22 Sep 2026)
- [x] 21 messages over 2 pages, 20 then 1 (22 Sep 2026)
- [x] Unread shown as the word "New" and a heavier name, not colour alone
      (22 Sep 2026)
- [x] Every row names which home it is about (22 Sep 2026)
- [x] A message about a **deleted** home is still there, marked "Listing
      removed" in italics (22 Sep 2026)
- [x] Opening one reveals email and phone as working links, the move-in
      date, the length of stay and the message (22 Sep 2026)
- [x] Opening marks it read and the badge drops **in the same request** —
      16 → 15, nav and list agreeing (22 Sep 2026)
- [x] Archive moves it to its own tab; "Move back to inbox" returns it;
      doing either twice is harmless (22 Sep 2026)
- [x] Another landlord cannot read or archive one, and is told exactly
      what a missing id is told (22 Sep 2026)
- [x] A Contact-page message never reaches a landlord's inbox, but staff
      can read it (22 Sep 2026)
- [x] A record with **no** `_tdh_read` row and one stored as `false` are
      both unread, and the count finds both (22 Sep 2026)
- [x] Staff read a message inside the portal; no link into wp-admin
      (R29) (22 Sep 2026)
- [x] Widths 320 and 390: rows stack, tabs wrap, nothing cut off
      (22 Sep 2026)

**Three bugs the walkthrough fixture caught that a green suite did not.**
Each is now a check and a handbook entry:

| What | Why it mattered |
|---|---|
| `post_status => 'any'` excludes trash | Deleting a home silently deleted its conversations, against the promise the delete confirmation makes |
| `Accounts::url( 'dashboard' )` is not a key | It answers with the **home page**, so every inbox link pointed at the front of the site and looked fine until clicked |
| The badge was counted from fetched rows, then again before `mark_read` | Thirty unread showed as "4"; opening one left the nav one ahead of the list |

**Known limits, none of them faults:** the landlord's overview still shows
a four-row taste of the inbox rather than the full screen, which is what
the card asks for; SMS notification state on the staff row is D4, and the
column is not shown until there is something true to put in it. Email
delivery state arrived with D3.

Gate: [x] 100% OK · [x] committed (`68903f2`) · [x] pushed · [x] live checked (22 Sep 2026: theme 0.45.0 on `/account/`) · [x] report updated (24 Sep 2026)

Deploy confirmed live 22 Sep 2026: theme **0.45.0** is being served. Read
it on `/account/`, not the home page — the host serves a **cached** home
page that still advertised 0.44.0 and made the deploy look as though it
had never landed. The inbox itself is not ticked because checking it means
signing in to the live site as a real landlord.

### D3 · Inquiry email, log and retries

Built 22 Sep 2026, then walked against pillars 1 and 2 on 23 Sep, which
found four more things. New `verify-notifications.php`, **167 checks**; 16
suites, **1511 checks**, `verify.bat` green. Walked in a real browser
signed in through the login form as a temporary staff account, at 1280,
390 and 320, with enquiries in every delivery state.

Check on localhost
- [x] The landlord email is written to the local mail capture folder, with
      the right recipient, subject and Reply-To (22 Sep 2026)
- [x] The email leaves out the renter's phone number and their message,
      and links to the message in the dashboard instead (22 Sep 2026)
- [x] A mistyped contact address on the home still delivers — to the
      account address — and the fall-back is logged (22 Sep 2026)
- [x] A forced failure is logged, badged for staff, and retried at +10
      minutes, then +60, then given up at three (22 Sep 2026)
- [x] A row that has given up refuses a fourth attempt, and no retry is
      left booked behind it (22 Sep 2026)
- [x] The catch-up sweep leaves a failure alone until **its own** backoff
      is up, and never touches a terminal row (22 Sep 2026)
- [x] Staff press **Send it again** and the screen says what happened; the
      renter is not emailed and their confirmation is untouched
      (22 Sep 2026)
- [x] A second press within 30 seconds sends nothing more (22 Sep 2026)
- [x] A landlord and a logged-out visitor are both refused the resend, and
      an id that does not exist is refused identically (22 Sep 2026)
- [x] Copies are off until switched on; switching them on with a blank or
      unusable address is refused rather than stored (22 Sep 2026)
- [x] Widths 1280, 390 and 320: no sideways scroll, the badge on its own
      line on a phone, the button full width with the hint beneath
      (22 Sep 2026)
- [x] The Resend button is reached by keyboard (tab 17) with a visible
      focus ring, and is 137×52 px (22 Sep 2026)

**Four defects the forced-failure probe found before any of it shipped**,
each now a check:

| What | Why it mattered |
|---|---|
| `send()` guarded only `sent`, not `given_up` | A given-up row accepted a fourth attempt, so the screen would say "4 attempts" under a promise of three |
| Each failure left its cron event behind | Retries stacked, and the last of them fired on a row that had already finished |
| The sweep used the **first** backoff for every attempt | All three tries would be spent inside the first ten minutes of an outage, when a mail server is least likely to have recovered |
| `array_filter` removed the body's blank lines with the optional ones | The email arrived as one unbroken block |

**One the phone walk found:** the badge and the renter's name were
competing for one row, so "Email failed — trying again" wrapped the name
onto two lines and cut the message to three words — on the single row that
most needed reading.

**Four more from the journey and corner-case walk, 23 Sep**, all fixed and
all now checked:

| What | Why it mattered |
|---|---|
| The send ran inline, between storing the enquiry and the renter's success screen | `Smtp::TIMEOUT` is 15 seconds, so a slow mail server left the renter on a page that had not moved. Booked through cron instead: measured 2.3 s end to end, and the landlord's email still arrived |
| The staff list drew a flat 20, no pager, no count | Anything past the twentieth was unreachable, so a delivery failure on it could never be seen — the badge's whole purpose |
| A staff copy that failed was silent | The landlord still got theirs, correctly, but copies could stop for a month with an empty mailbox as the only clue. Now `inquiry_copy_failed`, a warning |
| Resend froze for up to 15 seconds with no sign of life | Reads as a broken button, and the next thing anybody does is press it again. Now a disabled "Sending…" |

**And two bugs introduced by those fixes, caught before hand-over.**
Writing the "Sending…" label into the `onsubmit` attribute with
`wp_json_encode` closed the attribute on its own double quote and left the
script loose in the tag — broken markup that still looked right on screen.
And including queued rows in the sweep meant D4's `sms` rows, which start
life queued in the same table, would have been handed to `wp_mail()` with
a phone number in the To field; the sweep and `send()` are both restricted
to `channel = email` now.

Verified by hand as well as by suite: a real enquiry sent through the real
form as a logged-out renter returned its success screen in **2,314 ms**
and the landlord's email landed in the capture folder; **Send it again**
works with JavaScript switched off entirely (clicked with scripting
disabled, via the browser's own DOM coordinates).

**Still open.** The requests register's decision 5 says the admin copy
should be **on**, to the WordPress admin address until the business
mailbox exists. The reviewer chose **off by default** when asked. Built
off, with the admin address offered as the placeholder so one tick and
Save turns it on. Decision 5 should be amended or the default changed —
whichever is right, the two should not disagree.

Gate: [x] 100% OK (23 Sep 2026) · [x] committed · [x] pushed · [x] live checked (23 Sep 2026, per plan §10) · [x] report updated (24 Sep 2026)

### D4 · SMS

Built 23 Sep 2026 and committed `a75a1b4` on the reviewer's "100% OK";
then walked against pillars 1 and 2 the same day, which found seven more
(0.22.1 / 0.48.1, the table below). `verify-sms.php` **180 checks**; 18
suites, **1741 checks**, `verify.bat` green, and the staff log holds the
same rows after the run as before it. Walked in a real browser signed in
through the login form as a temporary landlord, at 1280 and 390, through
every state of the card: Not set up → Send code → Code sent (the code read
from the local capture folder, as a phone would show it) → a wrong code →
Verify → On → Turn texts off.

Check on localhost (test mode)
- [x] A landlord verifies a phone with a six-digit code (23 Sep 2026)
- [x] An unverified or opted-out number is never texted, and the log says
      why (23 Sep 2026)
- [~] A real text arrives on the approved test phone (screenshot kept) —
      28 Sep 2026: the verification code reached Rob's phone from
      +1 412 278 7837 and he confirmed his number (log: sms_verified). The
      inquiry text waits for his own home to be approved: it was Pending
      Review and his first inquiry went to a TEST home, so it texted nobody
- [x] SMS switched off: everything else still works, and the card says
      texts are not available yet while still saving the number
      (23 Sep 2026)
- [x] A landlord who never opted in, or turned texts off on the card, gets
      no row at all; unverified, opted out by STOP, unconfigured and over
      the hourly limit each write a `skipped` row naming the reason
      (23 Sep 2026)
- [x] The text names the home and links to the message — never the
      renter's name, phone or words — and is under 160 characters
      (23 Sep 2026)
- [x] A refused send is retried once at +10 minutes, then given up and
      shown to staff as "Text failed" under Delivery (23 Sep 2026)
- [x] Test mode: a number outside the list is skipped at send time, a
      number on it is sent, and wp-admin says test mode is on (23 Sep 2026)
- [x] STOP from the phone pauses, START resumes, anything else is ignored;
      an unsigned webhook is refused with 403; an undelivered status
      callback marks the row given up (23 Sep 2026)
- [x] A wrong code says so and keeps the field; five wrong tries lock the
      code; an expired code asks for a new one; a new code no sooner than
      60 seconds and at most 5 an hour (23 Sep 2026)
- [x] Changing the number — in the card or anywhere else — resets the
      verification; saving Details without a phone field leaves the number
      alone (23 Sep 2026)
- [x] A UK number, a short number and a blank are refused with the format
      shown; consent unticked is refused (23 Sep 2026)
- [x] Logged out is sent to the login page; a stale nonce says the page
      expired and nothing changes (23 Sep 2026)
- [x] Widths 1280 and 390: no sideways scroll, the pill under the title
      and every action full width on a phone, the code field and its
      button on one row on desktop (23 Sep 2026)
- [x] Buttons 141×52 px, one primary action per state, the code field
      marked `autocomplete="one-time-code"` so a phone offers the code
      (23 Sep 2026)

**Five layout faults the walk found, fixed before hand-over:** the
change-number input hogged its row and pushed its button underneath;
Verify sat a full row away from the field it verifies; the code-sent hint
repeated what the pill already said; the placeholder was cut short; and
on a phone "Turn texts off" stopped short of full width while every other
action filled it.

**Seven more from the journey and corner-case walk, 23 Sep**, all fixed
and each now a check:

| What | Why it mattered |
|---|---|
| The sweep retried a `failed` text after two minutes, not ten | The one retry was spent inside the first minutes of a gateway outage — the defect D3's sweep had before it |
| The home's name reached the text as HTML entities (`Landlord&#8217;s`), and a long or curly-quoted name made a two-segment text | `get_the_title()` is for web pages. `Inquiry::about()` returns text now — which fixes D3's email subject as well — and the body is trimmed with `...` and brought to keyboard punctuation, so it is always one segment |
| Verify pressed twice said "That code has expired" on a card already On | Reads as failure. The second press now says "confirmed" |
| A STOP handled by Twilio but never seen by our webhook would fail every later text (error 21610), for ever | Marked opted out and `skipped` now. And setting up the *same* number again while paused is refused with the reason — only START lifts the carrier's block — where the card used to say "set them up again below", which would not have worked |
| Send code, Use this, Change and Send a new code gave no sign of life while Twilio answered (up to 15 s) | D3's own lesson. They read "Sending…" and go quiet the moment they are pressed; Verify, which asks nobody, stays live |
| Twilio's queued/sending/sent reports each wrote a log line | Three lines per text in the SMS tab. Only delivered, undelivered and failed are logged now |
| The *Please sign in* return link doubled the site folder on a sub-directory install | `home_url()` plus the request path. A landlord following the text's link on localhost signed in and landed on a 404; live, at the domain root, was never affected |

Walked in the browser afterwards as a temporary landlord through the real
login form: the text's link opened signed out shows *Please sign in* with
the right return link, signing in lands on the message, and Send code
reads "Sending…" (disabled, opacity 0.55, cursor progress) before the
code-sent state arrives.

**Still open.** The real text to Rob's test phone is the last box and
waits for carrier approval of the A2P campaign (R45). Until then live
runs with `TDH_SMS_ENABLED` unset: the card says texts are not available
yet, the number can still be saved, and nothing is texted.

Gate: [x] 100% OK (23 Sep 2026) · [x] committed `a75a1b4` · [x] pushed (23 Sep 2026) · [x] live checked (23 Sep 2026: theme 0.48.1 on `/account/`, every page 200, no warnings; texting stays off on live until the carrier approves) · [x] report updated (24 Sep 2026)
Follow-up 0.22.1 / 0.48.1: [x] 100% OK (23 Sep 2026) · [x] committed `772bfd2` · [x] pushed and live (23 Sep 2026)

### R46 · Legal pages, for the carriers' campaign form (24 Sep 2026)

Twilio's A2P campaign form names what the Privacy and Terms pages must
say, and the live pages lacked it — a rejection costs the fee and
restarts the 1–3 week clock. Plugin 0.22.4. `verify.php` **63** (+16);
18 suites, **1780 checks**, `verify.bat` green, log rows unchanged.

Check on localhost
- [x] Privacy: after the owner's own sentence, the brand, what is kept
      and why, and the carriers' exact no-selling statement (24 Sep 2026)
- [x] Terms: titled **Terms of Service**, a real SMS terms section
      (brand, one text per inquiry, consent optional, frequency, rates,
      STOP, HELP, carrier liability, privacy link) above the
      still-to-come note (24 Sep 2026)
- [x] The sign-up form links to "Terms of Service" and its refusal says
      so (24 Sep 2026)
- [x] The opt-in proof image is served from the plugin's own assets
      (24 Sep 2026)
- [x] The importer re-seeded both pages without marking them edited;
      390 and 1280 clean (24 Sep 2026)

Gate: [x] 100% OK (24 Sep 2026) · [x] committed `bda7e7f` · [x] pushed (24 Sep 2026) · [x] live checked (24 Sep 2026: after the reviewer ran Pages and menus on live, both pages read over HTTP — every clause present, the owner's sentences intact, links to the live domain, the register page says Terms of Service, the proof image served) · [ ] report updated

### E1 · Membership lapse and restore

Check on localhost
- [x] Failed payment: homes stay visible during grace, dashboard says until when (24 Sep 2026)
- [x] After grace: homes hidden, nothing deleted (24 Sep 2026)
- [x] Payment fixed: exactly the hidden homes return; paused ones stay paused (24 Sep 2026)
- [x] While unpaid: no submit, no approval, and staff see why (24 Sep 2026)
- [x] R40: an account over its plan says so plainly (24 Sep 2026)
- [x] `verify-enforcement.php` 74 checks; `verify.bat` 19 suites, 1,855 checks green (24 Sep 2026)
- [x] Walked at 390 and 1280: past-due landlord, staff, visitor (24 Sep 2026)

Gate: [x] 100% OK (24 Sep 2026) · [x] committed `344261f` · [x] pushed (24 Sep 2026) · [x] live checked (24 Sep 2026: deploy green; theme 0.49.0 served; every landlord owning a home is Active, the two without a plan own none; Find a home lists 3 homes and each opens) · [x] report updated (24 Sep 2026)

### F1 · Email verification at sign-up (only after Rob's written OK)

Check on localhost
- [x] A new account gets a verification email; the link verifies it (24 Sep 2026)
- [x] Unverified accounts cannot pay or submit, and are told why (24 Sep 2026)
- [x] An expired link offers a new one (24 Sep 2026)
- [x] `verify-email-verification.php` 48 checks; `verify.bat` 20 suites, 1,903 checks green (24 Sep 2026)
- [x] Walked at 390 and 1280: real sign-up form, band, pricing, the link signed out in a second browser (24 Sep 2026)

Gate: [x] 100% OK (24 Sep 2026) · [x] committed `b92fd57` · [x] pushed (24 Sep 2026; the deploy's host-key step failed once and passed on rerun) · [x] live checked (24 Sep 2026: theme 0.50.0 served; a forged link goes to sign-in; live email reached a Gmail inbox, not spam) · [x] report updated (24 Sep 2026)

---

## 3. Milestone 2 acceptance (from the contract)

All must pass before the milestone is submitted. Evidence goes in the
column.

| | Criterion | Tasks | Passed | Evidence |
|---|---|---|---|---|
| 1 | Landlord creates a complete listing with images and amenities, previews it, submits it | A1 A2 A3 A5 | [x] self-QA | Suites: listing-form 153, photos 36, preview 24, listing-actions 130, availability 97 (24 Sep 2026). Walked by the reviewer in A1–A5 and Phase A. Client walkthrough still to do |
| 2 | Moderation state visible to landlord and administrator | A3 A4 | [x] self-QA | listing-actions 130, portal 86; the E1 walk showed In review, Hidden — payment and the queue's Membership inactive line (24 Sep 2026) |
| 3 | Approved property in search and on its page, correct at every width | A1 B2 C1 | [x] self-QA | Live 24 Sep 2026: all 3 homes in Find a home, each page opens with Close to care and the enquiry form; no street address or precise coordinates in the page or the REST output (ZIP is public by design). Widths walked per task at 390 and 1280; the full width pass is G1's |
| 4 | Search, all filters, sorting, list/map and empty states work together | C1–C4 | [x] self-QA | Live 24 Sep 2026: price range → "1 home matches your filters"; min 90000 → "No homes over $90,000"; pets → 1 home; Price low to high → $1,895, $2,450, $3,200; 1 Nov–15 Dec → "1 home free 1 Nov – 15 Dec 2026"; a 9-night stay → "Stays are 30 days or longer — try a later move-out date"; 15226 → "3 homes within 15 miles of 15226"; abcxyz → "No homes matching …"; /homes/page/9/ → back to page 1. Search suite 249 |
| 5 | Facility distances accurate for the agreed Pittsburgh test set | B1 B2 C3 | [x] self-QA | All 11 client hospitals geocoded from their addresses; the client's test addresses resolved (e.g. 1.3 mi to AHN Allegheny General, 20 Sep 2026). Live: Oakland Townhouse → UPMC Presbyterian 0.4 mi, Shadyside Retreat → UPMC Shadyside 0.3 mi. Straight-line miles. geocode 88, proximity 53 |
| 6 | Inquiry stored, in dashboard, email sent, SMS to the real test phone | D1–D4 | [~] | Stored, in the dashboard and emailed: inquiry 102, notifications 169, contact 92. **The real-phone text waits for the carriers** (A2P campaign filed 24 Sep 2026, in review) |
| 7 | Email and SMS failures logged, safe feedback, inquiry never lost | D3 D4 | [x] self-QA | notifications 169 and sms 180 force every failure: row kept, retried, given up, shown to staff in words, logged; the renter always sees their success screen |
| 8 | Membership lapse hides and renewal restores listings | E1 | [x] | enforcement 74; walked by the reviewer on localhost (grace, expired, restored) and live with nothing wrongly hidden (24 Sep 2026) |
| 9 | Elementor widgets show real data in editor and on the site | C5 | [x] self-QA | page-widgets 81; "Search results" and "Nearby hospitals" read live data in the editor and on the page (C5, live 23 Sep 2026) |
| 10 | No critical or high-severity defects | all | [ ] | None known after self-QA (24 Sep 2026). Open, not blocking a flow: R43 key rotation, the support@ mailbox (Hostinger order), R48 decision. Confirmed at the G1 walk |

## 3b. Live acceptance walk (reviewer, 26 Sep 2026)

Guide: `docs/M2-LIVE-CHECK.md`. Three windows on live: admin, a landlord (bsse1105), a signed-out renter. The three live homes belong to the admin account (user 2, instaquirk), so they are the reviewer's own and safe to enquire about; staff-owned homes are never held, so the payment steps use a TEST home owned by bsse1105.

- [x] 1 · Search: price range (2 match), Oakland (Pittsburgh, not CA), 15226 (3 within 15 mi, distances), map pills, 90000 → "No homes over $90,000" — found the card photo strip, fixed `a266257`
- [x] 2 · Property page: facts, fees, Everything you need, availability, Close to care circle + 3 hospitals, enquiry form, no street address (ZIP only) — found the calendar scrollbar, fixed `54d3cf7`
- [x] 3 · Enquiry: Message sent → admin inbox → Delivery "Emailed" → Gmail inbox (not spam), reply-to the renter; empty name refused with values kept
- [x] 5a · New home as bsse1105: non-US mobile refused with the rest saved; unfindable address warned and approval held; corrected to a Pittsburgh address, found; Fair Housing tick required; submitted, In Review
- [x] 5b · Approved from the admin queue ("Approved. The listing is live"); landlord sees Live, "Live since 26 Sep 2026" — found that a home still "Location not checked yet" could be approved without a location; Approve now looks it up first (plugin 0.24.3)
- [x] 4 · Pause ("is paused. Renters can't see it") and Resume ("is live again"); the admin list shows Paused
- [x] 6 · Payment failed: red band "Your 1 home stays visible until 3 Oct 2026. Update your card", home still public in grace ✓ · Expired: amber band "Your 1 home is hidden until a plan is active. Nothing is deleted" with Restart a plan, row "Hidden — Payment · the plan has ended", renter gets "This home isn't available right now", search down to 3 homes ✓ · Active again: "Payment received — your 1 home is back online", Live again, renter page opens, search back to 4 homes ✓ (plan expiry then set to 31 Dec 2027: "Renews 31 Dec 2027", bsse1105 ends Active)
- [x] 7 · New sign-up (mdsweem4+test1@gmail.com): welcome line names the address, yellow "Confirm your email address" band with Send a new link, "No active plan" band; the email arrived in Gmail (inbox); the link opened in the admin's browser said "That email address is confirmed. Sign in as its owner to continue." — the wording fixed in G1
- [x] 8 · Phone view: public pages checked on live at 320 and 390 by script (home, search, property, register, pricing) — no overflow; the reviewer's admin overview at 320 rendered zoomed-out, most likely Hostinger's admin-only "Agent" widget (not on localhost, not seen by renters or landlords); then the reviewer walked the home page and Find a home at 320 on live — hero search stacked, audience cards, homes, owner panel, footer, filter drawer, results, all tidy; the drawer's two dates clipped to "mm/dd/yyy" at 320, fixed (each date full width under 24rem, theme 0.50.7)
- [x] Clean-up: TEST home deleted ("is deleted", empty state "No homes listed yet") ✓; test sign-up account deleted ("Member deleted") ✓ — it found the Members delete used a browser pop-up, now an in-page question (0.24.4); the test enquiry stays in the staff inbox (staff have no Archive; the landlord's own inbox does); bsse1105 Active to 31 Dec 2027 ✓. Listing cards (99592cb) live and seen, including the tinted delete confirmation
## 4. Payment gate

- [x] Developer self-QA and integration testing complete (24 Sep 2026: §3 evidence; 26 Sep 2026: end-to-end journey across landlord, staff and renter, 28/28, and the Pittsburgh place fix R50; 20 suites / 1,908 checks)
- [x] Real-phone SMS evidence supplied — code text on Rob's phone 28 Sep 2026; the whole flow proven on live with a US number on 30 Sep 2026 (code, verify, inquiry text, link opens the inquiry; Twilio shows Delivered); the inquiry text for Shady Fun in the Sun on Rob's own phone, his screenshot, 2 Oct 2026
- [x] Team lead's review of 4 Oct 2026, functional items, all live by 5 Oct 2026 (plugin 0.24.22 / theme 0.57.1): R56 "Within" unlocks at once · R57 phone fixes (administrator tab bar, price before the inquiry form) · R58 Google address suggestions (Rob enabled Places API (New) on 4 Oct; checked with real answers) · R59 results refresh in place, inquiry lands on its confirmation · R60 photo picker keeps every choice, 20 MB a photo · R61 add-listing inside the dashboard · R62 Cloudflare Turnstile on the five public forms (keys in live wp-config; box seen on all five live pages by script; a real sign-in through it by the reviewer, 5 Oct)
- [ ] Team lead's review of 4 Oct 2026, design items — versions first, then build: **H1 Version B approved on localhost and deployed by green workflow #115; live cache purge / final public check remains** · H2 (whole card clickable, Version B) built locally, rejected by the reviewer 5 Oct 2026 and reverted to the live card · **H3 gallery: Version B selected and built locally; desktop / phone layout, photo-count boundaries, captions, Previous / Next, keyboard, Escape, exact focus return, no-script and the one-photo state passed; `verify-photos.php` 42/0 and full `verify.bat` green; reviewer approved it 6 Oct 2026 and it is included in the acceptance commit, awaiting user push/live check** · map, empty states, sign-in or sign-up first still to come
- [ ] Wordfence on live (with Rob's go-ahead) · photo limit 10 or 20 MB (Rob to answer) · the unused "Maps Platform API Key" (35 APIs) in Rob's Google project restricted or deleted · key-rotation list for hand-over (Twilio token included)
- [x] Frontend design review done; agreed corrections applied — part 1 (24 Sep 2026): every screen at six widths, no overflow or PHP output; tap targets and field labels fixed; part 2 (26 Sep 2026): keyboard pass on 16 screens and 125 % zoom, the home search's focus ring fixed; part 3 (29 Sep 2026, G2 batch 1 of 4, live `d5598ab`): the master-design-prompt audit's failed MUSTs cleared site-wide — no text under 12 px, gold text at 5.8:1, two-tone text, lining numerals, navy-tinted shadows, one filled primary per view, header labels and current-page mark, hero button always usable, pills at 12 px in sentence case, no invented ratings (plugin 0.24.7, theme 0.51.0); batch 2a (29 Sep 2026, G3a, live `e978bc3`): the renter's pages — one search form with one button and Sort by beside the count, price-first cards with an availability pill, the property page's facts as a line, Included / House rules, the inquiry form in the main column with a pinned price card and Ask the owner (a bottom bar on phones), the hospital heading with the real distance, the home page's audience cards 2 × 2 on phones (plugin 0.24.8, theme 0.52.0); batch 2b (29 Sep 2026, G3b, live `13ff174` + `e1cafef`): pricing buttons that say what they start and cost, one gold door per page on About and How it works, owner FAQ, the contact form first on phones, Landlord sign in with a renter's way out, Show / Hide passwords, legal pages with a compact header, links and a Last-updated line, every editor's note staff-only (plugin 0.24.9, theme 0.53.0); batch 3a (29 Sep 2026, G4a, live `51693db`): the landlord portal — a labelled tab bar on phones, figures that read, My listings with status chips and per-home views and inquiries, a More menu for Pause and Delete on phones, inquiries tabs with counts, a membership page about the plan and its price, a one-column Account details with Show/Hide and a Send code that says what it waits for (plugin 0.24.10, theme 0.54.0); batch 3b (29 Sep 2026, G4b, live `3c6d1a1`): the listing form — a named four-step stepper, one column with "$", "/ month" and "sq ft" inside the fields and helpers under them, a one-line live-home note, a running amenity total, Remove photo in red and a live description counter, a review that opens with the home's state and a Worth fixing card (plugin 0.24.11, theme 0.55.1); batch 4a (30 Sep 2026, G5a, live `644f51d`): the team's portal — one primary per screen with Add member and Add facility as header buttons, a **Pending approval** overview tile that shows the number once and has a separate **Review now** button when work is waiting (`a225203` follow-up), Approve on the queue row, Listings with counted chips, a Needs location banner and Edit / View on every row, Members with search and status chips, plan names in words and an Over limit pill, Facilities saying where each is shown, Inquiries with a New pill and a time, Listing setup as one list with a 640px hospitals form (plugin 0.24.12, theme 0.56.0); batch 4b (30 Sep 2026, G5b, live `037f6aa`): wp-admin — the Elementor notice gone, Logs with six grouped tabs (a select on phones) that open on the week's warnings with the line itself opening its context and times in Eastern Time, a Listings table with Status, Landlord, Rent, Map point and one Updated date, staff without manage_options sent to the portal (plugin 0.24.13); the last design batch — part 3 is complete and live (30 Sep 2026)
- [ ] Client walkthrough as renter, landlord and administrator
- [ ] Final `Milestone-2-Report.docx` sent
- [ ] Written acceptance recorded
- [ ] Milestone 2 payment received
