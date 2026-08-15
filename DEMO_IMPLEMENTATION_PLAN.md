# 30 Day Homes — Frontend Demo Implementation Plan

## 1. Purpose

Build a visually complete, clickable React/Vite demonstration of the full 30 Day Homes marketplace. The demo is for client validation of navigation, screens, copy, states, and role-specific workflows before backend development begins.

This version is intentionally frontend-only:

- No backend, database, authentication service, email service, maps API, file storage, analytics, or Stripe integration.
- All records come from local fixture data.
- All mutations are simulated in browser state and optionally persisted to `localStorage` for the current browser.
- Every important action produces a realistic visible result: modal, toast, status change, redirect, empty state, or confirmation screen.
- Refreshing or clicking “Reset demo” can restore the original sample data.

The demo must feel like a complete product, but must clearly label simulated payments, emails, texts, approvals, and account actions so nobody mistakes them for live operations.

## 2. Product Summary

30 Day Homes is a furnished monthly-rental marketplace launching in Pittsburgh. Renters search for homes near hospitals and contact property owners directly. Landlords pay a recurring membership to publish up to three listings. Administrators approve listings, supervise memberships, manage medical facilities, and review inquiries.

### Recommended demo rules

- Brand: 30 Day Homes, using the supplied midnight-navy and warm-gold identity.
- Launch market: Pittsburgh, Pennsylvania.
- Membership: one plan, up to three active listings.
- Billing options: monthly and annual, with a simulated two-month annual discount.
- Failed-payment grace period: seven days.
- Lapsed listings: hidden, not deleted, and restored after simulated renewal.
- Nearby facilities: three nearest facilities within 15 miles.
- Search distance: approximate straight-line distance.
- Listing detail: simulated drive time.
- Public location: neighborhood and approximate map area, not the exact street address.
- Text landlord: reveal the number and open a simulated/native `sms:` action after confirmation.
- Listing edits: price and availability update immediately; title, address, and photos return the listing to review.
- Minimum-stay presets: 30 days, 60 days, 90 days, and 13 weeks.

## 3. Demo Users and One-Click Entry

The site must include a persistent “Demo roles” control or `/demo` page. A reviewer can enter any journey with one click and without credentials.

### Visitor / renter

One-click action: **Explore as renter**

Starting state: public homepage with sample Pittsburgh listings.

Complete journey:

1. Search by neighborhood, ZIP code, or hospital.
2. Set move-in date and stay duration.
3. View results and apply filters.
4. Sort by newest, price, or hospital proximity.
5. Open a property detail page.
6. Browse the gallery, amenities, location, and nearby hospitals.
7. Save/unsave the home.
8. Submit the inquiry form and see a success state.
9. Choose “Text landlord,” confirm number reveal, and see the simulated handoff.

### Landlord with active membership

One-click action: **Demo active landlord**

Starting state: landlord dashboard with an active annual plan, two listings, recent inquiries, and one remaining listing slot.

Complete journey:

1. Review subscription status and dashboard totals.
2. Create a listing through a multi-step form.
3. Add/reorder/remove simulated photos.
4. Save a draft, preview it, and submit it for approval.
5. Edit a listing.
6. Pause and resume an approved listing.
7. Delete a listing through a confirmation modal.
8. Open received inquiries.
9. Update profile and contact preferences.
10. Open the simulated billing portal and change/cancel the plan.

### Landlord without membership

One-click action: **Demo new landlord**

Starting state: account created, no active plan, and publishing locked.

Complete journey:

1. See the membership requirement.
2. Select monthly or annual billing.
3. Open the simulated Stripe Checkout modal.
4. Test successful, failed, and canceled payment outcomes.
5. See dashboard status update after successful payment.
6. Create a listing and submit it for review.

### Landlord with failed/canceled membership

One-click actions: **Demo failed payment** and **Demo canceled plan**

Starting states:

- Failed payment: warning banner, seven-day grace-period counter, retry action, listings still visible.
- Expired/canceled: inactive status, public listings hidden, renewal action available.

Complete journey:

1. Review billing alert.
2. Open simulated billing modal.
3. Retry or renew.
4. See membership become active and hidden listings restored.

### Administrator

One-click action: **Demo administrator**

