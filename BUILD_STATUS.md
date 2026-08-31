# Build Status

What was built, what was assumed, what is blocked, and how to test it.

**Last updated:** 30 August 2026
**Plugin:** `thirtydayhomes-core` 0.2.0 · **Theme:** `thirtydayhomes` 0.15.0
**Live:** https://thirtydayhomes.com (Hostinger) · **Local:** http://localhost/thirtydayhomes

This is the working document. Update it at the end of every closed section — the
four headings below are the contract: *what was done, what was assumed, what is
blocked or needs configuring, and how to verify it.*

---

## Working agreement

One section at a time. No section starts before the previous one closes.

```
   BUILD locally  →  I VERIFY  →  I REPORT  →  you COMMIT  →  you DEPLOY
        │                                                          │
        └──────────────  next section only starts here  ────────────┘
```

**"100% done" means all five, not just the first:**

1. The feature works end to end from the interface — not only from the database.
2. Empty, loading, error, unauthorised and failure states are handled.
3. It works at desktop, tablet and phone widths.
4. Keyboard reachable, labelled, visible focus.
5. Lint clean, no PHP errors, and **tested over HTTP rather than assumed**.

That fifth point keeps earning its place. Testing rather than assuming has caught,
so far: an ownership filter that never ran, so any landlord could read any other
landlord's inquiries; a failed sign-in that redirected to the home page and showed
no error at all; a notice key that collapsed to `md5(IP + user agent)` and could
show one visitor the email address another had typed; a login throttle shared by
everyone behind one IP; a release zip whose paths used backslashes and would have
failed on a Linux server; and a pricing table rendering `$49, $125, $88`.

If something in a section turns out blocked, the rest of that section is finished
and what remains is stated plainly. Nothing is quietly carried forward — that is
how a build ends up 80% done everywhere and finished nowhere.

---

# 1. What was done

## 1.1 Foundation — complete

| Area | Detail |
|---|---|
| Environment | Local XAMPP (WP 7.1, PHP 8.2), repo junctioned into `htdocs`. Live on Hostinger. Git, plus a scripted release build. |
| Plugin skeleton | 3 post types (`tdh_listing`, `tdh_facility`, `tdh_inquiry`), 3 custom statuses (`tdh_paused`, `tdh_rejected`, `tdh_billing_hold`), 4 taxonomies, the `tdh_landlord` role, 2 custom tables. |
| Theme foundation | Design tokens, header, footer, navigation, account navigation, responsive framework, Elementor theme locations. No business rules — see the architectural rule in `README-wp.md`. |
| Blocks | 13 shortcodes; 5 Elementor widgets are thin wrappers over the same `TDH\Render` methods. Never two implementations. |
| Public pages | 14 seeded pages, both menus, static front page — all created by code, not copied from a database. |
| Client review mode | Persona bar, gated four ways, refuses to render in production. |
| Demo importer | Tools → Import Demo Content. Idempotent; verified from an empty database. |

## 1.2 Accounts — complete

Registration, sign in, sign out, password reset, profile editing, and the landlord
dashboard, all on our own front-end pages rather than `wp-login.php`.

Cross-landlord permission enforcement is in place and tested: a landlord reaches
only their own listings and only inquiries sent to them.

**Security fixes made during this work, each found by testing rather than review:**

- **Inquiry ownership filter never ran.** It matched `read_tdh_inquiry`, but
  WordPress rewrites a custom post type's meta capability to its generic form
  (`read_post`) *before* the filter runs. Any landlord could read any other
  landlord's inquiries.
- **A failed sign-in looked like a successful one.** `fail()` redirected to
  `wp_get_referer()`. The Referer header is optional — privacy browsers trim it —
  and without it the fallback was the home page, which renders no notices. A wrong
  password showed the front page and no error.
- **Notice keys were shared between visitors.** `visitor_key()` hashed
  `TEST_COOKIE . IP . USER_AGENT` and called itself cookie-scoped. WordPress only
  sets `TEST_COOKIE` on `wp-login.php`, so on our forms it is always absent and the
  key collapsed to `md5(IP + user agent)`. Two people behind one office NAT on the
  same browser shared a key — and the stash carries the email address typed into
  the form.
- **The login throttle was per-IP, not per-account.** Five failures from anyone
  behind an office NAT locked out everyone there. Now five per account, plus a
  higher per-address cap so credential spraying across many accounts is still
  caught.

## 1.3 Payments — settings complete, billing not built

**Listings → Payments** (`manage_options` only — approving a listing and holding
the key that moves money are different jobs).

- Sandbox and production credentials stored under **separate option names**; the
  mode switch only chooses which set is *read* and never overwrites the other.
