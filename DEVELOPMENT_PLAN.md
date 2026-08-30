# ThirtyDayHomes — Technical Development Plan

**Companion to the *WordPress Development Handoff and Milestone Acceptance Plan*.**

That document governs scope, acceptance criteria, payment gates, QA severity, change control and handover. **Where the two differ, the handoff document wins.** This one exists to answer the questions it deliberately leaves to the developer: what the prototype actually delivers, how the theme/plugin split is drawn, what the data model and widget surface look like, how the SMS pipeline is designed, and where the schedule is under pressure.

Source documents: `WorkStay_MVP_Website_Spec 7272026`, `WorkStay_Delivery_Plan_Instaquirk`, the 8/16 website comments round, the WordPress Handoff & Milestone Acceptance Plan, and the approved prototype at `thirtydayhomes-qi1e.vercel.app`.

---

## 0. Two decisions that changed since the last revision

Both come from the handoff document. Both are material, and the second is the biggest schedule risk on the project.

### 0.1 No third-party page-builder add-ons — custom theme + custom plugin

The handoff document specifies *"Custom WordPress theme + custom plugin + Elementor"* and *"Configure Elementor and global design settings without unnecessary add-ons."* Business logic lives in the plugin; presentation lives in the theme and Elementor; and *"no essential functionality may depend on an undocumented account, inaccessible license, developer-owned API credential, or manual database intervention."*

That rules out the JetEngine / JetSmartFilters / JetFormBuilder stack I previously recommended. **This is the right call for this project**, and it fixes a problem I had flagged: recurring licences now drop to Elementor Pro alone at roughly $59/year, which lands *under* the delivery plan's stated "software licenses: under $100 per year" instead of overshooting it. It also removes a third-party vendor from the critical path of a marketplace the client owns outright.

The cost is build effort. We are hand-building the custom post types, field storage, front-end submission, AJAX filtering, query logic and Elementor widgets that Crocoblock would have supplied as configuration. That is roughly the difference between assembling and manufacturing — better outcome, more days.

### 0.2 SMS to landlords is now in scope — with A2P registration

The delivery plan put this to the client as an explicit either/or, and recommended **Option A** — a button that opens the *renter's own* phone, no ongoing cost, no approval queue. It warned that **Option B**, where the website itself sends the text, *"puts your launch date in a phone carrier's approval queue that neither of us can influence."*

The handoff document requires Option B: a third-party gateway, verified sender, **US A2P 10DLC carrier registration**, consent and opt-out handling, delivery logging, rate limiting and test-mode support — and it correctly notes that carrier approval is a third-party dependency to be *"raised immediately if it threatens the schedule."*

Two consequences, stated plainly:

1. **A2P brand and campaign registration must be submitted in week 1**, alongside the Stripe account, not when the SMS code is written in Milestone 2. Brand registration is usually quick; campaign vetting is the unpredictable part and can run one to three weeks. Submitting it in week 1 means the queue runs in parallel with Milestones 1 and 2 rather than blocking launch.
2. **It needs the client's EIN / tax ID and business identity details at kickoff.** Without them the registration cannot even be submitted. This now joins the blocker list in §7.

The two texting features are separate and both survive — do not conflate them:

| Feature | Who sends | Cost | Status |
|---|---|---|---|
| Renter taps "Text landlord" on a listing | The renter's own phone, via an `sms:` link after number reveal | None | Already in the prototype, keep it |
| System notifies landlord of a new inquiry | Our gateway, to the landlord's verified number | Per message + A2P | New in scope, Milestone 2 |

---

## 1. Where we actually are

The client confirmed the flow visualization. That is a real milestone — every screen, every state and most of the copy is agreed, which is exactly what the spec's "Discovery & design" milestone was meant to produce.

**The React prototype is a design specification, not a codebase.** It is one 43 KB `src/main.jsx` with no router, no persistence, no auth and no data layer. On WordPress none of it ports as code. Its value is that every screen it contains is signed off, which removes the largest source of mid-build churn. Treat it as the approved wireframe set.

**Milestone 1 is already past its ClickUp due date.** The day counts below still hold — but they count from kickoff.

### What only appears to work

These read as functional to a reviewer but have no logic behind them. Listed because each is now a build task, and because they are what the committed day counts still have to cover.

