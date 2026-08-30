# Build Status

How we work, and where the build actually is.

**Last updated:** 27 August 2026

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

That fifth point keeps earning its place. Testing rather than assuming has caught, so far: a visibility rule silently filtering WP-CLI queries; a duplicate auth cookie that worked in browsers and failed stricter clients; a seeder creating duplicate listings on every run; an Elementor autosave shadowing published data; and a stylesheet load order stripping the height off every art-directed image.

If something in a section turns out blocked, I finish everything else in it and say plainly what is left. Nothing is quietly carried forward — that is how a build ends up 80% done everywhere and finished nowhere.

---

## Progress

**7 of 16 sections complete.** The renter-facing site exists and works. The marketplace behind it does not yet — nobody can register, publish a listing, search, or send an inquiry.

| # | Section | Status | Notes |
|---|---|---|---|
| 1 | Local environment + WordPress install | ✅ Done | WP 7.1, PHP 8.2, own DB, repo junctioned into XAMPP |
| 2 | Plugin skeleton — content model, statuses, roles, fields, visibility rule | ✅ Done | 3 post types, 4 taxonomies, 3 custom statuses, 1 role, 2 tables |
| 3 | Theme foundation + approved design port | ✅ Done | Navy header, hero, audience, cards, split feature, footer |
| 3b | Elementor free + shortcode blocks | ✅ Done | 5 shortcodes, 5 widget wrappers, one renderer underneath |
| 3c | Design token system | ✅ Done | 0 hex colours, 0 raw sizes, 400 `var()` references |
| 4 | Public page structure and copy | 🟡 Partial | Pages, menus, templates exist. **Copy is placeholder; legal pages need the attorney.** |
| 5 | Client review persona bar | ✅ Done | Gated four ways, restricted demo admin, admin-bar switcher |
| 5b | One-click demo importer | ✅ Done | Tools → Import Demo Content. Verified from an empty database, idempotent across 3 runs |
| 6 | Listing archive: search, filters, sorting | ⬜ **Next** | Hero form already puts `q`/`start`/`end` in the URL; the archive ignores them and says so |
| 7 | Single listing: gallery, amenities, fees | ⬜ Not started | Template exists; gallery and amenity rendering incomplete |
| 8 | Proximity engine (`tdh-proximity`) | ⬜ Not started | Seeded hospital coordinates make this testable immediately |
| 9 | Landlord front-end submission + dashboard | ⬜ Not started | Admin meta boxes are currently the only way to create a listing |
| 10 | Membership + Stripe | 🔴 Blocked | Needs the pricing model and the PMPro vs WooCommerce decision |
| 11 | Inquiry pipeline + transactional email | ⬜ Not started | |
| 12 | SMS notifications + A2P registration | 🔴 Blocked | Needs the client's EIN to even submit registration |
| 13 | Admin: approval queue, facilities, inquiries | ⬜ Not started | |
| 14 | SEO, performance, accessibility pass | ⬜ Not started | |
| 15 | Launch, documentation, handover | ⬜ Not started | |

### What exists right now

| | |
|---|---|
| Code | 45 PHP files, ~6,100 lines |
| Shortcodes | `[tdh_hero_search]` `[tdh_audience]` `[tdh_property_grid]` `[tdh_split_feature]` `[tdh_owner_cta]` |
| Elementor widgets | 5, thin wrappers over the same renderer |
| Admin screens | Meta boxes · Shortcodes reference · Import Demo Content |
| Seeded content | 8 pages, 4 listings, 5 facilities, 2 menus |
| Homepage weight | 231 KB |

---

## Blockers

Not things I can work around. Each stops a specific section.

| Blocker | Stops | Why it cannot wait |
|---|---|---|
| **Membership pricing model** — one tier up to 3 listings, or per-listing with volume discounts? | §10 | Determines how many membership levels exist and how the allowance is enforced. Restructuring Stripe products after real members exist is painful. |
| **PMPro vs WooCommerce Subscriptions** | §10 | Migrating membership plugins after launch is worse than choosing now. Recommend PMPro. |
| **Client EIN / business identity** | §12 | A2P 10DLC registration cannot even be submitted without it, and carrier vetting is the longest lead time on the project — 1–3 weeks nobody controls. |
| **Final name and domain** | §14, §15 | Touches hosting, Stripe, email authentication, A2P brand registration, the logo. Cheap to change *until* accounts are created in a name. |
| **Terms, Privacy, Fair Housing text** | §4, §15 | Attorney-supplied. The most common reason a finished site sits unlaunched. Request in week 1 regardless. |
| **Launch facility list** | §8 | Currently seeded with approximate coordinates that must not reach production. |
| **Approved SMS template + consent wording** | §12 | Needed before the SMS build, with legal guidance on opt-out. |

---

## Known debt

Carried deliberately, tracked so it is not forgotten.

| Item | Section |
|---|---|
| ~~Hero photo hotlinked~~ · ~~589 KB brand mark~~ · ~~Elementor not installed~~ | ✅ Resolved |
| Google Fonts loaded from Google; self-hosting removes a third-party request and the GDPR question | §14 |
| Seeded hospital coordinates are approximate placeholders | §8 |
| Stock photography is licensed but is not the client's own — they should supply or approve final imagery | §14 |
| Snapping to the 4px spacing scale shifted a few prototype values by 1–3px | — accepted |
| Elementor 4.x is opted into Editor V4; our widgets use the classic `Widget_Base` API. Works today, would need porting if the classic path is ever dropped | watch |

---

## Standards in force

| | |
|---|---|
| **Design tokens** | `themes/thirtydayhomes/assets/design-tokens.css`. Components draw exclusively from tokens — no hardcoded sizes, margins or hex values. See `DESIGN-SYSTEM.md`. |
| **Images** | WebP. Hero 1920px q58 under 100 KB. Listing masters 1400px q68. Logos 3× display size. Re-run `tools/optimise-images.php` after adding any asset. |
| **Blocks** | Shortcode is the primitive; the Elementor widget is a thin wrapper over the same `TDH\Render` method. Never two implementations. |
| **Lookups** | Never find a seeded record by slug — a pending post has no slug, and custom statuses are excluded from `post_status => 'any'`. Use `_tdh_seed_key`. |
| **Section headings** | Add the selector to the shared rhythm rule in `style.css`. Never restyle an `h2` locally. |

---

## Deploy order

Nothing reaches production until the section it belongs to is closed.

1. **Local** — build and verify.
2. **Commit** — one commit per closed section, so a section can be reverted on its own.
3. **Staging** — verify on real hosting. `TDH_DEMO_MODE` may be on here. Run Tools → Import Demo Content to populate.
4. **Production** — `TDH_DEMO_MODE` must be off, and the review bar refuses to render there regardless.