Starting state: admin dashboard with pending listings, active members, failed payments, facilities, and recent inquiries.

Complete journey:

1. Review dashboard totals.
2. Open the listing approval queue.
3. Preview, approve, reject, edit, unpublish, or delete a listing.
4. Add a rejection reason and see the landlord-facing status.
5. Review users and simulated membership states.
6. Open the simulated Stripe customer link.
7. Add, edit, enable/disable, and remove a medical facility.
8. Review recent inquiries.
9. Edit basic page content in a simulated content editor.

## 4. Information Architecture and Routes

### Public routes

- `/` — Homepage
- `/homes` — Search results
- `/homes/:slug` — Property detail
- `/pricing` — Membership pricing
- `/how-it-works` — Renter and landlord explanations
- `/about` — Brand and mission
- `/contact` — Contact form demo
- `/terms` — Placeholder legal content
- `/privacy` — Placeholder privacy content
- `/fair-housing` — Fair Housing statement
- `/demo` — One-click role selector and reset control

### Account routes

- `/login` — Login screen with one-click demo accounts
- `/register` — Landlord registration flow
- `/forgot-password` — Simulated reset request
- `/reset-password` — Simulated new-password screen

### Landlord routes

- `/landlord` — Dashboard overview
- `/landlord/listings` — All listings
- `/landlord/listings/new` — Multi-step listing creation
- `/landlord/listings/:id/edit` — Listing editor
- `/landlord/listings/:id/preview` — Private preview
- `/landlord/inquiries` — Inquiry inbox and detail drawer
- `/landlord/membership` — Plan, invoices, and billing state
- `/landlord/profile` — Profile and contact settings

### Admin routes

- `/admin` — Dashboard overview
- `/admin/listings` — Listings and approval queue
- `/admin/listings/:id` — Moderation detail
- `/admin/users` — Landlords and membership states
- `/admin/facilities` — Medical-facility management
- `/admin/inquiries` — Inquiry overview
- `/admin/content` — Editable public-page copy

### Route behavior

- Frontend route guards redirect the selected demo role to the appropriate area.
- Unauthorized routes show a polished access-denied state with a one-click role switch.
- Direct URLs work after refresh in development and the chosen static host configuration.
- A compact demo toolbar shows the current persona and allows instant switching/reset.

## 5. Shared UI System

Create reusable components before building feature pages:

- App shell, responsive header, mobile navigation, footer, breadcrumbs.
- Actual supplied logo asset, navy/gold palette, typography tokens, spacing, shadows, and focus states.
- Buttons, links, inputs, selects, date fields, text areas, checkboxes, radio cards, range controls, badges, tabs, pagination, and tooltips.
- Modal, drawer, dropdown, toast, alert, confirmation dialog, skeleton loader, empty state, and error state.
- Property card, image gallery, price block, amenity list, hospital-distance row, map placeholder, inquiry card, member row, metric card, and status timeline.
- Responsive tables that become cards on narrow screens.
- Keyboard focus trapping for modals and drawers, Escape-to-close, semantic labels, useful alt text, and sufficient color contrast.

## 6. Local Demo Architecture

### Suggested frontend stack

- React and Vite
- React Router for route-level flows
- Context plus `useReducer`, or Zustand, for demo state
- React Hook Form plus Zod for client-side validation
- Local CSS system or the existing CSS approach, organized into tokens/components/pages
- Lucide React for interface icons

### Fixture data

Create typed local modules for:

- `users`: visitor, new landlord, active landlord, failed-payment landlord, canceled landlord, admin.
- `memberships`: trial, active monthly, active annual, past due, grace period, canceled, expired.
- `listings`: draft, pending, approved/live, paused, rejected, and hidden due to billing.
- `facilities`: recognizable Pittsburgh hospital campuses with coordinates and display metadata.
- `inquiries`: new, opened, and archived examples.
- `plans`, `invoices`, `notifications`, `siteContent`, and `dashboardMetrics`.

### Demo-state rules

- Use a single store as the source of truth.
- Seed it from immutable fixtures.
- Persist demo changes to a versioned `localStorage` key.
- Provide “Reset current role” and “Reset all demo data.”
- Keep derived rules centralized: listing visibility, available listing slots, approval requirements, grace-period messaging, and dashboard totals.
- Never show a success state without also changing the related visible data.

