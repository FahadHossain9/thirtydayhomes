Table of Contents

# ThirtyDayHomes

## WordPress Development Handoff and Milestone Acceptance Plan

**Audience:** Full-stack WordPress developer  
**Delivery model:** Custom WordPress theme \+ custom plugin \+ Elementor  
**Project type:** Paid-membership marketplace for furnished 30+ day rentals  
**Launch market:** Pittsburgh, Pennsylvania  
**Primary users:** Renters, landlords/property managers, and administrators  
---

## 1\. Purpose

This document defines how the approved ThirtyDayHomes MVP should be implemented in WordPress and how completion will be evaluated. The developer is responsible for full-stack WordPress delivery. A frontend designer may review and refine the visual implementation after each milestone because the finished product must meet a high visual standard, not merely function correctly.  
The project has three milestones. Each milestone must be completed as a full, testable user flow. Partial screens, disconnected functions, placeholders, or features that work only from the database are not considered complete. Payment for a milestone becomes due only after its stated deliverables and acceptance tests have passed and the milestone has been approved in writing.

## 2\. Non-negotiable delivery principles

1. **Complete flows, not isolated pages.** Every feature must work from the user interface through validation, storage, notifications, permissions, and the resulting dashboard or public view.  
2. **Elementor-editable presentation.** Public-facing page sections and approved dynamic widgets must be editable through Elementor without editing PHP. The administrator must be able to update headings, descriptions, calls to action, imagery, and other agreed presentation settings.  
3. **Plugin-owned business logic.** Marketplace data and behavior must live in the custom plugin, not be locked into Elementor templates or the theme.  
4. **Theme-owned presentation.** The custom theme controls global styling, layout, typography, responsive behavior, and Elementor integration. It must not contain critical marketplace business rules.  
5. **Design quality is part of acceptance.** Responsive spacing, typography, component consistency, states, accessibility, and polish must match approved designs. “Functionally complete” does not override visible design defects.  
6. **No silent scope expansion.** New requests must be documented and classified as an in-scope clarification, a replacement, or a separately estimated change.  
7. **Staging first.** All work must be demonstrated and accepted on staging before production deployment.

## 3\. Recommended WordPress architecture

### 3.1 Custom theme

The custom theme should provide:

* Global design tokens: colors, typography, spacing, borders, shadows, and breakpoints.  
* Header, footer, navigation, account navigation, and responsive layout framework.  
* Elementor-compatible page templates and theme locations.  
* Templates for search results, property details, dashboards, account screens, and content pages where Elementor alone is not appropriate.  
* Accessible HTML structure, keyboard focus styles, loading states, empty states, validation states, and error states.  
* Minimal business logic. Theme replacement must not remove listings, memberships, inquiries, hospitals, or their relationships.

### 3.2 Custom marketplace plugin

The custom plugin should provide:

* Property/listing data, listing fields, statuses, and landlord ownership.  
* Medical-facility data, map coordinates, and proximity calculations.  
* Inquiry storage, email delivery, SMS delivery, logs, and dashboard presentation.  
* Membership rules and Stripe/WooCommerce subscription integration as selected during setup.  
* User roles, permissions, moderation, and admin workflows.  
* Search/filter query logic and dynamic Elementor widgets.  
* Activation/deactivation safety, upgrade routines, sanitization, escaping, nonce checks, and capability checks.  
* Hooks, filters, and documented extension points for future development.

### 3.3 Data model

The developer must document the final data model before implementation. At minimum it must support:

* **Property:** owner, address, private/public location fields, latitude/longitude, rent, deposits and fees, bedrooms, bathrooms, type, furnished status, stay length, availability, utilities, parking, pets, categorized amenities, description, contact settings, photos, and moderation status.  
* **Medical facility:** name, address, city, state, ZIP, latitude/longitude, active status, and optional display metadata.  
* **Inquiry:** property, renter name, email, phone, move-in date, stay length, message, consent/acknowledgment, timestamps, delivery statuses, and landlord read/unread state.  
* **Membership:** member, plan, Stripe customer/subscription identifiers, status, renewal information, grace period, and listing allowance.

Use WordPress-native structures where practical, but use custom database tables where query volume, relationships, or delivery logs justify them. Do not store critical structured data inside Elementor page JSON.

## 4\. Elementor requirements

Elementor is the editing layer for presentation, not the application database.  
The developer should provide reusable Elementor widgets or dynamic components for:

* Hero/property search.  
* Featured/recent properties.  
* Property cards and result summaries.  
* Medical-facility proximity display.  
* Membership/pricing presentation.  
* Calls to action, FAQs, and standard marketing sections.  
* Approved site-wide content blocks.

