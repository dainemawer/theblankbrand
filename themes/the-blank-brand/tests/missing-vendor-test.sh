#!/usr/bin/env bash
#
# The theme must degrade, not fatal, when vendor/ is missing.
#
#   bash wp-content/themes/the-blank-brand/tests/missing-vendor-test.sh [site-url]
#
# vendor/ is not committed, so a deploy or a fresh clone can arrive without it.
# The theme used to throw at load, which returned a 500 for every request,
# including the login page, so there was no admin left to read an error in.
#
# This moves vendor/ aside, asks the site for three URLs, and puts it back. A
# trap restores it on any exit. Local only: it briefly breaks the theme.

set -u
THEME="$(cd "$(dirname "$0")/.." && pwd)"
SITE="${1:-https://the-blank-brand.local}"

case "$SITE" in
	*.local|*.test|*localhost*) ;;
	*) echo "REFUSING TO RUN: $SITE does not look like a local site."; exit 2 ;;
esac

[ -f "$THEME/vendor/autoload.php" ] || { echo "vendor/autoload.php not found, nothing to test."; exit 2; }
[ -e "$THEME/vendor.test-aside" ] && { echo "vendor.test-aside already exists. Restore it by hand first."; exit 2; }

mv "$THEME/vendor" "$THEME/vendor.test-aside"
trap 'mv "$THEME/vendor.test-aside" "$THEME/vendor" 2>/dev/null' EXIT

pass=0; fail=0
check() {
	local label="$1" want="$2" got="$3"
	if [ "$want" = "$got" ]; then pass=$((pass+1)); echo "  PASS  $label"; else fail=$((fail+1)); echo "  FAIL  $label (expected $want, got $got)"; fi
}

echo "With vendor/ missing:"
for path in "/" "/shop/" "/wp-login.php" "/wp-json/"; do
	code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 25 "$SITE$path")
	check "GET $path stays up" 200 "$code"
done

body=$(curl -sk --max-time 25 "$SITE/wp-login.php")
case "$body" in *"Fatal error"*|*"Uncaught"*) check "no fatal error on the login page" clean fatal ;; *) check "no fatal error on the login page" clean clean ;; esac

mv "$THEME/vendor.test-aside" "$THEME/vendor"
trap - EXIT
echo "(vendor restored)"

code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 25 "$SITE/")
check "and the site is healthy again with vendor/ back" 200 "$code"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
