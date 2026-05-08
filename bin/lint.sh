#!/usr/bin/env bash
#
# Lint every PHP file in the plugin. Catches syntax errors before we even
# try to run anything against WordPress. Run from the plugin root:
#
#   bash bin/lint.sh
#
# Exit code 0 = all files OK; non-zero = at least one file failed to parse.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

PHP_BIN="${PHP_BIN:-php}"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
	echo "ERROR: php not found on PATH. Set PHP_BIN to an executable, e.g.:"
	echo "  PHP_BIN=/usr/local/bin/php8.1 bash bin/lint.sh"
	exit 2
fi

ROOT_FILES=$(find . -maxdepth 1 -type f -name "*.php" 2>/dev/null)
INCLUDE_FILES=$(find includes -type f -name "*.php" 2>/dev/null)
TEST_FILES=$(find tests -type f -name "*.php" 2>/dev/null || true)

ALL_FILES="$ROOT_FILES $INCLUDE_FILES $TEST_FILES"

total=0
failed=0
failed_files=()

for f in $ALL_FILES; do
	total=$((total + 1))
	output=$("$PHP_BIN" -l "$f" 2>&1)
	if echo "$output" | grep -q "No syntax errors"; then
		continue
	fi
	failed=$((failed + 1))
	failed_files+=("$f")
	echo "FAIL: $f"
	echo "$output" | sed 's/^/  /'
done

echo ""
echo "----------------------------------------"
if [ "$failed" -eq 0 ]; then
	echo "PASS: $total file(s) parsed cleanly."
	exit 0
fi

echo "FAIL: $failed of $total file(s) had syntax errors:"
for f in "${failed_files[@]}"; do
	echo "  - $f"
done
exit 1