## 7. Simulated Integrations

### Stripe checkout and billing portal

No Stripe script or network call is used. A branded modal simulates the hosted Stripe handoff.

Checkout modal stages:

1. Order summary with selected plan.
2. Demo card form with safe fictional details.
3. Processing state.
4. Selectable outcome: success, payment failed, or user canceled.
5. Result screen and corresponding dashboard/membership update.

Billing portal modal supports:

- Switch monthly/annual plan.
- Update simulated payment method.
- Cancel at period end.
- Retry failed payment.
- Renew expired membership.
- View fictional invoices.

Every modal must display **Demo only — no charge will be made**.

### Maps and proximity

- Show a styled static map placeholder with property-area and facility pins.
- Use fixture coordinates and deterministic local distance values.
- Display approximate driving times from fixture data.
- The facility selector and proximity sort/filter must genuinely update results.

### Images

- Use curated fixture images.
- File selection creates local object-URL previews only.
- Simulate upload progress, size/type validation, cover selection, drag/reorder controls, and removal.
- Explain that images remain only in the browser demo.

### Email and texting

- Inquiry submission creates a new local inquiry for landlord/admin views.
- A success modal shows a simulated email-delivery timeline.
- Text action first reveals the number in a consent modal, then offers an `sms:` link where supported.
- Password reset and contact forms show simulated sent states.

## 8. Phased Implementation

## Phase 0 — Audit, scope lock, and demo map

Goal: establish one shared interpretation of the documents and frontend-demo constraints.

Tasks:

- Inventory the current React app, components, styles, images, and dependencies.
- Confirm the 30 Day Homes name, logo usage, and navy/gold design tokens.
- Convert the specification into a route map and role-flow diagram.
- Define fixture schemas and all visible statuses.
- Mark every action as real frontend behavior, simulated integration, or intentionally unavailable.
- Create a screen checklist for client sign-off.

Exit criteria:

- Every required MVP behavior is mapped to a demo screen or interaction.
- No screen implies that real payments, emails, authentication, uploads, or approvals occur.

## Phase 1 — Foundation and design system

Goal: create the application structure and reusable branded UI.

Tasks:

- Add routing and public/account/dashboard layouts.
- Build the shared design tokens and responsive component library.
- Implement the real logo asset consistently in header, footer, auth, and dashboard shells.
- Create modals, drawers, toasts, confirmations, loading states, errors, and empty states.
- Add demo store, fixture seed/reset logic, role selection, and local persistence.
- Implement navigation guards and the demo toolbar.

Exit criteria:

- All routes render inside their correct shells.
- Roles can be switched with one click.
- Shared interactive components work by keyboard and at mobile widths.

## Phase 2 — Public renter experience

Goal: complete the renter journey from landing page to inquiry.

Tasks:

- Finalize homepage sections and calls to action.
- Build search results with working query, price, bedroom, bathroom, type, pet, date, and facility filters.
- Add all four sort options.
- Add result count, filter chips, clear-all, pagination/load-more, no-results, and mobile filter drawer.
- Build property detail gallery, facts, pricing, amenities, availability, area map, and three nearby hospitals.
- Add saved-home interactions.
- Build validated inquiry form and success modal.
- Build text-landlord consent/reveal flow.
- Complete About, How It Works, Pricing, Contact, Terms, Privacy, and Fair Housing pages.

Exit criteria:

- A renter can complete the full journey without dead controls.
- Search/filter/sort results change predictably.
- A submitted inquiry appears in the matching landlord and admin demo inboxes.

## Phase 3 — Account and membership demonstrations

Goal: demonstrate onboarding and every billing state without Stripe.

Tasks:

- Build register, login, forgotten-password, and reset-password screens.
- Add clear one-click demo account choices.
- Build pricing selection and simulated Stripe Checkout modal.
- Support success, failure, and cancellation branches.
- Build membership screen and simulated billing portal.
- Implement active, past-due, grace-period, canceled, and expired dashboards.
- Enforce demo listing visibility and publishing rules from membership state.

Exit criteria:

- Client can test each membership outcome in one or two clicks.
- Successful renewal visibly restores hidden listings.
- Inactive members cannot publish, but never lose their saved work.

