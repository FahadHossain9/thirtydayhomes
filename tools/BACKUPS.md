# Backups and the security baseline

Two things live here, because they answer the same question: **if something
goes wrong, how bad is it, and how do we know?**

- `backup.sh` — nightly database + uploads, on the server
- `restore.sh` — putting it back, written down before it is needed
- `plugins/thirtydayhomes-core/tools/baseline.php` — the security check

---

## 1. Installing the backup

On the server, over SSH.

```bash
mkdir -p ~/backups/thirtydayhomes
bash ~/repos/thirtydayhomes-main/tools/backup.sh
```

Run it by hand once first. It prints what it wrote and how big it was, and it
verifies both archives read back before it finishes.

Then add the cron entry (`crontab -e`):

```cron
17 3 * * * bash $HOME/repos/thirtydayhomes-main/tools/backup.sh >> $HOME/backups/backup.log 2>&1
```

**03:17, not 03:00.** Every shared host runs its own maintenance on the hour;
landing in the middle of it is how a dump gets truncated. The odd minute is
the whole trick.

Check it took a week later:

```bash
ls -lh ~/backups/thirtydayhomes/
tail -30 ~/backups/backup.log
```

---

## 2. What is covered, and what is not

| | Where it lives | In the backup |
|---|---|---|
| Database — listings, members, inquiries, settings, Stripe ids | **only the server** | ✅ |
| `wp-config.php` — salts, DB credentials | **only the server** | ✅ |
| Listing photography (`uploads/`) | **only the server** | ✅ originals |
| Generated thumbnail sizes | derived | ❌ — `wp media regenerate` |
| Our theme and plugin | **git** | ❌ by design |
| WordPress core, other plugins | wordpress.org | ❌ |

The rule: back up what exists in exactly one place. Backing up code that is
already in git creates a second, staler source of truth for someone to restore
from by mistake.

### The limit, stated plainly

These files sit **on the same server as the site**. That covers a bad deploy, a
bad update, a mistaken deletion and a corrupted table — the four things that
actually happen. It does **not** cover losing the server, the account being
suspended, or the host having a bad week.

Copying them off-server is the remaining gap. It needs a destination the client
owns (their Google Drive, an S3 bucket, a laptop) and is a decision about who
holds a copy of every member's personal data — so it is Rob's call, not ours.
**Raise it before launch.** Until then, Hostinger's own panel backups are the
only off-server copy, and nobody has verified their schedule or tested one.

---

## 3. Restoring

```bash
bash ~/repos/thirtydayhomes-main/tools/restore.sh ~/backups/thirtydayhomes/20260831-031700
```

Without `--yes` it prints exactly what it would do and changes nothing. That is
the intended first run, every time.

It verifies the archives **before** touching the live database, and takes a
safety copy of the current one first — because the second most common restore
disaster is restoring the wrong backup over a database that was fine.

### Rehearse it

An untested backup is a rumour. Milestone 3 requires a demonstrated restore;
do it early, on a copy, not on the live site:

1. Make a staging site or a local install
2. Copy a backup directory to it
3. `WP_DIR=/path/to/staging bash restore.sh <backup> --yes`
4. Walk the pages. Sign in. Open a listing. Open an inquiry.

Write down how long it took. That number is the honest answer to "how long
would we be down", and it is always longer than people guess.

---

## 4. The security baseline

```bash
wp eval-file wp-content/plugins/thirtydayhomes-core/tools/baseline.php
```

Read-only. It changes nothing and sends nothing.

Every check declares which environments it applies to, and prints as skipped
elsewhere with the reason. So a **FAIL always means something** — which is the
only way anyone keeps reading a report like this. A local run is not evidence
about production; run it on the server too.

Exits non-zero if anything failed, so it can go in CI later.

### Run it

- Before launch, on the server
- After any host, PHP or WordPress version change
- After adding an administrator
- After a restore

### What it will say on the live site until it is set up

These are the wp-config lines production needs. They are **not** in the
repository, deliberately — secrets and environment settings do not belong in
version control:

```php
define( 'WP_ENVIRONMENT_TYPE', 'production' );
define( 'DISALLOW_FILE_EDIT',  true );
define( 'FORCE_SSL_ADMIN',     true );
define( 'WP_DEBUG',            false );
define( 'WP_DEBUG_DISPLAY',    false );

// Better here than in the database — a database row travels in every backup.
define( 'TDH_STRIPE_LIVE_SECRET',      'sk_live_…' );
define( 'TDH_STRIPE_LIVE_WEBHOOK',     'whsec_…'   );
define( 'TDH_STRIPE_LIVE_PUBLISHABLE', 'pk_live_…' );
define( 'TDH_SMTP_PASSWORD',           '…'         );
```

