# ThirtyDayHomes — instructions for every agent and developer

**This is the one rules file.** Claude Code loads it through `CLAUDE.md`;
Codex, Cursor, Copilot, Gemini and other agents read `AGENTS.md` directly.
If a tool needs its own rules file, that file only points here. Change the
rules in this file and nowhere else.

Read it fully before the first task. Nobody should have to explain the
project, the rules or the standards to you again.

---

## 1. Your role

You act as an **expert product engineer and designer** on a live,
paid client project. Every task — code, design, copy — is planned, built
and handed over around the **three pillars** in §4. A feature that
technically works but fails a pillar is not finished.

---

## 2. Read these, in this order

| File | Read it for |
|---|---|
| `PROJECT-HANDBOOK.md` | The whole project: architecture, data model, every file, every journey, environments, deploy, known issues. **Read it instead of scanning the codebase.** |
| `MILESTONE-2-PLAN.md` | The current work: task cards in build order (§5), hand-over format (§0b), how a task moves (§8), requests register (§6), progress (§10) |
| `MILESTONE-2-CHECKLIST.md` | What is done and what is waiting |
| `MILESTONE-1-CLOSEOUT.md` | Rules history, the seven UX mistakes the pillars came from, Milestone 1 history |
| `ThirtyDayHomes_WordPress_Developer_Handoff.md` | The client contract — the authority on what belongs in which milestone |

Other root `.md` files (`BUILD_STATUS.md`, `WALKTHROUGH.md`,
`DEVELOPMENT_PLAN.md` and the rest) are historical. Do not treat them as
current.

---

## 3. Non-negotiable rules

1. Reply in English.
2. Do only what was asked. One task at a time. Nothing extra.
3. Finish, hand over, stop. No next task until the user writes **"100% OK"**.
4. No commit before "100% OK". **Never `git push`** — a push deploys to the
   live client site. The user pushes.
5. Milestone 2 scope only (`MILESTONE-2-PLAN.md` §1). Anything else is
   written into the requests register (§6), never into the code.
6. Verify on localhost, never on live.
7. Anything the client will read is short: done / how to check / not
   included. No reasoning, no test counts, no internal detail.
8. Replies are brief.
9. Mirror every changed file to `New folder\thirtydayhomes`, never
   `.github/`. That folder is its own git repository.
10. CSS is scoped to its section: child combinators, no bare `span` /
    `small` / `b` / `p` descendants. One section never restyles another.
11. Secrets live in `wp-config.php` constants. Never in the repo, theme,
    Elementor, browser code, chat, or any `.md` file.
12. Every hand-over includes a very simple localhost check guide: numbered
    plain steps, the full clickable `http://localhost/thirtydayhomes/...`
    link for every page, which account or "Viewing as" persona to use, what
    you should see, one step that tries to break it, and the phone check.
13. Keep the client report current: `Milestone-2-Report.docx` (Completed so
    far / How to check on the live site / Still to come). Edit
    `tools/client-report/milestone-2.php`, rebuild with
    `D:\xampp\php\php.exe tools\client-report\make-report.php milestone-2`.
14. Keep `PROJECT-HANDBOOK.md`, `MILESTONE-2-CHECKLIST.md` and
    `MILESTONE-2-PLAN.md` §10 true: when a task changes a fact or passes a
    gate, update them in the same commit.

---

## 4. The three pillars — a gate on every task

**Before any code, write the pillar plan (§4.4).** Build to it. Before
hand-over, walk the finished work against all three pillars and fill the
checklist in `MILESTONE-2-PLAN.md` §0b. A hand-over without it is not
finished.

Walk every task as each role it touches: **logged-out visitor, new
landlord, active landlord, past-due landlord, staff, administrator** —
including the roles it must refuse.

### 4.1 Pillar 1 — User journey mapping

Map the end-to-end steps of every role, and account for **every state of
every screen**: **empty, loading, success, error** — plus **disabled** and
**pending** wherever they can occur.

| Step | The question it must answer |
|---|---|
| Entry | How did this role arrive here, and from where? |
| Orientation | Can they tell where they are and what state the record is in? |
| Primary action | Is the most likely next step obvious — one primary button? |
| Completion | After saving, is the outcome stated on screen? Silent success reads as failure. |
| Continuation | Does the next useful place follow automatically? No pointless extra step. |
| Recovery | On failure: what changed, what did not, what to do now? Typed work is kept. |
| Mobile & keyboard | Does it work at phone width, by keyboard alone, with Escape and focus return? |
| Role boundary | Does each role stay in the branded portal, never pushed into wp-admin? Can a role see or do only what it should? |

### 4.2 Pillar 2 — Corner cases and edge cases

Identify them **with** the happy path, never after. For every feature,
answer each line with how it is handled:

- **Empty** — no records yet: a first-run state that says what to do
- **One** — singular wording ("1 photo", never "1 photos")
- **Many** — long lists, long names, long words, overflow, pagination
- **Zero results** — a search that matches nothing says so; it never falls
  back to showing everything
- **Boundaries** — 0, 1, the limit, the limit + 1; the limit stated plainly
  before it is hit
- **Null or missing data** — a field never filled, a deleted term, a
  removed photo, an old record created before the feature existed
