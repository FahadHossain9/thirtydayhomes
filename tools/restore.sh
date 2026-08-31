#!/usr/bin/env bash
#
# Restore ThirtyDayHomes from a backup made by tools/backup.sh.
#
#   bash ~/repos/thirtydayhomes-main/tools/restore.sh ~/backups/thirtydayhomes/20260831-020000
#
# ─── WHY THIS EXISTS AS A SCRIPT ───────────────────────────────────────────
#
# An untested backup is a rumour. The commonest way a restore fails is not a
# corrupt archive — it is that nobody has ever done one, so it is attempted
# for the first time at the worst possible moment, from memory, by whoever is
# available at 2am.
#
# Writing it down as a runnable script means the restore has been thought
# through once, calmly, and can be rehearsed on a copy. Milestone 3 requires
# a demonstrated restore; this is the thing to demonstrate.
#
# ─── IT REFUSES TO GUESS ───────────────────────────────────────────────────
#
# This overwrites a live database. It will not run without --yes, it prints
# exactly what it is about to do first, and it takes a safety copy of the
# CURRENT database before touching anything — because the second most common
# restore disaster is restoring the wrong backup over a database that was
# fine.
#
set -euo pipefail

WP_DIR="${WP_DIR:-$HOME/domains/thirtydayhomes.com/public_html}"
WP_CLI="${WP_CLI:-$(command -v wp || echo "$HOME/bin/wp")}"

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }
die() { printf '\nrestore ABORTED: %s\n' "$*" >&2; exit 1; }

SRC="${1:-}"
CONFIRM="${2:-}"

[ -n "$SRC" ] || die "usage: restore.sh <backup directory> --yes"
[ -d "$SRC" ] || die "no such backup: $SRC"
[ -f "$WP_DIR/wp-config.php" ] || die "no WordPress at $WP_DIR"

DUMP="$SRC/database.sql.gz"
[ -f "$DUMP" ] || die "no database.sql.gz in $SRC"

# ─── say what is about to happen ───────────────────────────────────────────

say "about to restore"

echo "  from    $SRC"
echo "  into    $WP_DIR"
echo "  dump    $(du -h "$DUMP" | cut -f1), written $(date -r "$DUMP" -u '+%Y-%m-%d %H:%M UTC')"
echo
echo "  This REPLACES the current database. Listings, members, inquiries and"
echo "  settings created since that timestamp will be gone."

if [ "$CONFIRM" != "--yes" ]; then
	echo
	echo "  Nothing has been changed. Re-run with --yes to go ahead."
	exit 0
fi

# ─── verify the archive BEFORE destroying anything ─────────────────────────

say "checking the backup first"

gzip -t "$DUMP" || die "the dump is corrupt — the current database has NOT been touched"
echo "  dump reads cleanly"

if [ -f "$SRC/uploads.tar.gz" ]; then
	tar -tzf "$SRC/uploads.tar.gz" >/dev/null || die "the uploads archive is corrupt — nothing has been touched"
	echo "  uploads archive reads cleanly"
fi

# ─── safety copy of what is there now ──────────────────────────────────────

say "saving the current database first"

SAFETY="$HOME/backups/thirtydayhomes/before-restore-$(date -u +%Y%m%d-%H%M%S).sql"
mkdir -p "$(dirname "$SAFETY")"

"$WP_CLI" --path="$WP_DIR" db export "$SAFETY" --add-drop-table --quiet || die "could not save the current database, so the restore was not started"
gzip -9 "$SAFETY"

echo "  $SAFETY.gz"
echo "  If this restore turns out to be the wrong one, that file puts it back."

# ─── the database ──────────────────────────────────────────────────────────

say "restoring the database"

gunzip -c "$DUMP" > /tmp/tdh-restore.sql
"$WP_CLI" --path="$WP_DIR" db import /tmp/tdh-restore.sql --quiet
rm -f /tmp/tdh-restore.sql

echo "  imported"

# ─── uploads ───────────────────────────────────────────────────────────────
#
# Extracted OVER the existing directory rather than replacing it. A file
# uploaded after the backup was taken is not in the archive, and deleting the
# directory first would destroy it for no reason — the database restore has
# already removed its attachment record, but the file itself is recoverable.

if [ -f "$SRC/uploads.tar.gz" ]; then
	say "restoring uploads"
	tar -xzf "$SRC/uploads.tar.gz" -C "$WP_DIR/wp-content"
	echo "  extracted over the existing uploads directory"
fi

# ─── afterwards ────────────────────────────────────────────────────────────

say "done"

cat <<'NOTES'
  Now, in this order:

    1. wp cache flush, and purge LiteSpeed from the host panel
    2. Settings -> Permalinks -> Save once, to rebuild rewrite rules
    3. wp media regenerate --yes
       The backup excludes generated thumbnail sizes; this rebuilds them.
    4. Walk tools/DEPLOY.md section 4.5 — the pages by eye
    5. Run the baseline:
       wp eval-file wp-content/plugins/thirtydayhomes-core/tools/baseline.php

  wp-config.php was NOT restored. It is in the backup, but the live one holds
  this server's own database credentials and salts, and overwriting it is how
  a restore turns into an outage. Copy it by hand only if you know you need it.
NOTES