- Any credential can be moved into `wp-config.php` as a constant, which then beats
  the database — so production secrets need never sit in a database export.
- A live key pasted into the sandbox tab is **rejected on its prefix**, and the
  connection test reads `livemode` back from Stripe to catch what the prefix misses.
- Stored secrets are never rendered into the page; a blank submit keeps rather than
  erases them.
- **Price IDs are verified against Stripe** — amount, monthly recurrence, and mode —
  because a Price ID is opaque and the right IDs in the wrong slots would silently
  bill $125 for a one-listing plan.
- **Going live is gated.** The switch runs the full readiness check and refuses if
  anything fails, leaving the site in test. Returning to test is never gated.

## 1.4 Design work

The About page was rebuilt as four bands rather than a column of prose; page
banners can carry their own headline and lead separately from the page title; the
membership plans render ascending after a reorder bug put them in 1-3-2 order; and
the price's currency symbol is set smaller and raised because Playfair's dollar
sign was striking through the first digit.

## 1.5 Contact form — complete

The Contact page invited people to "get in touch" and then offered no way to do
it: no form, no address, no number. It now carries `[tdh_contact]`.

A message is **stored first and emailed second**. Delivery is not yet proven on
this domain — no provider, no SPF, no DKIM — and a form that only emails loses
every message the moment a send fails, with nobody finding out until a customer
asks why they were ignored. The record lands in **Listings → Inquiries** and
carries `_tdh_notified` = `sent` / `failed` / `no-recipient`, so a failed send is
a bad day rather than a lost customer.

It reuses `tdh_inquiry` rather than adding a post type. An inquiry with no
listing attached has no owner to route to, so the existing capability filter
falls through to `read_private_posts` — administrators only, which is right for a
message addressed to the company. Contact messages are marked
`_tdh_inquiry_kind = contact`.

Spam and abuse: nonce, an off-screen honeypot **answered with the success page**
(telling a bot it was caught only teaches its author to stop filling that field),
and five messages an hour per address. `Reply-To` is the sender so a reply
reaches the person; `From` stays our own authenticated address, because a
visitor's address there would fail SPF and put the one email that must not be
missed into spam.

`do_action( 'tdh_contact_received', $id )` is the seam the Milestone 2
notification pipeline hooks, so adding SMS will not mean reopening this handler.

## 1.6 Release tooling

`tools/build-release.ps1` produces both installable zips, reads versions from
source, and verifies its own output — single root folder, main file present,
nothing leaked from `.git`, and zero entries containing a backslash.

## What exists right now

| | |
|---|---|
| Code | 55 PHP files, ~10,900 lines · 2,930 lines CSS |
| Shortcodes | 14 |
| Post types / statuses | 3 / 6 (3 custom) |
| Seeded pages | 14 |
| Admin screens | Meta boxes · Shortcodes · Import Demo Content · **Payments** |
| Custom tables | `wp_tdh_distances`, `wp_tdh_notifications` |

## Section progress — 9 of 16

| # | Section | Status |
|---|---|---|
| 1 | Local environment + WordPress install | ✅ |
| 2 | Plugin skeleton | ✅ |
| 3 | Theme foundation + approved design port | ✅ |
| 3b | Elementor free + shortcode blocks | ✅ |
| 3c | Design token system | ✅ |
| 4 | Public page structure and copy | 🟡 Copy is placeholder; legal pages need the attorney |
| 5 | Client review persona bar | ✅ |
| 5b | One-click demo importer | ✅ |
| 5c | Accounts: register, sign in, reset, profile, dashboard | ✅ |
| 5d | Payment credentials + go-live gate | ✅ |
| 6 | Listing archive: search, filters, sorting | ⬜ Hero form already puts `q`/`start`/`end` in the URL; the archive ignores them |
| 7 | Single listing: gallery, amenities, fees | ⬜ |
| 8 | Proximity engine | 🟡 Haversine and caching exist; no renter-facing facility control |
| 9 | Landlord front-end submission | ⬜ **No way to create a listing except the admin.** Biggest functional gap |
| 10 | Billing: checkout, webhook, membership lifecycle | ⬜ **Next** |
| 11 | Inquiry pipeline + transactional email | 🟡 Contact form stores and notifies; listing enquiries and a real sending provider are still open |
| 12 | SMS + A2P registration | 🔴 Blocked on the client's EIN |
| 13 | Admin: approval queue, facilities, inquiries | ⬜ |
| 14 | SEO, performance, accessibility pass | ⬜ |
| 15 | Launch, documentation, handover | ⬜ |

---

# 2. Assumptions

