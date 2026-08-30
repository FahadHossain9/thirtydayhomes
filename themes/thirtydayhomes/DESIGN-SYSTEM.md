# ThirtyDayHomes — Design System

All tokens live in **`assets/design-tokens.css`**. That file is the single source of truth for colour, type, spacing, layout and motion.

## The rule

> Component and section styles draw **exclusively** from tokens. No hardcoded font sizes, no one-off margins, no stray hex values inside a section class.

If a component needs a value the tokens don't have, **add the token first**. That is the moment to decide whether it's a genuinely new step in the system or a rounding error about to be baked in permanently.

## Load order

```
1. elementor/assets/css/frontend.css   (Elementor, when active)
2. assets/design-tokens.css            (tdh-tokens)
3. style.css                           (tdh-theme)
```

Two things depend on this order:

- **Tokens before the stylesheet.** `style.css` is almost entirely `var()` references; loaded alone it renders nothing.
- **Both after Elementor.** Elementor ships `.elementor img { height: auto }`, which is the same specificity `(0,0,1,1)` as our `.split-photo > img`. Equal specificity is decided by source order. Declared as a dependency, so the theme wins without `!important`.

The Elementor **editor** loads both too (`Registrar::editor_styles`), otherwise widgets render unstyled in the panel while looking correct on the front end.

## Usage

### Typography

```css
/* Never a raw size */
.my-heading { font-size: var( --text-h2 ); }
.my-body    { font-size: var( --text-base ); line-height: var( --leading-body ); }
```

The scale is fluid — `clamp()`, not breakpoints — so a heading grows continuously and never lurches one pixel past 700px.

| Token | Range | Use |
|---|---|---|
| `--text-display` | 44 → 70 | Hero only |
| `--text-h1` | 32 → 47 | Page title |
| `--text-h2` | 32 → 43 | Section heading |
| `--text-h3` | 21 → 32 | Card title |
| `--text-lg` | 17 | Lead paragraph |
| `--text-base` | 16 | Body |
| `--text-sm` → `--text-3xs` | 13 → 10 | Dense UI, meta, pills |

Line height is a role, not a number: `--leading-display` (1.02) for the hero, `--leading-tight` (1.3) for h1/h2, `--leading-body` (1.6) for running text. **Body copy and display type want very different values** — a single global line-height cannot serve both, and setting one on `body` without overriding headings is what produced 89px line boxes earlier in this build.

### Spacing

A 4px scale, `--space-1` (4px) through `--space-12` (96px). Use a step, never a raw pixel.

```css
.my-section { padding: var( --section-gap ) var( --gutter ); }
.my-stack   { gap: var( --space-4 ); }
```

`--section-gap` and `--gutter` are fluid, so there is **no mobile padding override to keep in sync**.

### Section heading rhythm

Every section heading uses one shared rule in `style.css`:

```css
.section-title h2,
.audience-head h2,
.split h2,
.owner-cta h2 { font-size: var( --text-h2 ); margin: var( --section-heading-gap ) 0; }
```

The eyebrow above adds nothing, the copy below adds nothing, and the heading's top and bottom margins are equal — so the gap above and below is the same measurement in every section.

**Adding a section? Add its selector to that rule.** Do not restyle its `h2` locally. A local override wins today and starts a cascade fight the next time either rule is edited.

### Colour

Use the **role** token, not the raw brand colour, wherever a role exists:

```css
color: var( --text-muted );        /* not var( --color-ink-muted ) */
background: var( --bg-surface );   /* not var( --color-white ) */
border: 1px solid var( --border-default );
```

Roles change independently of brand values. `--color-ink` and `--color-navy` are the same hex today; they are named separately because one is text and one is a brand colour, and one day only one of them moves.

**On gold:** `--color-gold` is for fills. For gold **text on a light ground** use `--color-gold-deep` — it is the only one that passes contrast.

Semantic colours (`--color-success`, `--color-danger`, `--color-warning`) are deliberately separate from the brand accent. A warning must not be gold just because gold is the brand.

### Layout & components

```css
height: var( --split-photo-height );   /* 560px */
border-radius: var( --radius-lg );
box-shadow: var( --shadow-md );
transition: transform var( --duration-base ) var( --ease );
```

Fixed heights are tokens so a change is one edit: `--card-image-height`, `--icon-well`, `--header-height`, `--sidebar-width`.

## What is still a raw pixel, and why

An audit of `style.css` finds **0 hex colours, 0 rem literals, 400 `var()` references**, and these remaining `px`:

| Value | Where | Why it stays |
|---|---|---|
| `1px` | borders | A hairline is a hairline. Tokenising it implies it might change. |
| `2px` | focus outline offset | Ditto. |
| `-4px` | card hover lift | Part of the motion, not the spacing system. |
| `1px` / `-1px` / `-9999px` | `.screen-reader-text`, `.skip-link` | Standard accessibility clip values. |
| `700px`, `950px`, `1050px` | media queries | **CSS cannot use custom properties in media query conditions.** This is a language limitation, not a choice. |

That last one is worth knowing before someone tries to fix it: `@media (max-width: var(--bp))` is invalid CSS. Breakpoints are declared once in the responsive block at the bottom of `style.css` and nowhere else.

## Adding a component

1. Check the tokens for what you need.
2. Missing? Add the token to `design-tokens.css`, in the right group, with a comment saying what it's for.
3. Write the component using only `var()`.
4. If it's a section heading, add its selector to the shared rhythm rule.
5. Grep your new CSS for `#`, raw `px` and raw `rem` before committing.