| Appears to work | What the code actually does |
|---|---|
| Homepage search | The "Where" input is uncontrolled and its value is discarded; start and end dates live in local state and are thrown away on submit |
| Date availability filter | `filters.start` / `filters.end` are captured on the results page but never applied to the result set |
| Search by hospital | `filters.facility` exists in state and in the filter chain, but **no control anywhere sets it** |
| Sort by closest facility | Sorts on a static per-listing number measured to that listing's own hardcoded hospital — not to a facility the renter chose |
| Nearby facilities on a listing | One hardcoded facility, not "the three nearest within 15 miles" |
| Photo gallery | Three images, two of them identical across every listing |
| Listing amenities | The detail page renders a hardcoded list and ignores the listing's own amenities |
| Listing wizard, step 1 | Street address, ZIP, property type and deposit inputs are uncontrolled — that data is lost on submit |
| Photo upload | No file input exists; the drop zone and "Choose photos" button are inert |
| Minimum stay selection | Four option buttons with no selected state and no binding |
| Edit a listing | Routes to a blank creation wizard with no id and no prefill |
| Members table (admin) | A hardcoded four-row array, not derived from any data |
| Site content editor (admin) | The landlord profile form reused with different labels; saves nothing |
| Facilities | Plain strings — no coordinates, no city or state |
| A landlord's own listings | Filtered by `owner === 'Maya Thompson'` regardless of which persona is active |

---

## 2. Architecture — where the line is drawn

The handoff document's separation of concerns, made concrete. The test for every decision: **if the theme were deleted and replaced tomorrow, would any listing, membership, inquiry, facility or relationship be lost?** If yes, it is in the wrong place.

### 2.1 `thirtydayhomes-core` — the plugin

Owns everything that is data or behaviour.

| Module | Responsibility |
|---|---|
| **Content types** | `tdh_listing`, `tdh_facility`, `tdh_inquiry` — registration, labels, capabilities, rewrite rules |
| **Statuses** | Custom post statuses (§3.2), their transitions, and the rules governing them |
| **Fields** | All meta registration with explicit `show_in_rest` control; sanitisation and validation callbacks on every field |
| **Tables** | `wp_tdh_distances`, `wp_tdh_notifications` — creation, upgrade routines, indexes |
| **Roles & caps** | `tdh_landlord` role, capability map, and record-ownership enforcement on every read and write |
| **Membership** | Subscription layer integration, status mapping, grace period, listing allowance, publish gating |
| **Submission** | Front-end listing create/edit handling — nonce, capability, validation, media, status assignment |
| **Query** | Search, filter and sort logic; the public-visibility filter; AJAX endpoints |
| **Proximity** | Geocoding, distance matrix, facility-relative sorting, drive-time fetch and cache |
| **Notifications** | Inquiry storage, queued email and SMS dispatch, retry, delivery logging |
| **Elementor widgets** | All dynamic widgets registered here (§4), so they survive a theme change |
| **Admin** | Approval queue, facility manager, inquiry overview, notification log |
| **Lifecycle** | Activation, deactivation safety, version upgrade routines, documented hooks and filters |

### 2.2 `thirtydayhomes` — the theme

Owns everything that is presentation. No marketplace rules.

- Global design tokens as CSS custom properties — colour, typography, spacing, radius, shadow, breakpoints — lifted from the approved prototype's navy and gold system.
- Header, footer, primary navigation, account navigation, responsive layout framework.
- Elementor theme-location support and page templates.
- Templates for search results, single property, landlord dashboard, account screens, and content pages.
- Accessible HTML structure, focus styles, and the full state set: loading, empty, validation, error, success, disabled, pending, payment-failure.

### 2.3 Elementor

The editing layer. Administrators change headings, supporting copy, calls to action, imagery, item counts, query selection and approved style variants. They never touch prices, ownership, membership status, distance values or inquiry content — those come from the plugin as dynamic values and are never duplicated by hand.

### 2.4 Subscription layer — DECIDED

The handoff document left this open: *"Stripe/WooCommerce subscription integration as selected during setup."*

**Decision (2026-08-30): WooCommerce core plus a renewal layer inside `thirtydayhomes-core`. No premium extension.**

Two client constraints drove it, and they are not fully compatible on their own:

1. Everything in one plugin we own, nothing bought in.
2. WooCommerce, because the client wants that admin.

WooCommerce core is free but sells one-off products — it has no renewal engine. The extension that adds one, **WooCommerce Subscriptions, is premium at roughly $200–280/year**, which the budget rules out. So the split is:

| Layer | Owner | Cost |
|---|---|---|
| Products, cart, checkout, orders, refunds | WooCommerce core | Free |
| Card capture and tokenised saved cards | WooCommerce Stripe Gateway (official) | Free |
| Renewal schedule, dunning, grace periods, plan changes | **`thirtydayhomes-core`** | Free |

