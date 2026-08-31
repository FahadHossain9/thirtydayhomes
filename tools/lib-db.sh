#!/usr/bin/env bash
#
# Database export and import, shared by backup.sh, restore.sh and deploy.sh.
#
# Sourced, not executed:
#
#   . "$(dirname "$0")/lib-db.sh"
#
# ─── WHY THIS EXISTS ───────────────────────────────────────────────────────
#
# `wp db export` is the obvious way to dump a WordPress database, and it is
# what all three scripts used. On Hostinger it fails:
#
#   Error: Cannot do 'Process::run': The PHP functions `proc_open()` and/or
#   `proc_close()` are disabled.
#
# wp-cli does not dump anything itself — it shells out to mysqldump through
# PHP's process functions, and shared hosts routinely disable those, because
# proc_open is how a compromised PHP file runs arbitrary commands. The host
# is right to disable it. mysqldump itself is installed and works fine; only
# PHP's route to it is closed.
#
# So: try wp-cli first, because it handles the awkward cases, and fall back
# to calling mysqldump directly. Every host is then covered by the same
# script, and neither path is anybody's special case to remember.
#
# ─── THE PASSWORD NEVER REACHES THE PROCESS LIST ───────────────────────────
#
# `mysqldump --password=hunter2` puts the password in `ps`, where every other
# account on a shared server can read it for as long as the dump runs. The
# credentials go into a temporary file with mode 600 instead, and it is
# deleted on the way out — including when the script is interrupted.

# Read one constant out of wp-config.php.
#
# `wp config get` parses the file in PHP; it spawns nothing, so it works on
# a host where proc_open is disabled.
tdh_db_conf() {
	"$WP_CLI" --path="$WP_DIR" config get "$1" 2>/dev/null || true
}

# Write a mysql defaults file and echo its path. Caller removes it.
tdh_db_defaults_file() {

	local cnf host port

	cnf="$(mktemp)"
	chmod 600 "$cnf"

	host="$(tdh_db_conf DB_HOST)"
	[ -n "$host" ] || host="localhost"

	# DB_HOST can carry a port (`localhost:3306`) or a socket path
	# (`localhost:/var/run/mysqld/mysqld.sock`). mysqldump wants them as
	# separate options.
	port=""
	socket=""

	case "$host" in
		*:/*)
			socket="${host#*:}"
			host="${host%%:*}"
			;;
		*:*)
			port="${host##*:}"
			host="${host%%:*}"
			;;
	esac

	{
		echo "[client]"
		echo "host=${host}"
		if [ -n "$port" ]; then
			echo "port=${port}"
		fi
		if [ -n "$socket" ]; then
			echo "socket=${socket}"
		fi
		echo "user=$(tdh_db_conf DB_USER)"
		echo "password=$(tdh_db_conf DB_PASSWORD)"
	} > "$cnf"

	echo "$cnf"
}

# Dump the database to $1. Returns non-zero on failure.
tdh_db_export() {

	local out="$1" cnf db

	# Preferred. Works wherever proc_open is allowed.
	if "$WP_CLI" --path="$WP_DIR" db export "$out" --add-drop-table --quiet 2>/dev/null; then
		return 0
	fi

	db="$(tdh_db_conf DB_NAME)"
	[ -n "$db" ] || return 1

	cnf="$(tdh_db_defaults_file)"

	#
	# --single-transaction  a consistent snapshot without locking the site
	#                       out of its own tables while the dump runs
	# --quick               stream rows instead of buffering the whole table
	#                       in memory, which is what kills a dump on a small
	#                       shared-hosting plan
	# --no-tablespaces      MySQL 8 requires the PROCESS privilege to read
	#                       tablespace information, and a shared-hosting user
	#                       does not have it. Without this the dump fails with
	#                       "Access denied; you need PROCESS privilege"
	#
	# The credentials file is removed on every path out of here — explicitly
	# rather than with a RETURN trap, which stays armed after the function
	# returns and then fires again on the next function that returns.
	#
	if mysqldump --defaults-extra-file="$cnf" \
		--add-drop-table --single-transaction --quick --no-tablespaces \
		"$db" > "$out" 2>/dev/null; then
		rm -f "$cnf"
		return 0
	fi

	# Older mysqldump and some MariaDB builds do not know --no-tablespaces
	# and exit on the unknown option rather than ignoring it.
	if mysqldump --defaults-extra-file="$cnf" \
		--add-drop-table --single-transaction --quick \
		"$db" > "$out"; then
		rm -f "$cnf"
		return 0
	fi

	rm -f "$cnf"
	return 1
}

# Load a dump from $1 into the database. Returns non-zero on failure.
tdh_db_import() {

	local file="$1" cnf db

	if "$WP_CLI" --path="$WP_DIR" db import "$file" --quiet 2>/dev/null; then
		return 0
	fi

	db="$(tdh_db_conf DB_NAME)"
	[ -n "$db" ] || return 1

	cnf="$(tdh_db_defaults_file)"

	if mysql --defaults-extra-file="$cnf" "$db" < "$file"; then
		rm -f "$cnf"
		return 0
	fi

	rm -f "$cnf"
	return 1
}

# Which route will actually be used, for the log.
tdh_db_method() {
	if "$WP_CLI" --path="$WP_DIR" db size --quiet >/dev/null 2>&1; then
		echo "wp-cli"
	else
		echo "mysqldump directly (PHP's proc_open is disabled on this host)"
	fi
}
