#!/usr/bin/env bash
#
# Failsafe Backup test suite.
#
# Usage: ./tests/run-tests.sh
#
# Requires the Docker stack from docker-compose.yml to be running.
# Credentials are read from docker-compose.yml so no secret lives in this file.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/src"
WP="${WP_CONTAINER:-failsafe-wordpress-1}"
DB="${DB_CONTAINER:-failsafe-db-1}"
TESTS_REMOTE="/tmp/failsafe-tests"
SQL_REMOTE="/var/www/html/wp-content/failsafe-backups/failsafe-backup.sql"
SQL_LOCAL="/tmp/failsafe-ci.sql"
FAILED=0

compose_value() {
	grep -m1 "$1" "$ROOT/docker-compose.yml" | awk '{print $2}' | tr -d '"'
}

DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-$(compose_value MARIADB_ROOT_PASSWORD)}"
DB_NAME="${DB_NAME:-$(compose_value MARIADB_DATABASE)}"

step() { printf '\n=== %s ===\n' "$1"; }
fail() { printf '  FAIL %s\n' "$1"; FAILED=1; }

step "PHP lint"
for file in "$SRC"/failsafe.php "$SRC"/admin/*.php; do
	if php -l "$file" >/dev/null; then
		printf '  ok   %s\n' "${file#"$ROOT"/}"
	else
		fail "$file"
	fi
done

step "Unit tests"
docker exec "$WP" rm -rf "$TESTS_REMOTE"
docker cp "$ROOT/tests" "$WP:$TESTS_REMOTE" >/dev/null

docker exec -u www-data "$WP" php "$TESTS_REMOTE/test-dump.php" || FAILED=1
docker exec -u www-data "$WP" php "$TESTS_REMOTE/test-zip.php" || FAILED=1

step "Round-trip import"
if docker cp "$WP:$SQL_REMOTE" "$SQL_LOCAL" 2>/dev/null; then
	docker exec "$DB" mariadb -uroot -p"$DB_ROOT_PASSWORD" \
		-e 'DROP DATABASE IF EXISTS teste_ci; CREATE DATABASE teste_ci' >/dev/null 2>&1

	if docker exec -i "$DB" mariadb -uroot -p"$DB_ROOT_PASSWORD" teste_ci <"$SQL_LOCAL" 2>/tmp/failsafe-import.err; then
		printf '  ok   dump imported without errors\n'
	else
		printf '  FAIL import error: %s\n' "$(head -1 /tmp/failsafe-import.err)"
		FAILED=1
	fi

	if docker exec -i "$DB" mariadb -uroot -p"$DB_ROOT_PASSWORD" teste_ci <"$SQL_LOCAL" 2>/tmp/failsafe-import2.err; then
		printf '  ok   restore over an existing database works\n'
	else
		printf '  FAIL second import error: %s\n' "$(head -1 /tmp/failsafe-import2.err)"
		FAILED=1
	fi

	for table in wp_posts wp_options wp_users; do
		original=$(docker exec "$DB" mariadb -uroot -p"$DB_ROOT_PASSWORD" -N -e "SELECT COUNT(*) FROM $table" "$DB_NAME" 2>/dev/null)
		restored=$(docker exec "$DB" mariadb -uroot -p"$DB_ROOT_PASSWORD" -N -e "SELECT COUNT(*) FROM $table" teste_ci 2>/dev/null)

		if [ "$original" = "$restored" ]; then
			printf '  ok   %s rows match (%s)\n' "$table" "$original"
		else
			fail "$table rows differ (original=$original restored=$restored)"
		fi
	done

	docker exec "$DB" mariadb -uroot -p"$DB_ROOT_PASSWORD" -e 'DROP DATABASE IF EXISTS teste_ci' >/dev/null 2>&1
	rm -f "$SQL_LOCAL" /tmp/failsafe-import.err /tmp/failsafe-import2.err
else
	fail "could not copy the SQL dump out of $WP"
fi

step "Cleanup"
docker exec "$WP" rm -rf "$TESTS_REMOTE"
printf '  ok   temporary files removed\n'

printf '\n'
if [ "$FAILED" -eq 0 ]; then
	echo "RESULT: PASS"
else
	echo "RESULT: FAIL"
fi

exit "$FAILED"