Annual licence cost across the whole project stays **£0**.

**What we are taking on by not buying the extension.** Recurring billing is the highest-risk work in this project — higher than listings or search, because a bug costs the client money rather than looking wrong. Specifically ours to get right:

- Charging the saved card on schedule, with idempotency so a retried request cannot double-charge.
- Retry and dunning: a declined card moves the member's listings to `tdh_billing_hold`, not `tdh_paused`, so a successful retry restores exactly what billing took away and nothing the owner paused themselves. This distinction already exists in `TDH\Statuses` for this reason.
- Webhook handling that is replay-safe and signature-verified.
- Upgrades and downgrades mid-cycle, and what happens to listings over quota on a downgrade.

**Rejected alternatives, recorded so this is not reopened:**

- *Paid Memberships Pro* — free and would have solved the renewal engine, but it is a second plugin the client does not want.
- *Buying WooCommerce Subscriptions* — lowest risk, but an ongoing licence the client declined.
- *Stripe Billing direct, no WooCommerce* — fewest moving parts for a site selling one thing, but the client wants the WooCommerce admin.

Migrating billing after real members exist is genuinely painful, so this is fixed unless the client changes the budget.

---

## 3. Data model

Documented before implementation, per handoff §3.3.

### 3.1 `tdh_listing`

| Group | Fields |
|---|---|
| Identity | title, slug, owner (post author) |
| Location | `street_address` **(private)**, neighborhood, city, state, zip, `lat`, `lng`, `geocode_status` |
| Pricing | monthly rent, security deposit, application fee, pet fee |
| Property | bedrooms, bathrooms, property type, furnished status, square feet, total rooms, backyard type, parking |
| Terms | minimum stay, lease term, availability date, blackout ranges, utilities included, pet policy |
| Content | description, amenities, photo gallery (ordered, with cover) |
| Compliance | Fair Housing acknowledgment timestamp and accepted text version |
| Contact | contact name, email, SMS-capable phone, preferred contact method |
| Moderation | rejection reason, last approved revision, submitted-at, approved-at, approved-by |

**Taxonomies:** `property_type`, `neighborhood`, `amenity`, `city` — taxonomies rather than meta, because they give indexed filtering and clean archive URLs without extra work.

### 3.2 Listing statuses

| Status | Meaning | Public |
|---|---|---|
| `draft` | Landlord still writing | No |
| `pending` | Submitted, awaiting approval | No |
| `publish` | Approved and live | Yes |
| `tdh_paused` | Landlord paused deliberately | No |
| `tdh_rejected` | Rejected with reason, awaiting revision | No |
| `tdh_billing_hold` | Hidden by the system on membership lapse | No |

**`tdh_billing_hold` is deliberately separate from `tdh_paused`.** To a renter they look identical; on renewal they must behave completely differently. We restore only `tdh_billing_hold` listings, leaving deliberate pauses alone. Without the distinction, a landlord who paused one of their three homes last week gets it silently republished when their card is fixed.

Because these are real post statuses, the standard admin list, its status filters and bulk actions work on them with no extra UI.

### 3.3 `tdh_facility`

`name`, `type` (hospital / clinic / rehab), `street_address`, `city`, `state`, `zip`, `lat`, `lng`, `active`, `sort_order`, display metadata.

City and state present from day one, so Pittsburgh is seed data rather than an assumption baked into templates. The admin adds a facility like any other post and it appears in renter search immediately — which is the whole of the spec's "additional facilities and cities later without rebuilding the website."

### 3.4 `tdh_inquiry`

listing, renter name, email, phone, move-in date, stay length, message, consent/acknowledgment version, created-at, landlord read/unread, admin-visible flag, status (new / opened / archived).

Stored as well as sent — the spec only asks for email, but an emailed lead that bounces is a lost lead, and the admin dashboard needs the recent-inquiry total.

### 3.5 Custom tables

**`wp_tdh_distances`** — `listing_id`, `facility_id`, `miles`, `drive_minutes`, `computed_at`. A real table rather than post meta: with fifteen facilities and a few hundred listings this is thousands of rows sorted on every search, and meta would be slow enough to notice.

**`wp_tdh_notifications`** — `id`, `inquiry_id`, `channel` (email/sms), `recipient`, `provider_message_id`, `status` (queued/sent/delivered/failed/opted_out), `provider_response`, `attempts`, `created_at`, `updated_at`. Required by handoff §5 and the M3 admin deliverable: *"email/SMS delivery status visibility appropriate for support."* Provider responses stored without credentials.

### 3.6 The visibility rule