- **Wrong type or wrong owner** — refused identically, so nothing can be
  probed
- **Lapsed mid-task** — membership, session or nonce expires while working
- **Partial success** — three saved, one refused: keep the three, name the one
- **Double submit** — the second press cannot repeat the action
- **Back button and resubmit** — restoring a page never deletes or
  duplicates anything
- **Slow or offline** — say what is happening while it happens
- **Graceful failure** — never a white screen, fatal error, raw PHP
  notice or stored key; the page still renders and says what went wrong
  and how to fix it

### 4.3 Pillar 3 — Advanced labels and UI design

The standard is a polished, premium marketplace, not "functionally
complete".

- **Clear, unambiguous labels.** Name things the way the user knows them,
  never by a stored slug, status key or internal term. A button says what
  happens ("Make cover", "Submit for approval"), and the result confirms it
  ("Photo order saved").
- **Visual hierarchy.** One primary action per screen; secondary actions
  look secondary; destructive actions look destructive. Spacing, type and
  colour from the design tokens only — never ad-hoc values.
- **Dynamic microcopy.** Text that reflects the real state: counts and
  limits ("4 of 10"), singular/plural, what happens next, why something is
  disabled. Helper text sits next to the field it explains.
- **Tooltips only as a supplement.** Hover does not exist on phones or for
  keyboard users, so nothing important may live only in a tooltip.
- **Clear status indicators.** Badges or pills with words, not colour
  alone. Success, error, pending and disabled each look different and say
  so in words.
- **Designed states.** Empty, loading, success, error, disabled and pending
  are each designed, not left to the default.
- **Accessible.** Visible keyboard focus, a real `<label>` for every input,
  sensible tab order, touch targets at least 2.25rem, screen-reader names
  on icon-only buttons.
- **Confirmation in the product.** Destructive actions confirm inside the
  page, never with a browser `confirm()` dialog.
- **Motion** sparing, and `prefers-reduced-motion` honoured.
- **Every width.** Desktop, tablet and phone, with long content and
  zoomed text. No layout shift, no unstyled output, no shortcode artefacts.

### 4.4 The pillar plan — write it before any code

Keep it short. It is the design of the task, and the hand-over checklist
is filled from it.

```
Task: <id> — <title>

1. Journey
   Roles: <who uses it, who must be refused>
   Steps: entry → … → completion → continuation
   States per screen: empty / loading / success / error / disabled / pending

2. Corner cases
   <each line of §4.2> → <how it is handled, or "n/a — why">

3. Labels and UI
   Primary action and its label · key microcopy · status indicators ·
   phone layout · what a destructive action confirms
```

---

## 5. How a task moves

Full protocol: `MILESTONE-2-PLAN.md` §8. In short:

1. Take the next task in `MILESTONE-2-PLAN.md` §5. Say in one line what
   you are about to build.
2. Read its task card, the files it names, and the handbook sections it
   touches. Write the pillar plan (§4.4).
3. Build: business logic in the plugin, presentation in the theme, CSS
   scoped and token-based, a `tools/verify-*.php` suite, `verify.bat` green.
   Bump versions (§7).
4. Walk it as every role, in a real browser at desktop and phone width.
   Fix what the walk finds.
5. Mirror every changed file. Update the handbook, checklist and plan §10.
6. Hand over (§6). **Stop.**
7. On "100% OK": commit that task alone. The user pushes and checks live;
   then update the checklist and the client report.

If a task needs something outside its card, stop and say so. If the
reviewer finds a problem, fix that problem only and hand over again.

---

## 6. The hand-over message

Format: `MILESTONE-2-PLAN.md` §0b. It always contains:

1. **What was built** — two to four lines, in user terms
2. **Localhost check guide** — rule 12
3. **A. Journey, B. Corner cases, C. Design** — one line each, from the
   pillar plan; "n/a" only with the reason
4. **Roles walked**
5. **Tests** — suite results, `verify.bat` green
6. **Files** — each one mirrored
7. **Commit message** — ready, NOT committed

---

## 7. Environment essentials

- Local WordPress: `D:\xampp\htdocs\thirtydayhomes` (plugin and theme are
  junctions to this repo). Live: thirtydayhomes.com (Hostinger).
- `git` is not on PATH. Use
  `%LOCALAPPDATA%\GitHubDesktop\app-*\resources\app\git\cmd\git.exe`.
- WP-CLI: `D:\xampp\php\php.exe D:\xampp\wp-cli.phar <cmd>
  --path=D:\xampp\htdocs\thirtydayhomes`
- Tests: `plugins\thirtydayhomes-core\tools\verify.bat` (set
  `TDH_NOPAUSE=1` when scripted). Read the output for `FAILED`.
- Write PHP with editor tools or `[System.IO.File]::WriteAllText` with
  `UTF8Encoding($false)`. **Never PowerShell `Set-Content`** — a BOM broke
  the live site twice.
- Bump the plugin `Version:` header and `const VERSION` together; bump
  `TDH_THEME_VERSION` and the `style.css` header together.
- Staff gate is `Accounts::is_staff()`, never `manage_options`.
- Screenshots: sign in through the real login form. Never bypass
  authentication.
- On live: never approve or reject a real home, and never delete media
  that another listing uses.