Widget controls should expose safe presentation options such as heading, supporting copy, item count, query/filter choice, imagery, alignment, and approved style variants. Dynamic values such as prices, property ownership, membership status, distance, and inquiry content must come from the plugin and must not be manually duplicated in Elementor.  
Editing must be tested in Elementor on staging. Widgets must render correctly in the editor and on the frontend, avoid editor-only CSS, and remain responsive after content changes.

## 5\. Visual design and frontend review

The target is a polished, trustworthy premium marketplace suitable for healthcare professionals, working travelers, families, and property owners. The interface should feel calm, clear, modern, and credible.  
Required design standards include:

* Consistent spacing, typography, buttons, form controls, cards, icons, and feedback states.  
* Strong hierarchy and readable layouts on desktop, tablet, and mobile.  
* Purposeful empty, loading, error, success, disabled, pending, and payment-failure states.  
* High-quality listing galleries and image cropping behavior.  
* Accessible contrast, visible focus, sensible tab order, labels, and touch targets.  
* No unstyled plugin output, shortcode artifacts, layout shifts, or inconsistent default WordPress controls.

At the end of each milestone, the frontend designer may conduct a focused review. The developer must implement agreed corrections that bring the milestone into conformity with the approved design. This review does not authorize unrelated redesign or new functionality. Design review and corrections occur before milestone approval and payment.  
---

# Milestone 1 — Foundation

**Estimated duration:** 13 working days / approximately 2.5 weeks  
**Contract allocation:** 30%

## Deliverables

### Discovery and design

* Confirm scope, user roles, listing fields, amenities, membership rules, third-party services, and privacy requirements.  
* Document renter, landlord, and administrator journeys.  
* Approve page list, wireframes, responsive designs, component system, and interaction states.  
* Define Elementor-editable areas and plugin-controlled areas.

### WordPress foundation

* Configure local/staging/production workflow, version control, backups, security baseline, and deployment process.  
* Build the custom theme foundation and custom plugin skeleton.  
* Configure Elementor and global design settings without unnecessary add-ons.  
* Create public page structures: Home, Search, Property, About, How It Works/FAQ, Pricing, Contact, Terms, and Privacy.

### Accounts and memberships

* Landlord registration, login, logout, password reset, and profile management.  
* Stripe subscription integration in test mode, including the approved membership plans and listing limits.  
* Correct handling of active, failed, past-due, canceled, and expired membership states.  
* Landlord dashboard shell showing real account and membership status.  
* Authenticated permissions preventing one landlord from accessing another landlord’s data.

### Delivery infrastructure

* Transactional email provider configured on staging with authenticated sending.  
* SMS gateway account/configuration started, including any required US business messaging registration. Carrier approval is a third-party dependency and must be raised immediately if it threatens the schedule.

## Milestone 1 end-to-end acceptance

The milestone passes only when all of the following work on staging:

1. A new landlord can register, verify/access the account, log in, reset the password, and update the profile.  
2. The landlord can start a test subscription and see the correct membership status and listing allowance in the dashboard.  
3. Test scenarios for successful, failed, canceled, and expired subscriptions produce the agreed account/listing state.  
4. Protected pages and records enforce role and ownership permissions.  
5. Approved public and account screens match the responsive design at agreed viewport sizes.  
6. Elementor-editable sections can be changed by an administrator and render correctly on the frontend.  
7. No critical or high-severity defects remain.

## Milestone 1 review and payment gate

* Developer self-QA completed.  
* Frontend design review completed and agreed corrections applied.  
* Client walkthrough completed using the acceptance checklist.  
* Written acceptance recorded.  
* Milestone 1 invoice/payment follows acceptance; incomplete flows carry into the milestone and do not move to Milestone 2 acceptance.

---

# Milestone 2 — Core Marketplace Build

**Estimated duration:** 18 working days / approximately 3.5 weeks  
**Contract allocation:** 45%

## Deliverables

### Listing management

* Create, save draft, edit, preview, submit, pause, and delete a listing.  
* Pending approval, live, paused, rejected, and membership-hidden states.  
* Full listing fields, categorized extended amenity options, fees, availability, contact preferences, and Fair Housing acknowledgment.  
* Multi-image upload, validation, ordering, deletion, compression, and responsive display.  
* Approved edits that can publish immediately versus material edits that return to moderation.

### Public marketplace

* Property search and results with price, bedrooms, bathrooms, type, pet policy, availability, and agreed sorting options.  
* List and map presentation where included in the approved design.  
* Public property page with gallery, key facts, description, amenities, approximate location, nearby facilities, fees, availability, and inquiry actions.  
* Exact property address protected from public display according to the privacy decision.