A listing is public when **its status is `publish` AND its author holds an active membership** (or is inside the seven-day grace window). Enforced once, in a `pre_get_posts` filter in the plugin that every listing query passes through. Never reimplemented in a template. The prototype's client-side `status === 'live'` check is exactly what this replaces.

### 3.7 Membership state machine

| Event | Effect |
|---|---|
| Checkout completed | Level assigned, allowance set, listings publishable |
| Payment succeeded | Active; clear grace timestamp; restore `tdh_billing_hold` listings |
| Payment failed | Grace window opens — seven days — landlord warned, **listings stay public** |
| Subscription canceled | Canceled at period end, then listings move to `tdh_billing_hold` |
| Nightly cron | Grace expired → `tdh_billing_hold`. Data never deleted. |

Seven days per delivery plan §2 item 9. The prototype hardcodes "5 more days" — a bug, not a decision.

### 3.8 Address privacy — a WordPress-specific hazard

The street address must never be public. This is a safety issue for a furnished rental that sits empty, and on WordPress the *default* behaviour leaks it. Three deliberate steps, and the third is a release blocker:

1. Register `street_address` with `show_in_rest => false` so it never appears at `/wp-json/wp/v2/tdh_listing`.
2. Never place the field on the single-listing template, not even hidden — Elementor renders hidden elements into the DOM.
3. An explicit REST-endpoint and page-source check in the M3 QA pass.

---

## 4. Elementor widget surface

Registered by the plugin so they survive a theme change. Controls expose presentation only; dynamic values always come from the plugin.

| Widget | Editable controls | Plugin-supplied data |
|---|---|---|
| Hero property search | Heading, sub-copy, background, placeholder text, which fields show | Facility list, neighborhood list |
| Featured / recent properties | Heading, item count, source (recent / featured / by neighborhood), layout, card variant | Listings, respecting the visibility rule |
| Property card | Card variant, which facts to show, badge visibility | Price, beds, baths, distance, photos, status |
| Search results | Layout, default sort, filters shown, results-per-page | Query results, counts, filter options |
| Facility proximity block | Heading, number shown, distance format | Nearest facilities, miles, drive time |
| Membership pricing | Heading, sub-copy, which plans, feature list, highlight variant | Plan names, prices, allowances from the subscription layer |
| Inquiry form | Heading, sub-copy, which optional fields, success message | Listing binding, validation, spam protection, dispatch |
| CTA / FAQ / marketing blocks | Full content control | — |

Every widget must render correctly **inside the Elementor editor as well as on the frontend**, avoid editor-only CSS, and stay responsive after content changes. Tested on staging per handoff §4.

---

## 5. Inquiry, email and SMS pipeline

The highest-risk feature in Milestone 2, and the one the handoff document specifies in most detail. Design it once, correctly.

### 5.1 Store first, dispatch second

*"A notification failure must not silently discard a successfully stored inquiry."* So the order is fixed:

1. Validate → spam checks (honeypot, timing, Turnstile, per-IP rate limit) → **write the `tdh_inquiry` record** → show the renter success.
2. Queue email and SMS as **background jobs** (Action Scheduler), each writing a `wp_tdh_notifications` row.
3. Retry failures with backoff; mark terminal failures.
4. Surface failures in the admin inquiry overview so support can see them.

The renter's success state depends only on step 1. A gateway outage never costs a lead.

### 5.2 SMS design

**Gateway:** Twilio recommended — the most mature A2P 10DLC path, best documented status webhooks, straightforward test credentials. Telnyx or Plivo are viable alternatives at slightly lower per-message cost.

**Consent.** The recipient is the landlord — our paying customer, not a marketing target. Capture consent at registration and in profile ("Text me when I receive an inquiry"), then **verify the number with a one-time code** before it is ever used. An unverified number is never texted.

**Message content — minimise PII, per handoff §9.** The text is a notification, not the message itself:

> `New ThirtyDayHomes inquiry for {listing_title}. View it: {short_dashboard_link} — Reply STOP to opt out.`

No renter name, phone, email or message body in the SMS. The landlord opens the dashboard. This reduces carrier filtering risk, keeps personal data out of carrier logs, and is cheaper per segment.

**Opt-out.** STOP / HELP handled at the gateway *and* mirrored back into the landlord's profile via webhook, so the site stops attempting sends and the dashboard shows SMS as disabled with a way to re-enable.

**Operational requirements**, all per handoff §5: environment-based credentials (never in the repo, theme or Elementor), verified sender number, E.164 phone normalisation and validation, delivery-status webhooks into the notification log, per-recipient and per-hour rate limiting, and a test mode restricted to approved test recipients.