## Phase 4 — Landlord workspace

Goal: demonstrate the complete property-management lifecycle.

Tasks:

- Build overview metrics, plan card, recent inquiries, and listing summaries.
- Build listings table/card views with status filters.
- Implement multi-step listing creation covering every specified field.
- Add field validation, autosave messaging, step progress, and unsaved-change confirmation.
- Add simulated image upload and reordering.
- Build preview and submission-for-approval flow.
- Implement edit, duplicate, pause, resume, and delete actions.
- Apply reapproval rules for title, address, and photo edits.
- Build inquiry inbox with unread/read/archive states.
- Build profile and communication preferences.

Exit criteria:

- Active landlord can create a complete listing, preview it, and submit it.
- Listing statuses and dashboard totals update after every action.
- Three-listing limit has an explanatory locked state.

## Phase 5 — Administrator workspace

Goal: demonstrate how the owner operates the marketplace.

Tasks:

- Build admin dashboard totals and activity feed.
- Build listing queue with search and status filters.
- Implement approve, reject with reason, edit, unpublish, and delete flows.
- Build member list with subscription-status filters and profile drawer.
- Add simulated link to Stripe customer details.
- Build facility CRUD with confirmation and enabled/disabled status.
- Build inquiry overview.
- Build a simple public-content editor with preview/reset controls.

Exit criteria:

- Admin approval makes a pending listing appear publicly.
- Rejection reason appears in the landlord workspace.
- Facility changes immediately affect renter selectors and property proximity displays.

## Phase 6 — Cross-role story synchronization

Goal: make the demo feel like one connected system rather than separate mockups.

Tasks:

- Connect renter inquiries to landlord/admin inboxes.
- Connect landlord submissions to admin approval queue.
- Connect approval/rejection actions back to landlord status.
- Connect membership state to public listing visibility.
- Connect facility administration to filters and listing details.
- Add a guided “Play full story” sequence:
  1. Subscribe as landlord.
  2. Create and submit a listing.
  3. Approve it as admin.
  4. Find it as renter.
  5. Send an inquiry.
  6. Read the inquiry as landlord.

Exit criteria:

- The full guided story works in one browser session with visible state continuity.
- Resetting the demo reliably restores the original state.

## Phase 7 — Polish, accessibility, and client handoff

Goal: produce a client-ready walkthrough with no obvious prototype gaps.

Tasks:

- Test common desktop, tablet, and phone widths.
- Test Chrome, Safari, Firefox, and Edge where available.
- Complete keyboard navigation, focus management, labels, contrast, and reduced-motion handling.
- Add route-level loading, 404, access-denied, empty, and validation-error states.
- Remove placeholder/dead controls or give them explicit demo behavior.
- Run production build and lint checks.
- Prepare a demo script and a screen-by-screen client feedback checklist.
- Document what will change when a real backend, Stripe, maps, storage, and email are introduced.

Exit criteria:

- No critical console errors or major responsive defects.
- Every primary action has a visible result.
- Client can review all roles without instructions beyond the on-screen demo controls.

## 9. Definition of Done for the Frontend Demo

- All public, renter, landlord, and admin routes are visually complete.
- Each persona is accessible with one click and no password.
- Search, filters, sorting, favorites, forms, listings, approvals, facilities, inquiries, and membership states operate on local data.
- Simulated Stripe flows cover active, failed, canceled, expired, retry, and renewal outcomes.
- The main cross-role story is synchronized and repeatable.
- The product is responsive and keyboard-usable.
- The supplied brand logo and navy/gold palette are applied consistently.
- There are no misleading claims that a payment, email, upload, map calculation, or database write is real.
- `npm run build` succeeds.
- A reset control and demo walkthrough are included.

## 10. Deferred Production Work

The following must not be implemented or estimated as complete during the frontend-demo phase:

- Real authentication, authorization, sessions, and password security.
- Database schema, migrations, backups, or data administration.
- Stripe products, Checkout, Billing Portal, webhooks, invoices, retries, or live payments.
- Image storage, processing, compression service, or CDN.
- Maps/geocoding API, address validation, or live distance calculation.
- Transactional email, deliverability setup, inquiry delivery, or spam service.
- Server-side validation, rate limiting, audit logs, monitoring, or production security.
- Real CMS, analytics, SEO rendering strategy, sitemap generation, or production deployment.
- Legal approval of Terms, Privacy, Fair Housing, or landlord-provided content.