Stated because each one is a decision someone could reasonably make differently.
If any is wrong, say so — several are cheap to change now and expensive later.

## Business

| Assumption | If wrong |
|---|---|
| The name is **ThirtyDayHomes**. Three are in circulation — "30 Day Homes" and "WorkStay" appear in the spec and prototype. The domain now purchased points this way. | Touches the logo, Stripe, email authentication, A2P brand registration |
| Plans are **$49 / $88 / $125 USD monthly** for 1 / 2 / 3 listings. Marked unconfirmed on the pricing page. | Stripe Prices must be recreated; restructuring after real members exist is painful |
| **Landlords pay, renters are free.** No renter-side fee anywhere. | Whole checkout flow |
| A plan grants a **listing quota**, and quota is the only thing membership controls. | Membership model |
| **Pittsburgh only** at launch, built to add markets. | Facility seeding, search defaults |
| A listing is **reviewed before publication**; landlords cannot self-publish. | Capability model already enforces this |
| The **exact address is withheld** until the renter makes contact. | Deliberate: a furnished home that is often empty should not have its address published |

## Technical

| Assumption | Note |
|---|---|
| **Stripe Billing direct, no WooCommerce.** Reversed from an earlier decision the same day — see `DEVELOPMENT_PLAN.md` §2.4, where both the reversal and its reasoning are recorded. | Stripe provides renewals, dunning, invoices and the customer portal at no extra cost; the WooCommerce route meant hand-writing all four |
| **A new landlord's quota is 0**, not the marketing figure. | A plan must actively grant capacity. Asserted by a test |
| **Test mode is the safe default** everywhere. A fresh install, a restored backup and a corrupt option all resolve to test. | Nothing should make "take real money" a fallback |
| **Everything free.** Elementor free only; no premium plugin or ongoing licence. | The client declined recurring licence costs |
| **The theme holds no marketplace data.** If it were deleted tomorrow, nothing would be lost. | The rule every placement decision is tested against |
| Legal text is **attorney-supplied**; every placeholder page says so on its face. | |
| Seeded hospital coordinates are **approximate** and must not reach production. | |

---

# 3. Blockers, settings and migrations

## 3.1 Blockers — not workaroundable

| Blocker | Stops | Why it cannot wait |
|---|---|---|
| **Client EIN / business identity** | SMS (§12) | A2P 10DLC registration cannot even be submitted without it. Carrier vetting is 1–3 weeks that nobody controls — the longest pole on the project. Submit now, even though the SMS code is Milestone 2. |
| **Final plan structure and prices** | Billing (§10) | Determines the Stripe Products. Recreating them after real members exist is painful. |
| **Final business name** | Launch | Touches Stripe, email authentication, A2P brand registration, the logo. Cheap to change *until* accounts exist in a name. |
| **Terms, Privacy, Fair Housing text** | §4, §15 | Attorney-supplied. The most common reason a finished site sits unlaunched. |
| **Launch facility list** | §8 | Seeded coordinates are placeholders. |
| **Approved SMS template + consent wording** | §12 | Needs legal guidance on opt-out. |

## 3.2 Settings — required on any new environment

**WordPress**

| Setting | Value | Why |
|---|---|---|
| Settings → General → **Site Title** | `ThirtyDayHomes` | The logo splits the title into white + gold. A title with no capital and no space (`thirtydayhomes.com`) renders entirely white. |
| Settings → Reading → **Discourage search engines** | **On, until launch day** | The site currently serves draft copy and placeholder legal pages. Indexed placeholders outlive the fix. |
| Settings → **Permalinks** | Post name, then **Save once** | Flushes rewrite rules. Skip it and every listing URL 404s. |

**Caching — LiteSpeed is active on the live host**

Purge all caches after any import. `/account/`, `/profile/`, `/login/` and
`/register/` must **never** be cached: a cached copy could serve one landlord's
dashboard to another. Excluding logged-in users covers most of it, but the
exclusions should be explicit rather than trusted to a default.

**Stripe** — Listings → Payments. Sandbox and production are filled in separately.
Production secrets are better placed in `wp-config.php`:

```php
define( 'TDH_STRIPE_LIVE_SECRET',      'sk_live_…' );
define( 'TDH_STRIPE_LIVE_WEBHOOK',     'whsec_…'   );
define( 'TDH_STRIPE_LIVE_PUBLISHABLE', 'pk_live_…' );
```

A constant beats the stored option and the field shows as locked. Webhook endpoint:
`https://<domain>/wp-json/tdh/v1/stripe-webhook` — **the handler does not exist
yet**, so adding it in Stripe now only produces failed deliveries.