**What this is not.** The handoff document is explicit: SMS notifies the landlord that an inquiry exists. Two-way conversational sync is not in scope and is not implied.

### 5.3 Email

WP Mail SMTP with a dedicated transactional provider — Resend or Postmark — authenticated on the owner's domain with SPF, DKIM and DMARC. Configured in Milestone 1, not Milestone 3. Email from a new domain lands in spam often enough that a landlord who pays and never receives inquiries will cancel, and it will read as the product failing when it is a DNS problem.

---

## 6. Prototype finetune

The client asked for this specifically: *"This is a prototype. That means you have to finetune the small changes. You have to have a clear idea about UX as well."* These belong to Milestone 1's design work, because they define the theme's global styles and every template built from them. They are also what the frontend designer will be reviewing against at each milestone gate.

### 6.1 Fix before anything else — these mislead the client or the renter

| # | Issue | Decision |
|---|---|---|
| 1 | **Every property card shows `★ 4.9`** | **Remove.** There is no review system in V1 and reviews are not in scope. A hardcoded rating on every home is the single most misleading element in the prototype — it promises social proof that will never exist and would be a trust problem on a live site. |
| 2 | **"Guest favorite" / "Top location" badges** | **Replace.** That is Airbnb's booking-marketplace vocabulary. This is a directory — the owner never sees booking volume, so no badge can be earned. Use derivable facts: "New this week", "Available now", "Pets welcome". |
| 3 | **`tag` doubles as marketing badge and status label** | **Split.** One card slot currently renders both "Guest favorite" and "Pending". Renter-facing badge and owner-facing status are different audiences on different screens. |
| 4 | **Three names in use** — "ThirtyDayHomes", "30 Day Homes", "WorkStay" | **Blocker.** See §8. Settle before theme tokens are frozen. |
| 5 | **Two contradictory pricing models** | **Blocker.** See §8. Determines how many membership levels exist. |
| 6 | **"5 more days" grace period** | Should be seven, and derived from the stored grace timestamp rather than hardcoded. |
| 7 | **"How it works" and "Renter FAQ" are one page under two names** | One page, one name. Recommend **"How it works"** in the primary nav with the FAQ as a section within it — renters hunting answers scan for FAQ, but first-time visitors need the explanation first. |

### 6.2 Icon decisions

**Implementation note:** the prototype uses Lucide icons, which Elementor does not bundle. Upload the specific Lucide SVGs to Elementor's Custom Icon Library rather than substituting Font Awesome equivalents. It preserves the approved design exactly, costs about an hour, and avoids a round of "this doesn't look like what we signed off" at the design review gate.

| Where | Currently | Change to | Reasoning |
|---|---|---|---|
| Pause / resume listing | `XCircle` | `Pause` / `Play`, toggling | `XCircle` reads as *delete* or *error*, and it sits directly beside an actual delete button. An owner will eventually click the wrong one. The clearest usability defect in the dashboard. |
| Total rooms | `Home` | `LayoutGrid` or `DoorOpen` | `Home` is the brand's own primary icon and already appears in navigation; reusing it as a stat dilutes it and reads as "this is a home" rather than "this many rooms". |
| Map placeholder, detail page | `Map` | `MapPinned` | Distinguishes "here is a location" from "switch to map view". One icon doing two jobs on the same site is a small cost with no upside. |
| Dashboard notification bell | `Bell` | **Remove for V1** | Decorative and non-functional. A bell that never rings teaches the landlord to ignore the header. Notifications are not in V1 scope. |
| Map / list view toggle | `Map` / `List` | Keep | Correct and conventional as a pair. |
| Saved homes | `Heart` | Keep | Universally understood. Do not switch to a bookmark. |
| Nearby hospitals | `Stethoscope` | Keep | Reads as *care* rather than *building* — the right emphasis for the differentiator. |
| Square footage | `Ruler` | Keep | Unambiguous. |

### 6.3 Search and results — the differentiator is not currently usable

| Item | Work |
|---|---|
| **Add a facility selector to the filter bar** | Proximity is the reason this product exists and there is currently no way for a renter to choose a hospital. Highest-value single addition on the results page. |
| **Wire the homepage search through to results** | Control the "Where" input; carry the query and both dates through as URL parameters — deep-linkable, shareable, back-button-safe and indexable. |
| **Apply the date filter** | Evaluate availability date and blackout ranges against the requested stay. |
| **Add the missing filters** | Bathrooms, price min/max, and pet policy as three states (allowed / considered / not allowed) rather than the current on-off toggle. All named in spec §D. |
| **Sort against the *selected* facility** | Not against each listing's own hardcoded nearest hospital. |
| **Filter chips, result count, clear-all, mobile filter drawer** | Standard on every rental site; their absence is felt immediately on a phone. |
| **Empty state that names the constraint** | "No 2-bedroom homes under $2,000 near UPMC Presbyterian", with the offending filter offered for removal, beats "No homes match those filters". |

