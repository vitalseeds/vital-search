#!/usr/bin/env bash
#
# Run the vital-search test suites.
#
#   ./tests/run.sh
#
# JS suites need jsdom (npm install). They report SKIP rather than failing
# if it is missing. The PHP suite needs any PHP 7.4+ binary.

set -uo pipefail

cd "$(dirname "$0")/.."

failed=0
skipped=0

run_js() {
    echo "=== $1 ==="
    node "tests/$1"
    local status=$?
    if [ $status -eq 2 ]; then
        skipped=$((skipped + 1))
    elif [ $status -ne 0 ]; then
        failed=$((failed + 1))
    fi
    echo
}

# Prefer a PHP on PATH, otherwise fall back to the one Local ships
find_php() {
    if command -v php >/dev/null 2>&1 && php -r 'exit(0);' >/dev/null 2>&1; then
        command -v php
        return
    fi
    ls -d /Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-*/bin/darwin/bin/php 2>/dev/null | tail -1
}

echo "=== syntax ==="
node --check js/vital-search.js && echo "OK  js/vital-search.js" || failed=$((failed + 1))

PHP_BIN="$(find_php)"
if [ -n "$PHP_BIN" ]; then
    "$PHP_BIN" -l vital-search.php >/dev/null && echo "OK  vital-search.php" || failed=$((failed + 1))
fi
echo

run_js lazy-init.test.js
run_js on-demand-libraries.test.js
run_js cache-freshness.test.js

echo "=== index-files.test.php ==="
if [ -n "$PHP_BIN" ]; then
    "$PHP_BIN" tests/index-files.test.php || failed=$((failed + 1))
else
    echo "SKIP  no working PHP binary found"
    skipped=$((skipped + 1))
fi
echo

if [ $failed -gt 0 ]; then
    echo "FAILED: $failed suite(s)"
    exit 1
fi

if [ $skipped -gt 0 ]; then
    echo "PASSED (with $skipped skipped)"
else
    echo "PASSED"
fi