### Medical-facility proximity

* Administrator can add/edit/deactivate major hospitals and save their map locations/coordinates.  
* Property addresses are converted to coordinates and stored securely.  
* The application calculates proximity from each property to the administrator-managed facilities.  
* Search can sort/filter by facility proximity.  
* Property pages show the agreed number of nearest facilities and distance format.  
* The initial delivery plan specifies the three nearest facilities within 15 miles. A change to five is a configurable presentation decision only if it does not change the agreed service/API cost.

### Inquiry, email, SMS, and dashboard flow

The confirmed inquiry flow is included in this milestone. When a renter submits a valid inquiry:

1. The inquiry is stored in the site database.  
2. It appears in the correct landlord dashboard with unread/read status.  
3. It is visible to the administrator where authorized.  
4. An email notification is sent to the landlord, with optional administrator copy.  
5. An SMS notification is sent through the approved third-party SMS gateway to the landlord’s verified text-capable phone number.  
6. Delivery attempts and provider responses are logged without exposing sensitive credentials.

The renter form must include name, email, optional/required phone as approved, move-in date, expected stay, message, consent/acknowledgment, validation, and spam protection. The interface must provide clear sending, success, validation-error, email-failure, and SMS-failure behavior. A notification failure must not silently discard a successfully stored inquiry.  
The SMS implementation must include:

* Environment-based gateway credentials.  
* Verified sender/number configuration.  
* US carrier/A2P registration where required.  
* Message template approved by the client.  
* Phone normalization and validation.  
* Opt-in/consent language and opt-out handling appropriate to the selected provider and legal guidance.  
* Delivery status or failure logging.  
* Rate limiting and abuse protection.  
* Test mode or approved test recipient support.  
* No private API keys in the theme, Elementor, browser code, or repository.

The website does not require a full two-way in-app chat unless separately approved. SMS notification of an inquiry is required; conversational reply synchronization is not implied.

### Membership enforcement

* Listings automatically become hidden after the agreed grace period when membership is inactive.  
* Existing listing data remains intact.  
* Eligible listings are restored correctly after membership renewal.

## Milestone 2 end-to-end acceptance

The milestone passes only when all of the following work on staging:

1. An eligible landlord creates a complete listing with images and amenities, previews it, and submits it for approval.  
2. The appropriate moderation state is visible to landlord and administrator.  
3. An approved property appears in search and on its public page with correct data and responsive presentation.  
4. Search, all agreed filters, sorting, list/map behavior, and empty states work together.  
5. Facility distances are accurate for the agreed Pittsburgh test properties and hospitals.  
6. A renter submits an inquiry; the same inquiry is stored, appears in the landlord dashboard, sends an email, and sends an SMS to the agreed real test phone.  
7. Email and SMS failure cases are logged and produce safe user/admin feedback without losing the inquiry.  
8. Membership lapse and restoration correctly hide and restore eligible listings.  
9. Elementor dynamic widgets render real plugin data in both editor and frontend.  
10. No critical or high-severity defects remain.

## Milestone 2 review and payment gate

* Developer self-QA and integration testing completed.  
* Real-phone SMS test evidence supplied.  
* Frontend design review completed and agreed corrections applied.  
* Client walkthrough completed using renter, landlord, and admin accounts.  
* Written acceptance recorded.  
* Milestone 2 invoice/payment follows acceptance of the entire core flow.

---

# Milestone 3 — Administration, Launch, and Handover

**Estimated duration:** 11 working days / approximately 2 weeks  
**Contract allocation:** 25%

## Deliverables

### Administration

* Listing approval queue, preview, approval, rejection reason, removal, and audit information.  
* User/member overview with membership status and listing counts.  
* Medical-facility management including name, address/map location, coordinates, active status, and validation.  
* Inquiry overview and email/SMS delivery status visibility appropriate for support.  
* Basic dashboard totals and approved content controls.  
* Elementor editing access configured with safe roles/capabilities.

### Production readiness

* SEO titles/descriptions, clean URLs, sitemap, robots settings, and indexable public listings.  
* Analytics configured with client-owned access.  
* Performance optimization, image behavior, caching compatibility, and database cleanup strategy.  
* Accessibility, browser, device, security, and regression testing.  
* Backup and restore test, uptime monitoring, and error logging.  
* Stripe switched to live configuration after approval.  
* Production email and SMS credentials/configuration verified.  
* Domain deployment and HTTPS verification.

### Documentation and handover