### 6.4 Listing detail

- Real per-listing galleries with a lightbox, replacing the three shared stock images.
- Render the listing's own amenities, grouped, instead of the hardcoded list.
- Three nearest facilities with distance *and* drive time. (Handoff §Milestone 2: a change to five is a presentation configuration only if it does not change the agreed API cost — with our caching it does not, so build the count as a setting.)
- Fee breakdown from listing data rather than defaults.
- Availability calendar driven by the blackout ranges.
- Keep the approximate map area, and say so on the page, so renters read it as deliberate rather than missing.

### 6.5 Accessibility and shell

Part of the acceptance gate, not a Milestone 3 afterthought — and Elementor's defaults do not give it to us free:

- Every icon-only button needs an accessible name. Roughly two dozen currently have none.
- Modals and the mobile filter drawer need focus trapping, `Escape` to close, and focus return.
- Every image needs meaningful `alt`. Enforce it on the landlord upload form so listing photos get alt text at source.
- Visible focus rings, keyboard-navigable filter bar and submission wizard.
- Honour `prefers-reduced-motion` — Elementor animations ignore it by default and need a CSS override.
- `brand-mark.png` is **603 KB** for a mark rendering at ~40 px. Convert to SVG with a compressed fallback plus favicon variants, under 30 KB total. It is currently the heaviest asset on the homepage.

---

## 7. Milestone plan

Day counts and contract allocations are exactly those in the handoff document and the delivery plan: 42 working days, 13 / 18 / 11, at 30% / 45% / 25%. The breakdown below is how those days get spent given §0's two changes.

### Milestone 1 — Foundation · 13 days · 30%

| Days | Work |
|---|---|
| 2.0 | Discovery: confirm scope, roles, listing fields, amenities, membership rules, privacy decisions. Document the three user journeys. **Document the final data model.** Define the Elementor-editable / plugin-controlled boundary. |
| 1.5 | Environment: local, staging and production workflow, version control, backups, security baseline, deployment process. |
| 2.5 | Custom theme foundation: design tokens from the approved prototype, header, footer, navigation, account navigation, responsive framework, Elementor theme locations, and the full state set. |
| 2.0 | Plugin skeleton: content types, statuses, meta registration with REST control, roles and capabilities, activation and upgrade routines, hooks scaffold. |
| 3.0 | Accounts and membership: registration, login, logout, password reset, profile; Stripe subscriptions in test mode; plans and listing allowance; active / failed / past-due / canceled / expired states; dashboard shell on real data; cross-landlord permission enforcement. |
| 1.0 | Public page structures — Home, Search, Property, About, How It Works/FAQ, Pricing, Contact, Terms, Privacy. |
| 1.0 | Delivery infrastructure: transactional email authenticated on staging, **SMS gateway account opened and A2P 10DLC brand and campaign registration submitted.** |

**Gate:** developer self-QA → frontend design review and corrections → client walkthrough against the acceptance checklist → written acceptance → invoice.

**Client input needed at kickoff:** blockers §8 items 1–4, plus **EIN / tax ID and business identity for A2P registration**. Stripe account created week 1. Legal text requested from their attorney week 1.

---

### Milestone 2 — Core Marketplace Build · 18 days · 45%

| Days | Work |
|---|---|
| 3.5 | Listing management: front-end create, save draft, edit, preview, submit, pause, delete; all six statuses; every field, categorised amenities, fees, availability, contact preferences, Fair Housing acknowledgment; the instant-publish versus return-to-moderation edit classification. |
| 1.5 | Media: multi-image upload, type and size validation, ordering, cover selection, deletion, compression, responsive output, per-landlord isolation. |
| 3.5 | Public marketplace: search and results with every filter and sort, list and map presentation, empty and error states, and the Elementor widgets in §4. |
| 2.0 | Public property page: gallery, key facts, description, amenities, approximate location, nearby facilities, fees, availability, inquiry actions, address privacy enforced. |
| 3.0 | Proximity: facility admin CRUD with coordinates and validation, address geocoding with failure flagging, distance calculation and storage, facility-relative sort and filter, nearest-facilities display. |
| 3.5 | **Inquiry pipeline (§5):** form, validation, spam protection, storage, landlord dashboard with unread/read, admin visibility, queued email, **queued SMS with consent, verification, opt-out, delivery logging, rate limiting and test mode**, and every failure state surfaced safely. |
| 1.0 | Membership enforcement: grace-period hide, data preserved intact, correct restoration on renewal. |

