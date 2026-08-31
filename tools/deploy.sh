#!/usr/bin/env bash
#
# Deploy the theme and plugin onto the live site.
#
# Runs ON THE SERVER. Called by hand over SSH, or by the GitHub Action in
# .github/workflows/deploy.yml on every push to main.
#
#   bash ~/repos/thirtydayhomes-main/tools/deploy.sh
#
# ─── WHY A CLONE PLUS RSYNC, RATHER THAN CLONING INTO THE WEB ROOT ─────────
#
# Two reasons, and both matter.
#
# The repository does not have WordPress's shape. It keeps plugins/ and
# themes/ at its root, so cloning it into public_html would put the theme at
# /themes/thirtydayhomes rather than /wp-content/themes/thirtydayhomes, where
# WordPress will never look for it.
#
# And a clone inside the web root publishes .git to the internet. Anyone can
# then fetch .git/config and walk the object files back into the complete
# source history — a well-known and heavily scanned-for exposure. Keeping the
# clone outside public_html removes that possibility rather than relying on a
# rule in .htaccess that a server rebuild could drop.
#
set -euo pipefail

# ─── settings ──────────────────────────────────────────────────────────────

REPO_DIR="${REPO_DIR:-$HOME/repos/thirtydayhomes-main}"
WP_DIR="${WP_DIR:-$HOME/domains/thirtydayhomes.com/public_html}"
BRANCH="${BRANCH:-main}"

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }

# ─── checks before touching anything ───────────────────────────────────────

say "checking the ground"

[ -d "$REPO_DIR/.git" ] || { echo "No clone at $REPO_DIR — see tools/DEPLOY.md"; exit 1; }
[ -f "$WP_DIR/wp-config.php" ] || { echo "No WordPress at $WP_DIR"; exit 1; }
[ -d "$WP_DIR/wp-content/themes" ] || { echo "No wp-content/themes in $WP_DIR"; exit 1; }

command -v rsync >/dev/null 2>&1 || { echo "rsync is not installed on this server"; exit 1; }

echo "  repo : $REPO_DIR"
echo "  site : $WP_DIR"

# ─── fetch ─────────────────────────────────────────────────────────────────

say "fetching $BRANCH"

cd "$REPO_DIR"

BEFORE="$(git rev-parse --short HEAD)"

git fetch --prune origin "$BRANCH"

# reset --hard, NOT pull. This clone is a mirror of the branch and nothing
# should ever be edited on the server; a pull would try to MERGE a stray
# edit and could stop mid-deploy with a conflict, leaving the site half
# updated. Reset makes the outcome identical every time.
git reset --hard "origin/$BRANCH"

AFTER="$(git rev-parse --short HEAD)"

if [ "$BEFORE" = "$AFTER" ]; then
	echo "  already at $AFTER — nothing new"
else
	echo "  $BEFORE -> $AFTER"
	git --no-pager log --oneline "$BEFORE..$AFTER" | sed 's/^/    /'
fi

# ─── copy the two folders into place ───────────────────────────────────────
#
# --delete so a file removed in a commit is removed from the site. Without
# it, deleting a PHP file locally leaves it running in production for ever,
# which is how an old copy of a security fix stays live.
#
# Only these two paths are ever written. wp-config.php, uploads, other
# plugins and WordPress core are never touched by a deploy.

sync_dir() {
	local from="$1" to="$2" label="$3"

	[ -d "$from" ] || { echo "  missing in the repo: $from"; exit 1; }

	say "syncing $label"

	rsync -a --delete \
		--exclude '.git' \
		--exclude '.github' \
		--exclude 'node_modules' \
		--exclude '*.log' \
		--itemize-changes \
		"$from/" "$to/" | sed 's/^/    /'
}

sync_dir "$REPO_DIR/themes/thirtydayhomes"        "$WP_DIR/wp-content/themes/thirtydayhomes"        "theme"
sync_dir "$REPO_DIR/plugins/thirtydayhomes-core"  "$WP_DIR/wp-content/plugins/thirtydayhomes-core"  "plugin"

# ─── after ─────────────────────────────────────────────────────────────────

say "done"

echo "  deployed $AFTER"
echo
echo "  Roles and tables are re-registered by Core::maybe_upgrade() on the"
echo "  next admin page load, but ONLY when the plugin's VERSION constant is"
echo "  higher than the stored one. Ship a change to capabilities without"
echo "  bumping it and the code updates while the roles do not."
echo
echo "  The demo importer is deliberately NOT run: it rebuilds both menus"
echo "  from scratch and would discard anything edited since."