These items become a separate production implementation plan only after the client approves the demo flows.

## 11. Recommended Client Review Checkpoints

1. **Brand and navigation review:** homepage, route map, shells, and demo-role switcher.
2. **Renter review:** search, property details, inquiry, and text flow.
3. **Membership review:** registration and all simulated Stripe states.
4. **Landlord review:** listing creation, statuses, and inquiries.
5. **Admin review:** approvals, members, facilities, and content.
6. **Final story review:** one continuous landlord → admin → renter → landlord demonstration.

Feedback should be captured against the screen checklist and classified as copy, visual, behavior, missing state, or future production requirement. This keeps client-flow approval separate from backend architecture decisions.

## 12. Client Specification vs. Instaquirk Proposal Traceability

This section is the source-of-truth coverage audit. `Demo` means fully interactive local behavior. `Simulated` means the complete user experience is shown but no external service is called. `Production` means deliberately deferred until the client approves the frontend flow.

### Public website requirements

| Source requirement | Demo implementation | Coverage |
|---|---|---|
| Homepage explaining the service | Complete branded landing page with audience, value proposition, featured homes, hospital proximity, process, trust, and owner CTA | Demo |
| “Find a Rental” CTA | Routes to populated search results, retaining homepage search criteria | Demo |
| “List a Property” CTA | Routes to landlord onboarding, membership choice, and simulated checkout | Demo |
| Search-results page | Results grid/list, count, filters, sorts, facility selection, loading, empty, and error states | Demo |
| Individual listing pages | Gallery, facts, description, amenities, monthly costs, availability, approximate location, hospitals, inquiry, text, and owner summary | Demo |
| About | Complete content page | Demo |
| How It Works | Renter and landlord tabs with step-by-step flows | Demo |
| Pricing/Membership | Monthly/annual options, three-listing allowance, feature comparison, FAQs, and checkout CTA | Demo |
| Contact | Validated form with simulated delivery confirmation | Simulated |
| Terms of Use | Clearly marked draft placeholder page for owner/legal approval | Demo content |
| Privacy Policy | Clearly marked draft placeholder page for owner/legal approval | Demo content |
| Mobile-friendly/current browsers | Responsive layouts and test matrix in Phase 7 | Demo |
| Editable SEO titles/descriptions | Route metadata fixture plus admin content fields; actual index strategy deferred | Demo/Production |
| Clean URLs | Route structure defined in Section 4 | Demo |
| Sitemap/indexable listings | Static demo route inventory; real sitemap and indexing deferred | Production |

### Accounts and membership requirements

| Source requirement | Demo implementation | Coverage |
|---|---|---|
| Email/password registration | Validated registration screens that create a local demo user | Demo |
| Login | Login form plus one-click personas | Demo |
| Password reset | Request, sent, reset, success, and expired-link screens | Simulated |
| Basic profile management | Name, company, email, phone, SMS availability, and notification preferences | Demo |
| Recurring Stripe membership | Monthly/annual selection and realistic hosted-checkout modal | Simulated |
| Active membership required to publish | Central visibility/publishing rule enforced in demo state | Demo |
| Failed payment handling | Past-due status, warning, seven-day grace countdown, retry modal | Demo/Simulated |
| Canceled membership handling | Cancel-at-period-end and expired states | Demo/Simulated |
| Expired membership handling | Listings hidden while data remains intact | Demo |
| Renewal restoration | Simulated successful renewal immediately restores eligible listings | Demo/Simulated |
| Dashboard membership status | Status card, renewal date, plan, listing allowance, alerts, invoices, and actions | Demo |
| Start/cancel/fail acceptance cases | Dedicated one-click personas and selectable checkout outcomes | Demo/Simulated |

### Property-listing requirements