**Gate:** self-QA and integration testing → **real-phone SMS test evidence** → frontend design review and corrections → client walkthrough using renter, landlord and admin accounts → written acceptance → invoice.

**Client input needed:** facility list and test addresses week 3; **approved SMS message template**; approved test recipients; site copy review weeks 4–5; staging walkthrough week 6.

---

### Milestone 3 — Administration, Launch & Handover · 11 days · 25%

| Days | Work |
|---|---|
| 3.0 | Administration: approval queue with preview, approve, reject-with-reason, remove and audit info; member overview with membership status and listing counts; facility management; inquiry overview with email and SMS delivery status; dashboard totals; Elementor editing roles and capabilities. |
| 2.0 | Production readiness: SEO titles and descriptions, clean URLs, sitemap, robots, indexable listings; analytics under client-owned access; performance and caching; database cleanup strategy. |
| 2.5 | Testing: accessibility, browser, device, security and regression; backup and restore test; uptime monitoring; error logging; **REST-API address-leak check as a release blocker.** |
| 1.5 | Go-live: Stripe to live configuration, production email and SMS credentials verified, domain deployment, HTTPS verification. |
| 2.0 | Documentation and handover: administrator guide, developer documentation (architecture, data model, hooks, dependencies, deployment, scheduled tasks, integrations), recorded training, and transfer of every account, credential and licence. |

**Gate:** final QA and regression → final design review → client launch walkthrough and owner training → written production acceptance → invoice.

---

## 8. Blockers

The delivery plan flagged twelve open points and recommended answers for all of them. The prototype resolved most by demonstrating them. These remain genuinely open, and the first three cost money or time if they move late.

**1. Membership pricing model — highest priority, needed at kickoff.**
The delivery plan recommended *one tier, up to three listings*. The 8/16 feedback introduced *per-listing pricing with 10% and 15% volume discounts*. The prototype contains both, contradicting each other on adjacent screens. These are different products: one sells a membership, the other sells listing slots. It determines how many membership levels exist, how the allowance is enforced, what upgrade and downgrade do, and what happens when a landlord on the three-listing price deletes a listing. Levels and Stripe prices are created in Milestone 1 and are awkward to restructure once real members exist.

**2. EIN / tax ID and business identity — needed at kickoff.** *New.* A2P 10DLC registration cannot be submitted without it, and the carrier queue is the longest lead time on the project. See §0.2.

**3. Final name and domain — needed at kickoff.** "ThirtyDayHomes", "30 Day Homes" and "WorkStay" are all in use across the spec and prototype. Touches hosting, Stripe, email authentication, the A2P brand registration, the logo and every page.

**4. Exact prices.** Monthly and annual figures, and confirmation of the two-months-free annual discount. The prototype's $49 / $88 / $125 / $490 numbers are placeholders.

**5. ~~Subscription layer.~~ SETTLED 2026-08-30** — WooCommerce core plus our own renewal layer, no premium extension. See §2.4. Still needed from the client: the exact plan structure, because "3 listings per plan" is currently on the homepage as an unverified figure.

**6. Approved SMS message template and consent wording.** Needed before the M2 SMS build, and the consent and opt-out language should have the client's legal guidance per handoff §9.

**7. The launch facility list — week 3.** Which Pittsburgh hospital campuses go live, with addresses. Major campuses first; the admin adds more later without a developer.

**8. Terms, Privacy, Fair Housing text — request week 1.** Attorney-provided. The most common reason a finished site sits unlaunched. We give the attorney a written summary of what the site does and what data it collects — now including SMS.

**9. Admin inquiry copies.** Copied on every inquiry by default, or on request? Affects email volume and admin inbox design. Decide before M2.

---

## 9. Risks