`WP_ENVIRONMENT_TYPE` is the important one. `TDH\Mail` refuses to capture mail
in production and the demo persona bar refuses to render there — both read this
constant, and both fail open if it is wrong.

---

## 5. Monitoring — what exists, and what does not

Being blunt, because "we have monitoring" is the second most commonly believed
untrue thing about a website.

| | State |
|---|---|
| Deploy breaks the site | ✅ The GitHub Action checks for a 200 after deploying and fails loudly |
| Code rollback | ✅ Revert the commit and push — `DEPLOY.md` |
| Database rollback after a migration | ✅ Snapshot taken before every deploy — `DEPLOY.md` |
| Nightly backup | 🟡 Written, **not installed on the server** |
| Security posture check | 🟡 `baseline.php` — a manual check, **not monitoring** |
| **Uptime monitoring** | ❌ none |
| **Error logging** | ❌ none |
| **Login / file-integrity alerting** | ❌ none |

The last three are **Milestone 3** in the handoff ("Backup and restore test,
uptime monitoring, and error logging" under Production readiness). They are not
Milestone 1 scope. But the site is already live, so the useful ones are worth
starting early — and handoff §8 requires the provider, owner, usage, cost and
free-tier assumptions to be stated before anything paid is switched on. Here
they are.

### Error logging — free, do this first

Nothing to buy. In `wp-config.php`, **above** the "stop editing" line:

```php
define( 'WP_DEBUG',         true  );
define( 'WP_DEBUG_LOG',     '/home/<user>/logs/wp-errors.log' );
define( 'WP_DEBUG_DISPLAY', false );  // never show a visitor a stack trace
@ini_set( 'display_errors', '0' );
```

The path must be **outside `public_html`**. The default writes to
`wp-content/debug.log`, which is downloadable by anyone who guesses the URL and
which leaks file paths, queries and occasionally credentials.

`WP_DEBUG_DISPLAY` false with `WP_DEBUG` true is the combination that logs
without showing anything — `baseline.php` checks for exactly that.

### Uptime monitoring — free tier is enough

| Provider | Free tier | Owner |
|---|---|---|
| **UptimeRobot** (recommended) | 50 monitors, 5-minute checks, email + SMS alerts | **Rob's account, not ours** |
| Better Stack | 10 monitors, 3-minute checks | Rob |
| Hostinger's own | included, but only tells you the server is up, not the site | Rob |

Monitor `https://thirtydayhomes.com/` for **200 + a string on the page**, not just
a 200 — a WordPress fatal error can still return 200 with a white page.

It must be **Rob's account**, per handoff §11: no essential function may depend
on a developer-owned account.

### Security monitoring — decide at launch, not now

The honest position: real file-integrity and malware monitoring means Wordfence
or Sucuri, and the useful tiers are paid — which this client has declined. The
free Wordfence tier gives login-attempt visibility and file-change scanning with
delayed signatures, and it is heavy.

What is already in place without any of that: login throttling (5 per account,
20 per IP), a honeypot and rate limit on the contact form, capability checks on
every record, and `baseline.php` to re-check posture after any change.

**Recommendation:** run `baseline.php` at launch and after every change; add
UptimeRobot and error logging now because they are free; revisit paid security
monitoring only once there are real members to protect, and price it to Rob then
rather than assuming.

## 6. Known findings

Carried here so they are not forgotten, and so nobody has to re-derive them.

| Finding | Where | Fix |
|---|---|---|
| An administrator account is literally named `admin` | every environment | Create a replacement administrator, sign in as it, delete `admin` and reassign its content. Half of all password guessing starts with that username |
| No off-server copy of the backups | production | Needs a destination the client owns — §2 |
| Hostinger's own backup schedule is unverified and untested | production | Ask Rob for panel access, or have him check it |
| XML-RPC status unknown on the live host | production | `xmlrpc.php` allows hundreds of password guesses per request, past our own login throttle |

The table prefix is the default `wp_`. Noted, and deliberately **not** on the
list: changing it on a live site means rewriting serialised option values and
user meta keys, for a benefit that stops at obscurity.
