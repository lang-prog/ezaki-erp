#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
# Credentials remain in Laravel .env/config; they are not command-line arguments.
exec php artisan backup:database "$@"