| Risk | Mitigation |
|---|---|
| **A2P 10DLC campaign vetting delays launch** | The single longest third-party lead time. Submit in week 1, not when the code is written. Report status in every Friday update. Build the SMS layer behind a feature flag so the site can launch with email-only notification and SMS enabled the day approval lands — this keeps a carrier queue off the critical path. |
| Stripe account verification takes weeks | Create it week 1. Everything is built and tested in test mode, which needs no verification. |
| Legal text arrives late | Requested week 1 with a written summary for the attorney. Pages ship as clearly marked drafts so nothing else is blocked. |
| Same 42 days now carries more custom code | §0's two changes both add build effort. Milestone 2 is where it lands — the inquiry pipeline's 3.5 days is more than half SMS. If a day has to give, take it from the map view before taking it from proximity or moderation. Flag it at the week-4 checkpoint, not week 7. |
| Elementor widgets break in the editor but work on the frontend | A known failure mode. Every widget tested in-editor on staging as part of its own definition of done, not at milestone end. |
| Geocoding fails on some real addresses | Explicit `geocode_status`, admin flag, manual coordinate override. A listing never publishes with an unknown location. |
| Street address leaks via the REST API | Private meta registered `show_in_rest => false`, never placed on a template, explicitly tested in QA as a release blocker. |
| Inquiry emails land in spam | Authenticated sending domain from Milestone 1, delivery logging, and every inquiry stored on the site — so a failed send is a visible support item, not a lost lead. |
| SMS carrier filtering | Minimal PII in message body, verified sender, approved template, opt-out honoured. Content that reads as marketing gets filtered; a short notification with a link does not. |
| Launching to an empty marketplace | Decide before launch how the first 10–20 listings arrive. A founding-member discount costs nothing to configure and gives the owner a reason to reach out. |
| Scope creep around week 4 | Handoff §10 governs: written down, classified, costed in days, client decides. Nothing absorbed silently. |
| Client feedback slower than two business days | The schedule assumes it. Each slipped review moves the launch date by the same amount — flagged in the Friday update, not discovered at the end. |

---

## 10. Third-party services and recurring costs

Per handoff §8, disclosed before anything is activated. All accounts in the owner's name from the start.

| Service | Purpose | Expected at launch |
|---|---|---|
| Domain | Web address | ~$15 / year |
| Managed WordPress hosting | Site, staging, backups | ~$20–30 / month |
| Elementor (free) | Page editing layer | Free |
| WooCommerce + official Stripe gateway | Checkout and saved cards | Free |
| Renewals, dunning, plan changes | Built into `thirtydayhomes-core` — no premium extension | Free |
| Stripe | Collects payments | No monthly fee; per-transaction rate |
| Maps — geocoding + distance matrix | Coordinates and drive times | Within free allowance given our caching; billing account required |
| Transactional email (Resend / Postmark) | Inquiry and account email | Free tier covers launch volume |
| **SMS gateway (Twilio)** | Landlord inquiry notifications | **Per-message, plus A2P brand and campaign registration fees** |
| Cloudflare Turnstile | Spam protection | Free |
| Rank Math, WP Mail SMTP, Wordfence, UpdraftPlus | SEO, email, security, backups | Free tiers |
| Image compression | ShortPixel / Imagify | Free tier |

**Annual licences now total $0.**

Elementor Pro is not needed, and that is a reading of the handoff document rather than a corner cut. Pro's value is its Theme Builder — header, footer, archive and single templates. §3.1 of the handoff assigns every one of those to the custom theme, and §3.2 assigns the dynamic widgets to the custom plugin. Elementor free registers custom widgets through the same `Widget_Base` API as Pro. So Pro would be paid for a capability the architecture already places elsewhere, and Milestone 1 explicitly asks for Elementor "without unnecessary add-ons".

Against the delivery plan's "software licenses: under $100 per year", we come in at nothing. Hosting at $20–30/month also fits the quoted range.

**The one trade-off, stated plainly:** without Pro the client cannot drag-and-drop the *listing archive* or *single listing* layouts — those stay theme-rendered and change through us. The handoff document anticipates exactly this ("templates … where Elementor alone is not appropriate"), so it is compliance rather than a gap, but it is worth the client hearing it out loud rather than discovering it in month three. Everything they will realistically want to edit — headings, copy, calls to action, imagery, marketing sections, FAQs, which listings a block shows — is editable.

**SMS is the one genuinely new recurring cost** and was not in the delivery plan's cost table, because that plan recommended the no-cost option. Registration fees plus per-message charges need confirming with the client before the gateway is activated, per handoff §8.

---

## 11. What is deliberately not in V1

Preserved from spec §4: native mobile apps; renter payments, rent collection, escrow or deposit processing; **two-way conversational SMS or in-app chat** (handoff §Milestone 2 is explicit — notification only); video tours; background checks or identity verification; lease signing; advanced landlord analytics; dynamic pricing; recommendation engines; multi-language support; a separate site per city; and any custom payment infrastructure.

Also excluded, and worth naming because the prototype currently implies otherwise: **property reviews and ratings**. See §6.1 item 1.