* Written administrator guide covering users, memberships, listings, facilities, inquiries, email/SMS status, Elementor content, backups, and common troubleshooting.  
* Developer documentation covering architecture, data model, hooks, dependencies, build/deployment, scheduled tasks, and third-party integrations.  
* Recorded training session.  
* Client ownership/access for domain, hosting, WordPress administrator, Stripe, mapping, email, SMS, analytics, repository, backups, and licenses.  
* Source code and database handover with no undisclosed developer-owned dependency.

## Milestone 3 end-to-end acceptance

The milestone passes only when:

1. The complete MVP acceptance checklist passes in production or an agreed production-equivalent environment.  
2. Administrator can independently approve listings, manage members and hospitals, review inquiries, check notification failures, and update approved Elementor content.  
3. A production-mode inquiry is stored and its approved email/SMS notifications are verified using controlled test data.  
4. Current desktop and mobile browsers show no major layout or interaction defects.  
5. No critical or high-severity security, accessibility, payment, inquiry, or data-loss defects remain.  
6. Backup restoration has been demonstrated or documented with evidence.  
7. Training and documentation are delivered and every client-owned account is accessible.  
8. The client controls the code repository, database, domain, and all third-party accounts.

## Milestone 3 review and payment gate

* Final developer QA and regression suite completed.  
* Final frontend design review completed and agreed corrections applied.  
* Client launch walkthrough and owner training completed.  
* Written production acceptance recorded.  
* Milestone 3 invoice/payment follows final acceptance and handover.

---

## 6\. Definition of done for every feature

A feature is “done” only when:

* Its frontend, backend, permissions, validation, storage, and notifications work together.  
* Normal, empty, loading, error, unauthorized, and relevant failure states are handled.  
* It works at approved desktop, tablet, and mobile sizes.  
* It meets the approved design and accessibility baseline.  
* It is tested with the intended user role.  
* It does not expose secrets or private data.  
* It is deployed to staging and included in the milestone walkthrough.  
* Required documentation is updated.  
* No known critical or high-severity defect remains.

## 7\. Quality assurance and defect handling

The developer must maintain a milestone checklist and defect list. Severity should be interpreted as:

* **Critical:** payment, authentication, inquiry, SMS/email, security, data loss, or primary flow is unusable.  
* **High:** major promised behavior is broken with no reasonable workaround, or a severe responsive/accessibility defect blocks use.  
* **Medium:** material issue with a workaround that does not block the milestone’s primary flow.  
* **Low:** cosmetic or minor usability issue.

Critical and high defects block milestone acceptance. Medium and low defects must be documented and either resolved before acceptance or explicitly accepted in writing with a target date.

## 8\. Third-party services and client dependencies

The developer must provide the proposed provider, account owner, estimated launch usage, expected recurring cost, free-tier assumptions, and setup requirements before a paid service is activated. Likely services include:

* Hosting and domain.  
* Elementor/required WordPress licenses.  
* Stripe or the approved subscription layer.  
* Maps/geocoding/distance service.  
* Transactional email service.  
* Third-party SMS gateway and US carrier registration.  
* Analytics, backup, security, and uptime services where applicable.

The client must supply or approve business identity details, legal text, Stripe information, SMS registration information, medical facilities, test addresses, message templates, and test recipients on schedule. Third-party carrier approval is outside the developer’s control, but integration work, timely submission, status reporting, and safe fallback/error handling remain the developer’s responsibility.

## 9\. Security and privacy baseline

* Sanitize input, escape output, use nonces, and enforce WordPress capabilities and record ownership.  
* Keep exact property addresses and private contact information out of public markup and APIs unless explicitly authorized.  
* Store secrets in server configuration/environment, never Elementor or committed source.  
* Protect forms with spam controls and rate limits.  
* Minimize personal data in SMS content and logs.  
* Use HTTPS, least-privilege accounts, backups, update procedures, and audit/error logs.  
* Obtain client legal guidance for Terms, Privacy, Fair Housing, SMS consent, retention, and opt-out language.

## 10\. Change control

Each milestone is delivered against this document and the approved screen designs. A requested change must be written down with its effect on scope, schedule, cost, dependencies, and acceptance criteria. Work should not move into a later milestone to conceal incomplete acceptance. Equally, future enhancements should not prevent acceptance of a milestone that fully satisfies its agreed scope.

## 11\. Final handoff expectation

The finished system must be maintainable by a competent WordPress developer. The client must be able to update normal marketing content through Elementor and operate marketplace administration without editing code. The custom plugin must preserve core marketplace data and behavior independently of theme presentation. No essential functionality may depend on an undocumented account, inaccessible license, developer-owned API credential, or manual database intervention.