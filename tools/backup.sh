#!/usr/bin/env bash
#
# Back up the ThirtyDayHomes database and uploads.
#
# Runs ON THE SERVER, from cron. By hand:
#
#   bash ~/repos/thirtydayhomes-main/tools/backup.sh
#
# ─── WHAT IS BACKED UP, AND WHAT IS DELIBERATELY NOT ───────────────────────
#
#   the database    everything: listings, members, inquiries, settings,
#                   Stripe customer and subscription ids
#   wp-config.php   the salts and the database credentials. Without it a
#                   restored database cannot be opened and every session
#                   cookie on the site is invalidated
#   uploads/        listing photography, which exists nowhere else
#
# NOT backed up, on purpose:
#
#   WordPress core  downloadable in thirty seconds from wordpress.org
#   our theme and plugin   every line is in git, and git is the copy that
#                   matters — a backup of code we already version is a
#                   second, staler source of truth to get confused by
#   other plugins   reinstallable from the directory
#
# So this backs up exactly the things that exist in ONE place.
#
# ─── WHY NOT A BACKUP PLUGIN ───────────────────────────────────────────────
#
# The good ones are paid, and this client has declined recurring licence
# costs. The free ones run inside PHP on a web request, which means a large
# site hits the execution time limit halfway through and leaves an archive
# that looks finished and is not. Cron and mysqldump have neither problem.
#
set -euo pipefail

# ─── settings ──────────────────────────────────────────────────────────────
#
# cron runs with almost no environment — often no HOME and a PATH of just
# /usr/bin:/bin. Every path below is built from $HOME, so if it is missing
# they all resolve to "/domains/..." and the job fails at 03:17 with nobody
# watching. Derived from the passwd entry when it is not set, which is what
# an interactive login would have done.
if [ -z "${HOME:-}" ]; then
	HOME="$(getent passwd "$(id -u)" 2>/dev/null | cut -d: -f6)"
	export HOME
fi

[ -n "${HOME:-}" ] || { echo "backup FAILED: HOME is not set and could not be determined" >&2; exit 1; }

# wp-cli is usually in ~/bin, which is on an interactive PATH and not on
# cron's.
PATH="$HOME/bin:$PATH"
export PATH

WP_DIR="${WP_DIR:-$HOME/domains/thirtydayhomes.com/public_html}"
DEST="${DEST:-$HOME/backups/thirtydayhomes}"
KEEP_DAYS="${KEEP_DAYS:-14}"
WP_CLI="${WP_CLI:-$(command -v wp || echo "$HOME/bin/wp")}"

STAMP="$(date -u +%Y%m%d-%H%M%S)"
RUN="$DEST/$STAMP"

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }
die() { printf '\nbackup FAILED: %s\n' "$*" >&2; exit 1; }

# shellcheck source=lib-db.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib-db.sh"

# ─── checks before touching anything ───────────────────────────────────────

[ -f "$WP_DIR/wp-config.php" ] || die "no WordPress at $WP_DIR"
[ -x "$WP_CLI" ] || command -v "$WP_CLI" >/dev/null 2>&1 || die "wp-cli not found (set WP_CLI)"

# The dump needs mysqldump one way or the other — wp-cli only shells out to
# it. Checked by name so the diagnosis arrives with the failure rather than
# halfway through as "command not found". Some shared hosts do not ship it.
command -v mysqldump >/dev/null 2>&1 || die "mysqldump is not on PATH. Both routes need it. On a host that does not provide it, take the database export from the control panel and keep uploads on this schedule."

command -v gzip >/dev/null 2>&1 || die "gzip is not installed"
command -v tar  >/dev/null 2>&1 || die "tar is not installed"

mkdir -p "$RUN"

say "backing up to $RUN"

# ─── the database ──────────────────────────────────────────────────────────
#
# Through wp-cli rather than mysqldump directly: it reads the credentials out
# of wp-config.php, so they are never written into this file, into a cron
# entry, or into the process list where every other account on a shared host
# can read them.

say "database"

tdh_db_export "$RUN/database.sql" || die "database export failed"

# Reported AFTER the fact, by the code that did the work — see lib-db.sh.
echo "  via $TDH_DB_VIA"

# An empty or near-empty file is a failure that reported success. mysqldump
# can exit 0 having written only its header if it is refused a table.
[ -s "$RUN/database.sql" ] || die "the database export produced an empty file"

gzip -9 "$RUN/database.sql"

DB_SIZE="$(du -h "$RUN/database.sql.gz" | cut -f1)"
echo "  database.sql.gz  $DB_SIZE"

# A dump that cannot be read back is not a backup. gzip -t costs a second and
# catches a truncated write, which is the failure that otherwise stays hidden
# until the day it is needed.
gzip -t "$RUN/database.sql.gz" || die "the database dump is corrupt"
echo "  verified readable"

# ─── wp-config.php ─────────────────────────────────────────────────────────

say "wp-config.php"

cp "$WP_DIR/wp-config.php" "$RUN/wp-config.php"
chmod 600 "$RUN/wp-config.php"
echo "  copied, mode 600"

# ─── uploads ───────────────────────────────────────────────────────────────
#
# Excluding the generated thumbnail sizes: WordPress regenerates them with
# `wp media regenerate`, and on a photography-heavy site they are the
# majority of the bytes. The originals are what cannot be recreated.

say "uploads"

UPLOADS="$WP_DIR/wp-content/uploads"

if [ -d "$UPLOADS" ]; then
	tar -czf "$RUN/uploads.tar.gz" \
		-C "$WP_DIR/wp-content" \
		--exclude='*-[0-9]*x[0-9]*.jpg' \
		--exclude='*-[0-9]*x[0-9]*.jpeg' \
		--exclude='*-[0-9]*x[0-9]*.png' \
		--exclude='*-[0-9]*x[0-9]*.webp' \
		--exclude='uploads/cache' \
		uploads

	echo "  uploads.tar.gz  $(du -h "$RUN/uploads.tar.gz" | cut -f1)"

	tar -tzf "$RUN/uploads.tar.gz" >/dev/null || die "the uploads archive is corrupt"
	echo "  verified readable"
else
	echo "  no uploads directory — skipped"
fi

# ─── a receipt the site itself can read ────────────────────────────────────
#
# tools/baseline.php reads this to answer "when did a backup last run".
# Asking the filesystem rather than asking a person is the whole point:
# "yes, we have backups" is the most commonly believed untrue thing about
# any website.

date -u +%s > "$UPLOADS/.tdh-last-backup" 2>/dev/null || true

# ─── rotation ──────────────────────────────────────────────────────────────
#
# Deleting happens LAST and only after everything above succeeded, because
# `set -e` has already aborted on any failure. An old backup is never removed
# to make room for one that did not finish.

say "rotating"

BEFORE="$(find "$DEST" -maxdepth 1 -mindepth 1 -type d | wc -l)"

find "$DEST" -maxdepth 1 -mindepth 1 -type d -mtime "+$KEEP_DAYS" -exec rm -rf {} +

AFTER="$(find "$DEST" -maxdepth 1 -mindepth 1 -type d | wc -l)"

echo "  keeping $KEEP_DAYS days: $BEFORE sets -> $AFTER"

# ─── done ──────────────────────────────────────────────────────────────────

say "done"

echo "  $RUN"
echo "  $(du -sh "$RUN" | cut -f1) total"
echo
echo "  These files sit on the SAME server as the site. That covers a bad"
echo "  deploy, a bad update and a mistaken deletion — it does not cover"
echo "  losing the server. Copy them off periodically; see tools/BACKUPS.md."