**Demo mode** — `TDH_DEMO_MODE` may be on for staging. It must be off in
production, and the review bar refuses to render there regardless.

## 3.3 Migrations

**There are no SQL migration files, and none are needed.**

| Mechanism | Detail |
|---|---|
| Tables | `wp_tdh_distances` and `wp_tdh_notifications`, created by `Activator::install_tables()` on activation. |
| Roles and capabilities | `Activator::register_roles()`. Roles live in the database, so this always rewrites rather than returning early — an early return is how a capability added later never reaches a site where the role already exists. |
| Upgrades | `Core::maybe_upgrade()` compares the stored `tdh_db_version` against `TDH_VERSION` and reinstalls tables and roles when the code is ahead. Activation hooks do not fire on *update*, so this is the only reliable place. |
| Content | Not migrated. Pages, menus and the front-page setting are **created by code** via Tools → Import Demo Content, so there is no database to move between environments and no URL search-and-replace to get wrong. |

> **⚠️ Bump `VERSION` on every release.** Ship a build with the version unchanged
> and a live site keeps the **old capabilities**: the code updates, the roles do
> not, and a landlord silently cannot do something the new code assumes they can.
> The build script refuses to run when the plugin header and the `VERSION`
> constant disagree.

> **⚠️ Do not re-run the demo import on a live site.** It rebuilds both menus from
> scratch and overwrites the seeded pages, including edits made since. Run it once
> at first install. Everything else it leaves alone — listings you created, users,
> roles, memberships, inquiries, theme settings.

---

# 4. How to test

## 4.1 Automated — start here

```powershell
D:\xampp\htdocs\thirtydayhomes\wp-content\plugins\thirtydayhomes-core\tools\verify.bat
```

That runs both suites and finds the binaries for you. **105 assertions**,
self-cleaning, safe on a populated site.

| Suite | Covers | Expect |
|---|---|---|
| `verify.php` | Cross-landlord ownership, listing visibility, membership defaults, proximity maths, account pages out of search | `all 34 checks passed` |
| `verify-contact.php` | The contact form end to end: nonce and honeypot, validation, storage, who can read a message, the notification's headers, rate limiting | `PASSED  71 passed, 0 failed` |

Neither ever sends a real email — `pre_wp_mail` is intercepted throughout.

To run one on its own:

```powershell
cd D:\xampp\htdocs\thirtydayhomes
D:\xampp\php\php.exe D:\xampp\wp-cli.phar eval-file `
  "wp-content/plugins/thirtydayhomes-core/tools/verify-contact.php"