| Source requirement | Demo implementation | Coverage |
|---|---|---|
| Create listing | Multi-step form with progress and local draft state | Demo |
| Edit listing | Prefilled form and status-aware edit behavior | Demo |
| Preview listing | Private public-page-style preview before submission | Demo |
| Publish listing | Submit for approval, then admin approval makes it public | Demo |
| Pause/resume | Confirmation and immediate public visibility update | Demo |
| Delete | Destructive confirmation with recoverability explanation | Demo |
| Draft state | Save, resume, discard, and unsaved-change warning | Demo |
| Pending-approval state | Landlord timeline and admin queue | Demo |
| Rejected state | Admin reason plus revise-and-resubmit action | Demo |
| Approved/live state | Search visibility and listing controls | Demo |
| Billing-hidden state | Clear badge and renewal restoration action | Demo |
| Photo upload | Local preview, progress simulation, validation, reordering, cover selection, and removal | Simulated |
| Practical image limits | UI states: JPG/PNG/WebP, maximum 10 images, 10 MB each; production compression deferred | Demo/Production |
| Admin approval before public | Cross-role submission and moderation workflow | Demo |
| Reapproval for sensitive edits | Title, address, and photo changes return to pending; price/availability remain live | Demo |

### Complete listing field coverage

The create/edit flow must contain every field named in the client specification:

| Field | Required demo behavior |
|---|---|
| Title | Required; sensitive edit triggers reapproval |
| Street address | Required privately; never displayed publicly; sensitive edit triggers reapproval |
| City | Required; Pittsburgh default |
| State | Required; Pennsylvania default |
| ZIP code | Required; format validation |
| Monthly rent | Required positive amount; searchable/filterable |
| Security deposit | Optional amount; included in cost summary when present |
| Bedrooms | Required numeric/select value |
| Bathrooms | Required; supports half bathrooms |
| Property type | Required controlled list |
| Furnished status | Required; furnished default for marketplace eligibility |
| Minimum stay | Required presets including 30, 60, 90 days, and 13 weeks |
| Availability date | Required; filterable and immediately editable |
| Lease term | Required controlled options plus details |
| Utilities included | Individual utility selections plus “all included” |
| Parking | Type, quantity, fee, and notes |
| Pet policy | Allowed/not allowed/considered, types, fees, and notes |
| Amenities | Searchable grouped checklist |
| Description | Required with length counter and Fair Housing guidance |
| Contact information | Name, email, optional SMS-capable phone, and preferred method |
| Photos | Cover image plus ordered gallery |
| Fair Housing acknowledgment | Required checkbox before submission, as proposed by Instaquirk |

### Search and filter requirements

| Source requirement | Demo implementation | Coverage |
|---|---|---|
| Search Pittsburgh area | Seeded neighborhoods and city match | Demo |
| Search neighborhood | Autocomplete fixture options and free-text match | Demo |
| Search ZIP code | Seeded ZIP lookup | Demo |
| Search/select medical facility | Dedicated facility autocomplete/select | Demo |
| Monthly price filter | Minimum/maximum controls with validation | Demo |
| Bedroom filter | Any/1/2/3/4+ | Demo |
| Bathroom filter | Any/1/1.5/2/3+ | Demo |
| Property type filter | Multi-select | Demo |
| Pet-policy filter | Allowed, considered, not allowed | Demo |
| Availability-date filter | Date input evaluated against fixture availability | Demo |
| Newest sort | Deterministic fixture publish dates | Demo |
| Price low-to-high | Numeric local sort | Demo |
| Price high-to-low | Numeric local sort | Demo |
| Closest facility sort | Local facility-distance matrix | Demo |
| Only approved, active-member listings | Central selector excludes drafts, pending, rejected, paused, and billing-hidden records | Demo |

### Medical-facility proximity requirements

| Source requirement | Demo implementation | Coverage |
|---|---|---|
| Facility name and approximate distance | Cards and detail pages use deterministic local values | Demo |
| Estimated driving time | Displayed on detail page from fixture data | Demo |
| Facility proximity filter/sort | Real local filtering and sorting | Demo |
| Admin-managed Pittsburgh facilities | Add/edit/enable/disable/delete UI updates other demo views | Demo |
| Additional cities later | Fixture schema includes city/state/coordinates rather than Pittsburgh-only component logic | Demo architecture |
| Mapping-provider recommendation | Production note recommends Mapbox or Google Maps comparison before implementation | Planning only |
| Usage limits/API costs | Not represented as live functionality; production vendor decision required | Production |
| Address geocoding/storage/refresh | Static fixture coordinates and distance matrix only | Production |
| Three nearest within 15 miles | Default display rule from Instaquirk proposal | Demo |
| General map area, not exact address | Public map deliberately obscures exact location | Demo |

