#!/usr/bin/env bash
# Runs PHPUnit with the XAMPP PHP build (8.2), which is the only local PHP with mysqli.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-C:/xampp/php/php.exe}"
exec "$PHP_BIN" "$ROOT/vendor/phpunit/phpunit/phpunit" "$@"
