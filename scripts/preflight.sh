#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
exec php artisan release:preflight --production "$@"