```

Build the release zips, which verify their own output:

```powershell
powershell -ExecutionPolicy Bypass -File tools\build-release.ps1
```

Expect `OK` for both, and `0` entries containing a backslash.

## 4.2 Payments — no Stripe account needed

**Listings → Payments.** Each of these proves a case that costs money if wrong.

| # | Do this | Expect |
|---|---|---|
| 1 | Sandbox tab → Secret key → `sk_live_abc123` → Save | **Red error.** A real-money key must never be accepted in the sandbox |
| 2 | Sandbox → Publishable `pk_test_AAA` → Save. Live tab → Publishable `pk_live_BBB` → Save. Back to Sandbox | Still `pk_test_AAA`. The two sets never overwrite each other |
| 3 | Save a secret, reload, **Ctrl+U**, search the source for it | **0 results.** The field shows `••••••••` |
| 4 | With a secret saved, change only a Price ID and Save | Secret still says *"Saved"* — a blank field must keep, not erase |
| 5 | Select **Live mode** → Save, with live empty | **Refused**, problems listed, still in test mode |

## 4.3 Payments — with Stripe test keys

1. Stripe dashboard in **Test mode** → Developers → API keys → paste `pk_test_…`
   and `sk_test_…` into the Sandbox tab
2. Create three **monthly recurring** products — $49, $88, $125 — and paste each
   `price_…` into its matching slot
3. Press **Test sandbox connection**

Expect: *"Connected to Stripe. Every plan has a Price ID, and each one charges what
the pricing page says, monthly."*

To prove the check is real, swap two Price IDs and press it again — it names both
mismatches and the amounts.

## 4.4 Accounts — by hand

| Do this | Expect |
|---|---|
| `/login/` with a wrong password | Stays on `/login/`, shows *"That email address and password do not match"*, email kept |
| Wrong password 5×, then the correct one | 5th correct attempt signs in; a 6th wrong attempt is blocked |
| A different account from the same computer | Not blocked — the throttle is per account |
| Sign out | Lands on `/login/` saying *"You have been signed out"* |
| `/register/` while signed in | *"You are already signed in"* with a way out |
| `/account/` while signed out | A sign-in wall, not an error |

Password reset on XAMPP: nothing is actually sent. `sendmail_path` is a Unix-only
setting, so on Windows PHP falls back to `SMTP=localhost:25`, nothing is listening
there, and `wp_mail()` simply returns false. `TDH\Mail` therefore **captures**
outside production and writes each message to a file instead. Request a reset,
then open the newest file in:

```
%TEMP%\thirtydayhomes-mail\
```

The link is inside. Those files are in the system temp directory and **not** under
`wp-content` on purpose — they carry live one-time reset tokens, and anything
under `wp-content` is reachable over HTTP.

## 4.4b Contact form — by hand

Go to `/contact/`.

| Do this | Expect |
|---|---|
| Submit with everything blank | Three errors listed above the form |
| Type a broken address (`dana@`) and submit | It is refused — and the broken address is **still in the field** to correct, not blanked |
| Send a real message | Green notice; the record appears under **Listings → Inquiries** |
| Open that record | Name, email, phone, topic in the meta; `_tdh_notified` says `sent` |
| Check `%TEMP%\thirtydayhomes-mail\` | A notification to the site admin, `Reply-To` the sender, `From` **not** the sender |
| Send six messages in a row | The sixth is asked to wait |
| Sign in as a landlord and open Inquiries | The contact message is **not** listed |
| Narrow the browser below ~990px | The navy half stacks above the form; the gold seam moves from the side to the join |

The whole of the above is also automated — see §4.1.

## 4.5 Pages — by eye

Hard-refresh (**Ctrl+Shift+R**) or a cached page will mislead you.

`/` · `/about/` · `/pricing/` · `/how-it-works/` · `/homes/` · `/contact/` ·
`/terms/` · `/privacy/` · `/fair-housing/`

Check on each: one `<h1>`, breadcrumb present, banner correct, and the footer
intact. On `/pricing/` specifically: plans ascend **$49 → $88 → $125**, discounts
read 10% then 15%, and the `$` does not collide with the first digit.

**Known issue:** between roughly 700px and 1000px the header nav collides with the
logo, reading "ThirtyDayHomesAbout". Pre-existing, every page, not yet fixed.

## 4.6 Deploying to a new environment

1. Build the zips → `dist/`
2. Appearance → Themes → Upload → activate **first** (the importer sets a page
   template belonging to the theme)
3. Plugins → Upload → activate
4. Install Elementor (free)
5. Settings → Permalinks → **Save**
6. Tools → **Import Demo Content**, all three steps
7. Purge the cache
8. Apply the settings in §3.2
9. Walk §4.5

For an update, upload the new zip and choose **"Replace current with uploaded"**.
Do **not** re-run the import.

---

## Known debt

Carried deliberately, tracked so it is not forgotten.

| Item | Section |
|---|---|
| Header nav collides with the logo between ~700–1000px | §14 |
| Google Fonts loaded from Google; self-hosting removes a third-party request and the GDPR question | §14 |
| Seeded hospital coordinates are approximate placeholders | §8 |
| Stock photography is licensed but is not the client's own | §14 |
| Elementor 4.x is opted into Editor V4; our widgets use the classic `Widget_Base` API. Works today, would need porting if the classic path is dropped | watch |
| Saving on the Payments screen reloads the page. Correct and safe (POST-then-redirect), but could be made asynchronous | — accepted |
| No focus ring on the two Payments mode tabs, at the client's explicit request. The active-tab styling still conveys state | — accepted |

## Standards in force

| | |
|---|---|
| **Design tokens** | `themes/thirtydayhomes/assets/design-tokens.css`. Components draw exclusively from tokens. Type and control values are deliberately **off** the 4px scale — that scale governs distance between blocks, not space inside a run of text. See `DESIGN-SYSTEM.md`. |
| **No cross-section conflict** | Every selector is scoped so one section or page can never affect another. Child combinators, never bare descendant `span` / `small` / `b`. |
| **Images** | WebP. Hero 1920px q58 under 100 KB. Listing masters 1400px q68. Re-run `tools/optimise-images.php` after adding any asset. |
| **Blocks** | Shortcode is the primitive; the Elementor widget is a thin wrapper over the same `TDH\Render` method. Never two implementations. |
| **Lookups** | Never find a seeded record by slug — a pending post has no slug, and custom statuses are excluded from `post_status => 'any'`. Use `_tdh_seed_key`. |
| **Section headings** | Add the selector to the shared rhythm rule in `style.css`. Never restyle an `h2` locally. |
| **Secrets** | Never in the repository. Constants in `wp-config.php` beat stored options, and no secret is ever rendered back into a page. |