### Inquiry requirements

| Source requirement | Demo implementation | Coverage |
|---|---|---|
| Name | Required validation |
| Email | Required format validation |
| Phone | Optional format validation |
| Desired move-in date | Required date validation |
| Expected stay length | Required presets plus custom option |
| Message | Required with length guidance |
| Email landlord | Delivery timeline and local landlord-inbox creation | Simulated |
| Optional admin copy | Checkbox/config fixture and admin-inbox creation | Simulated |
| Text landlord | Consent, number reveal, prefilled message, and `sms:` handoff | Demo/native handoff |
| Spam protection | Honeypot and rate-limit explanation/state; real enforcement deferred | Demo/Production |
| Form validation | Complete client-side validation and errors | Demo |
| No in-app chat | No chat interface included | Confirmed exclusion |

### Administration requirements

| Source requirement | Demo implementation | Coverage |
|---|---|---|
| Secure admin login | One-click role guard for demo; real security deferred | Demo/Production |
| Approve listing | Confirmation updates public results | Demo |
| Reject listing | Required reason updates landlord view | Demo |
| Edit listing | Admin edit drawer/page | Demo |
| Unpublish listing | Confirmation updates public visibility | Demo |
| Delete listing | Destructive confirmation updates all views | Demo |
| View users | Searchable/filterable member table/cards | Demo |
| View membership status | All proposed billing statuses represented | Demo |
| Link to Stripe | Simulated customer/billing modal, clearly labeled | Simulated |
| Manage facility list | Complete local CRUD and enabled state | Demo |
| Manage basic site text | Local content editor, preview, save, and reset | Demo |
| Active-member total | Derived dashboard metric | Demo |
| Active-listing total | Derived dashboard metric | Demo |
| Recent-inquiry total | Derived dashboard metric | Demo |

### Technical, ownership, and delivery requirements

| Source requirement | Frontend-demo treatment | Coverage |
|---|---|---|
| Commonly supported technology | React/Vite with ordinary open-source packages | Demo |
| Avoid proprietary lock-in | Fixtures and state rules remain framework-readable and replaceable | Demo architecture |
| Owner-controlled production accounts | No production accounts are created during demo | Production |
| Source code/design/data access | Workspace contains all demo code and fixture data | Demo |
| HTTPS | Local development only; required at production hosting | Production |
| Secure password handling | No passwords stored or transmitted in demo | Production |
| Role-based access | UI route guards only; server enforcement required later | Demo/Production |
| Server-side validation | Client validation only | Production |
| Backups | Resettable fixture seed only | Production |
| Common web-attack protection | No backend attack surface in demo; production requirement remains | Production |
| No card-detail storage | Demo card values are never persisted | Demo |
| Analytics | Consent/analytics placeholders only | Production |
| Recurring cost disclosure | Production handoff checklist retains domain, hosting, Stripe, maps, email, and licenses | Planning only |
| Staging/test version | Vite demo is the review version | Demo |
| Sample accounts/listings | One-click personas and rich fixtures | Demo |
| Training/documentation | Demo walkthrough and feedback checklist in Phase 7 | Demo deliverable |
| 30-day defect support | Contract/delivery commitment, not software behavior | Production contract |

## 13. Explicit Exclusions Preserved from the Client Specification

The frontend demo must not accidentally introduce scope the client explicitly excluded:

- No native iOS or Android application.
- No renter payment, rent collection, escrow, security-deposit transaction, or booking checkout.
- No in-app chat.
- No video-tour system.
- No background checks or identity verification.
- No electronic lease signing.
- No advanced landlord analytics.
- No dynamic pricing.
- No recommendation engine.
- No multilingual system.
- No separate site per city.
- No custom payment-card infrastructure.

Security deposits may be displayed as listing information only. The membership checkout is for landlords only and remains simulated in this demo.

## 14. Full Cross-Role Acceptance Scenarios

