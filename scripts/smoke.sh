#!/usr/bin/env bash
set -Eeuo pipefail
BASE_URL="${BASE_URL:-http://127.0.0.1}"
API_URL="${BASE_URL%/}/api/v1"
CURL=(curl --silent --show-error --fail --max-time "${SMOKE_TIMEOUT:-10}" -H 'Accept: application/json')
tmp_cookie="$(mktemp)"
trap 'rm -f "$tmp_cookie"' EXIT
get_json() { "${CURL[@]}" "$1"; }
health="$(get_json "$API_URL/health")"
version="$(get_json "$API_URL/version")"
php -r '$d=json_decode(stream_get_contents(STDIN), true); exit(($d["status"] ?? null) === "ok" ? 0 : 1);' <<<"$health"
php -r '$d=json_decode(stream_get_contents(STDIN), true); exit(($d["data"]["api"] ?? null) === "v1" ? 0 : 1);' <<<"$version"
printf 'smoke health/version: PASS (%s)\n' "$API_URL"
if [[ -n "${SMOKE_EMAIL:-}" || -n "${SMOKE_PASSWORD:-}" ]]; then
  [[ -n "${SMOKE_EMAIL:-}" && -n "${SMOKE_PASSWORD:-}" ]] || { echo 'SMOKE_EMAIL and SMOKE_PASSWORD must be provided together' >&2; exit 64; }
  # Password is sent only in the request body and never echoed.
  login_code="$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time "${SMOKE_TIMEOUT:-10}" -c "$tmp_cookie" -b "$tmp_cookie" -H 'Accept: application/json' -H 'Content-Type: application/json' -X POST "$API_URL/login" --data-binary "$(SMOKE_EMAIL="$SMOKE_EMAIL" SMOKE_PASSWORD="$SMOKE_PASSWORD" php -r 'echo json_encode(["email"=>getenv("SMOKE_EMAIL"),"password"=>getenv("SMOKE_PASSWORD")], JSON_UNESCAPED_SLASHES);')")"
  case "$login_code" in 200|204) ;; *) echo "smoke login: FAIL (HTTP $login_code; credentials/details omitted)" >&2; exit 1 ;; esac
  dashboard_code="$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time "${SMOKE_TIMEOUT:-10}" -b "$tmp_cookie" -H 'Accept: application/json' "$API_URL/dashboard")"
  [[ "$dashboard_code" == 200 ]] || { echo "smoke dashboard: FAIL (HTTP $dashboard_code)" >&2; exit 1; }
  echo 'smoke authenticated dashboard: PASS'
else
  echo 'smoke authenticated checks: SKIP (set SMOKE_EMAIL and SMOKE_PASSWORD for an explicit test account)'
fi
