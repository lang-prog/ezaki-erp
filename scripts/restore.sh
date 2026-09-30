#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
if [[ $# -eq 0 ]]; then echo "Usage: $0 path/to/backup.json [--dry-run|--yes]" >&2; exit 64; fi
exec php artisan backup:restore "$@"
