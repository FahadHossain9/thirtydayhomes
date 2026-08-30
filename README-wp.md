# ThirtyDayHomes — WordPress source

The two pieces of WordPress code we own. Core, third-party plugins and uploads are deliberately not in this repository — they are installed per environment and are not our source.

```
plugins/thirtydayhomes-core/   ← all marketplace data and behaviour
themes/thirtydayhomes/         ← all presentation, no business rules
```

Layout matches `owambeconnect`: `plugins/` and `themes/` sit at the repository root so each can be zipped directly for release.

## The architectural rule

> If the theme were deleted and replaced tomorrow, would any listing, membership, inquiry, facility or relationship be lost?

If yes, the code is in the wrong place. Listings, statuses, fields, roles, membership rules, query logic, proximity, notifications and **shortcodes** live in the plugin. The theme owns design tokens, the document shell, header, footer, navigation and templates.

This is required by the handoff document, and it is what lets the client change how the site looks without risking what the site knows.

## Local setup (XAMPP)

The working site lives in XAMPP; this repo is junctioned into it, so editing a file here changes the running site immediately.

| | |
|---|---|
| Site root | `D:\xampp\htdocs\thirtydayhomes` |
| URL | http://localhost/thirtydayhomes |
| Database | `thirtydayhomes` (user `root`, no password) |
| WordPress | 7.1 · PHP 8.2.12 |
| Elementor | free 4.2.3 — **no Pro, no add-ons** |

### Recreating the junctions

```powershell
$repo = "<path-to-this-repo>"
$wpc  = "D:\xampp\htdocs\thirtydayhomes\wp-content"

New-Item -ItemType Junction -Path "$wpc\themes\thirtydayhomes"       -Target "$repo\themes\thirtydayhomes"
New-Item -ItemType Junction -Path "$wpc\plugins\thirtydayhomes-core" -Target "$repo\plugins\thirtydayhomes-core"
```

Junctions rather than symlinks: no administrator rights and no Developer Mode required.

## Why Elementor free, and no Pro

Pro's value is its Theme Builder — header, footer, archive and single templates. The handoff document assigns every one of those to the **theme**, and the dynamic blocks to the **plugin**. Milestone 1 asks for Elementor "without unnecessary add-ons". So Pro would be paid for a capability the architecture places elsewhere. Annual licences: **£0**.

The trade-off, stated plainly: the listing archive and single-listing layouts are theme-rendered and change through us. Everything the client will realistically want to edit — headings, copy, calls to action, imagery, marketing sections, which listings a block shows — is editable without a developer.

## Shortcodes, not widgets

Every dynamic block is a **shortcode**. `TDH\Render` holds one implementation; the shortcode is the only entry point.

```
[tdh_property_grid count="3" columns="3" heading="Homes ready when you are"]
[tdh_hero_search heading="Stay a while." accent="Feel at home."]
```

Full reference with every attribute: **Listings → Shortcodes** in wp-admin.

Shortcodes work in Elementor, Gutenberg, the classic editor, a widget area, or a template via `do_shortcode()` — and they keep working if Elementor is ever deactivated. A widget-only approach would lock the client's content inside Elementor's own JSON, which handoff §2 forbids.

**Static marketing content has no shortcode on purpose.** Audience cards, calls to action and FAQs are text and icons, not marketplace data. They are built with Elementor's own widgets using the theme's CSS classes (`audience`, `owner-cta`, `section`, `overline gold`, `gold-btn`). Wrapping static copy in a custom block would make the client depend on a developer to change a paragraph.

## Theme layout

`functions.php` is a thin bootstrap. All feature logic lives in `inc/`:

| File | Owns |
|---|---|
| `inc/setup.php` | theme supports, menus, image sizes |
| `inc/brand.php` | wordmark, logo, hero photograph |
| `inc/assets.php` | styles, scripts, resource hints, hero preload |
| `inc/customizer.php` | client-editable settings |
| `inc/elementor.php` | page templates, Elementor helpers |
| `inc/icons.php` | inline Lucide icon set |
| `inc/security.php` | hardening |
| `elementor/tpl-*.php` | Full Width and Canvas page templates |

## What the plugin creates

Activating registers three post types, four taxonomies, three custom listing statuses, one role, and two tables. **Deactivating removes nothing.** Uninstalling removes nothing either, unless `TDH_REMOVE_ALL_DATA_ON_UNINSTALL` is defined true in `wp-config.php` — deleting a plugin is two clicks from deactivating one, and the difference between them should never be a client's entire marketplace.

| | |
|---|---|
| Post types | `tdh_listing`, `tdh_facility`, `tdh_inquiry` |
| Taxonomies | `tdh_property_type`, `tdh_neighborhood`, `tdh_amenity`, `tdh_city` |
| Statuses | `tdh_paused`, `tdh_rejected`, `tdh_billing_hold` + core draft / pending / publish |
| Role | `tdh_landlord` |
| Tables | `wp_tdh_distances`, `wp_tdh_notifications` |

### Three decisions worth understanding before changing anything

**`tdh_billing_hold` is not `tdh_paused`.** To a renter both are simply "not there". On renewal they must behave completely differently: only billing holds are restored. Without the distinction, a landlord who paused one of their three homes last week finds it silently republished the moment their card is fixed.

**The street address is private.** A furnished rental that sits empty, with its address published, is an easy target. Three guards, and the third is a release blocker: fields registered `show_in_rest => false`; never placed on a public template, *not even hidden* (Elementor renders hidden elements into the DOM); and QA checks the REST endpoint and page source before launch.

**Never look up a listing by slug.** A pending or draft post has no `post_name` until first published, and custom statuses are excluded from `post_status => 'any'`. The seeder learned this the hard way by duplicating every pending listing on each run. Use the meta keys we control.

## Tools

```powershell
cd D:\xampp\htdocs\thirtydayhomes

# Pages, menus, front page, sample content
php D:\xampp\wp-cli.phar eval-file wp-content/plugins/thirtydayhomes-core/tools/setup-site-structure.php

# Facilities, listings, photographs — idempotent
php D:\xampp\wp-cli.phar eval-file wp-content/plugins/thirtydayhomes-core/tools/seed-dev-data.php

# Recompress images after adding any asset
php wp-content/plugins/thirtydayhomes-core/tools/optimise-images.php
```

## Image standard

| | |
|---|---|
| Format | WebP (GD here also has AVIF) |
| Hero | 1920px, q58, under 100 KB — it is the LCP element, served directly |
| Listing masters | 1400px, q68 — WordPress serves the generated crops |
| Logo / marks | 3× display size, q88 |

Homepage weight: **231 KB**.

## Schema changes

Activation hooks do not fire on plugin *update*. Bump `VERSION` in `thirtydayhomes-core.php` and `Core::maybe_upgrade()` runs `install_tables()` and `register_roles()` on the next admin request. Adding a capability to `Roles::install()` is enough to roll it out — no manual database work, which the handoff document forbids.

## Not built yet

- Membership and the subscription state machine (`tdh_inactive_member_ids` is the filter it hooks)
- Front-end listing submission and the landlord dashboard
- Search, filters and sorting
- Proximity: geocoding, distance matrix, facility-relative sort
- Inquiry pipeline: storage, queued email, queued SMS with A2P consent and delivery logging
- Admin approval queue, facility manager, inquiry overview

See `BUILD_STATUS.md` for progress and blockers, `DEVELOPMENT_PLAN.md` for the full plan.
