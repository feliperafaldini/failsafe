#!/usr/bin/env bash
#
# Failsafe Backup disaster protocol.
#
# Proves end to end that a backup restores the site:
#   1. creates a marker post;
#   2. takes a backup;
#   3. deletes the marker post;
#   4. restores the database from the SQL dump of that backup;
#   5. checks that the post is back and the site answers HTTP 200;
#   6. checks that the backup is still in the backup directory;
#   7. removes the marker post.
#
# Usage: ./tests/disaster-protocol.sh
#
# Requires the Docker stack from docker-compose.yml to be running. Only the
# local test site is touched: the restore always runs against the database of
# the WordPress container, never against another database.
#
# Exit status: 0 when every step passes, 1 on the first failure.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP="${WP_CONTAINER:-failsafe-wordpress-1}"
DB="${DB_CONTAINER:-failsafe-db-1}"
TESTS_REMOTE="/tmp/failsafe-disaster"
SITE_URL="${SITE_URL:-http://localhost:8080/}"
MARKER_TITLE="failsafe disaster $(date -u +%Y%m%d-%H%M%S)"
POST_ID=''
BASE=''

step() { printf '\n=== %s ===\n' "$1"; }
pass() { printf '  PASS %s\n' "$1"; }

fail() {
	printf '  FAIL %s\n' "$1"
	shift

	if [ "$#" -gt 0 ]; then
		printf '%s\n' "$*" | sed 's/^/       /'
	fi

	exit 1
}

run_helper() {
	docker exec -u www-data "$WP" php "$TESTS_REMOTE/php/disaster-marker.php" "$@"
}

cleanup() {
	if [ -n "$POST_ID" ]; then
		run_helper cleanup "$POST_ID" >/dev/null 2>&1 || true
	fi

	docker exec "$WP" rm -rf "$TESTS_REMOTE" >/dev/null 2>&1 || true
}

printf 'Failsafe disaster protocol\n'

if ! command -v docker >/dev/null 2>&1; then
	printf '  FAIL docker is not installed or not in PATH.\n'
	exit 1
fi

if ! docker exec "$WP" true >/dev/null 2>&1; then
	printf '  FAIL the WordPress container %s is not running. Start it with: docker compose up -d\n' "$WP"
	exit 1
fi

if ! docker exec "$DB" true >/dev/null 2>&1; then
	printf '  FAIL the database container %s is not running. Start it with: docker compose up -d\n' "$DB"
	exit 1
fi

trap cleanup EXIT

if ! command -v curl >/dev/null 2>&1; then
	fail 'curl is required to check the site over HTTP.'
fi

step 'Setup'
docker exec "$WP" rm -rf "$TESTS_REMOTE" >/dev/null 2>&1 || true

if ! docker exec "$WP" mkdir -p "$TESTS_REMOTE" >/dev/null 2>&1; then
	fail "could not create $TESTS_REMOTE inside $WP."
fi

if ! docker cp "$ROOT/tests/php" "$WP:$TESTS_REMOTE" >/dev/null 2>&1; then
	fail "could not copy tests/php into $WP."
fi

pass "helper copied to $WP:$TESTS_REMOTE/php"

step '1. Create marker post'
if OUTPUT=$(run_helper create "$MARKER_TITLE" 2>&1); then
	POST_ID=$(printf '%s\n' "$OUTPUT" | awk -F= '/^POST_ID=/ { print $2 }' | tail -n 1)

	if [ -z "$POST_ID" ] || ! [[ "$POST_ID" =~ ^[1-9][0-9]*$ ]]; then
		fail 'the helper did not report a post ID.' "$OUTPUT"
	fi

	pass "post $POST_ID created with title \"$MARKER_TITLE\""
else
	fail 'could not create the marker post.' "$OUTPUT"
fi

step '2. Create the backup'
if OUTPUT=$(run_helper backup 2>&1); then
	BASE=$(printf '%s\n' "$OUTPUT" | awk -F= '/^BACKUP_BASE=/ { print $2 }' | tail -n 1)

	if [ -z "$BASE" ] || ! [[ "$BASE" =~ ^failsafe-[0-9]{8}-[0-9]{6}(-[0-9]{1,2})?$ ]]; then
		fail 'the helper did not report a valid backup base name.' "$OUTPUT"
	fi

	pass "backup set $BASE created"
else
	fail 'could not create the backup.' "$OUTPUT"
fi

step '3. Delete the marker post'
if OUTPUT=$(run_helper delete "$POST_ID" 2>&1); then
	pass "post $POST_ID deleted and confirmed gone"
else
	fail "could not delete post $POST_ID." "$OUTPUT"
fi

step '4. Restore the database'
if OUTPUT=$(run_helper restore "$BASE" 2>&1); then
	pass "database restored from $BASE.sql"
else
	fail "could not restore $BASE.sql." "$OUTPUT"
fi

step '5. Verify the restore'
if OUTPUT=$(run_helper verify "$POST_ID" "$MARKER_TITLE" 2>&1); then
	pass "post $POST_ID is back with the same title"
else
	fail "post $POST_ID did not survive the restore." "$OUTPUT"
fi

HTTP_CODE='000'

# The first request right after the tables are recreated may still fail.
for attempt in 1 2 3 4 5 6 7 8 9 10; do
	HTTP_CODE=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --max-time 15 "$SITE_URL" 2>/dev/null) || HTTP_CODE='000'

	if [ "$HTTP_CODE" = '200' ]; then
		break
	fi

	sleep 1
done

if [ "$HTTP_CODE" != '200' ]; then
	fail "the site did not answer HTTP 200 at $SITE_URL (last code: $HTTP_CODE)."
fi

pass "site answers HTTP 200 at $SITE_URL"

step '6. Keep the backup'
if OUTPUT=$(run_helper check "$BASE" 2>&1); then
	pass "$BASE.sql and $BASE.zip are still in the backup directory"
else
	fail "the backup $BASE is no longer available." "$OUTPUT"
fi

step '7. Cleanup'
if OUTPUT=$(run_helper cleanup "$POST_ID" 2>&1); then
	pass "marker post $POST_ID removed"
	POST_ID=''
else
	# Cleanup is best effort and must never turn a good restore into a failure.
	printf '  WARN could not remove the marker post %s (ignored):\n' "$POST_ID"
	printf '%s\n' "$OUTPUT" | sed 's/^/       /'
fi

printf '\nRESULT: PASS\n'