These scenarios are the final proof that screens are connected and the specification is not implemented as isolated pages.

### Scenario A — New landlord becomes a member and publishes

1. Start at `/demo` and choose **Demo new landlord**.
2. Open pricing and choose annual membership.
3. Complete simulated Stripe Checkout with the success outcome.
4. Confirm dashboard shows active status, renewal date, and 0/3 listing usage.
5. Create a listing using every required field.
6. Add photos, reorder them, choose a cover, and preview the page.
7. Accept Fair Housing acknowledgment and submit.
8. Confirm landlord status is pending and listing is absent from public search.
9. Switch to admin and approve the listing.
10. Switch back to landlord and confirm status is live.
11. Switch to renter and confirm the listing is searchable.

### Scenario B — Renter finds a hospital-adjacent home and inquires

1. Choose **Explore as renter**.
2. Select a Pittsburgh hospital, move-in date, and 13-week stay.
3. Apply price, bedroom, and pet filters.
4. Sort by closest facility.
5. Open a result and inspect gallery, costs, amenities, approximate area, three nearby facilities, and driving times.
6. Save the listing.
7. Submit every inquiry field and confirm simulated delivery.
8. Use the text-landlord flow and confirm the phone-number disclosure.
9. Switch to the matching landlord and confirm the inquiry appears unread.
10. Open it, mark it read, and archive it.
11. Switch to admin and confirm the copied inquiry appears if admin-copy is enabled.

### Scenario C — Failed payment, grace period, expiry, and recovery

1. Choose **Demo failed payment**.
2. Confirm the warning explains the seven-day grace period and current public visibility.
3. Open billing and simulate another failed retry.
4. Use “Advance demo state” to move beyond the grace period.
5. Confirm membership becomes expired and listings disappear from public search without being deleted.
6. Renew with a successful simulated payment.
7. Confirm membership is active and eligible listings are restored publicly.

### Scenario D — Sensitive listing edit requires reapproval

1. Enter as active landlord and edit only price and availability.
2. Confirm changes remain live without reapproval.
3. Edit the title, street address, or photos.
4. Confirm a warning explains that the edit requires review.
5. Submit and confirm pending status.
6. Switch to admin, compare changes, and reject with a reason.
7. Switch to landlord, see the reason, revise, and resubmit.
8. Approve as admin and confirm the updated public listing.

### Scenario E — Administrator manages marketplace data

1. Enter as administrator.
2. Search and filter listings by every status.
3. Approve, reject, unpublish, edit, and delete separate fixture records.
4. Filter users by active, past due, canceled, and expired membership.
5. Open simulated Stripe details for a user.
6. Add a medical facility and verify it becomes available in renter search.
7. Disable a facility and verify it disappears without losing its record.
8. Edit homepage copy, preview it, save it, and verify the public page changes.
9. Reset demo data and verify the original marketplace returns.

### Scenario F — Limits, validation, and unhappy paths

1. Use a landlord who already has three listings and confirm creation is locked with a membership explanation.
2. Attempt listing submission with missing/invalid fields and confirm field-level errors and error summary.
3. Attempt invalid image types, excessive size, and more than 10 images.
4. Attempt an inquiry with invalid email, past move-in date, and empty message.
5. Apply filters that return no homes and recover with “Clear filters.”
6. Visit a missing listing URL and see a useful 404/recovery state.
7. Visit an admin route as renter and see access denied plus one-click role switch.
8. Attempt to leave a dirty form and confirm unsaved-change protection.
9. Cancel every destructive modal and verify no data changes.

## 15. Open Business Decisions Still Requiring Client Confirmation

The documents intentionally leave these business inputs unresolved. The demo will use the proposal recommendations as defaults, but the review UI/content should make them easy to change:

1. Final site name and domain. Current demo assumption: **30 Day Homes**.
2. Exact monthly membership price.
3. Exact annual membership price/discount.
4. Final listing allowance. Current assumption: three.
5. Initial Pittsburgh medical-facility list.
6. Final legal/business entity name.
7. Approved Terms, Privacy, and Fair Housing wording.
8. Whether admin receives a copy of every inquiry by default.
9. Final property-photo limits.
10. Final launch copy and founding-member offer.

None of these blocks the interactive demo. They must be resolved before production integration.
