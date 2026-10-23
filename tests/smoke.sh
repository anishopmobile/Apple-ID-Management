#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
APP_TEST_PORT="$(printenv APP_TEST_PORT || echo 8181)"
BASE="http://127.0.0.1:$APP_TEST_PORT"
COOKIE="$(mktemp)"
TMP="$(mktemp)"
HEAD="$(mktemp)"
LOG="$(mktemp)"
php -S "127.0.0.1:$APP_TEST_PORT" -t public >"$LOG" 2>&1 &
PID=$!
trap 'kill "$PID" 2>/dev/null || true; rm -f "$COOKIE" "$TMP" "$HEAD" "$LOG"' EXIT
for i in $(seq 1 30); do
  if curl -fsS -b "$COOKIE" -c "$COOKIE" "$BASE/install.php" -o "$TMP"; then break; fi
  sleep 1
done
TOKEN="$(grep -oP 'name="_csrf" value="\K[^"]+' "$TMP" | head -1)"
test -n "$TOKEN"
echo '[smoke] installer initial page passed'
curl -sS -b "$COOKIE" -c "$COOKIE" \
  --data-urlencode "_csrf=$TOKEN" --data-urlencode 'action=test' \
  --data-urlencode 'APP_TZ=Asia/Tehran' --data-urlencode 'DB_HOST=127.0.0.1' \
  --data-urlencode 'DB_PORT=1' --data-urlencode 'DB_NAME=apple_ci' \
  --data-urlencode 'DB_USER=apple_ci' --data-urlencode 'DB_PASSWORD=ci-password' \
  --data-urlencode 'DB_PREFIX=anishop_' "$BASE/install.php" >"$TMP"
php -r '$r=json_decode(file_get_contents($argv[1]), true); if(!is_array($r)||$r["ok"]!==false) exit(1);' "$TMP"
echo '[smoke] rejected invalid database connection'
curl -sS -b "$COOKIE" -c "$COOKIE" \
  --data-urlencode "_csrf=$TOKEN" --data-urlencode 'action=test' \
  --data-urlencode 'APP_TZ=Asia/Tehran' --data-urlencode 'DB_HOST=127.0.0.1' \
  --data-urlencode 'DB_PORT=3306' --data-urlencode 'DB_NAME=apple_ci' \
  --data-urlencode 'DB_USER=apple_ci' --data-urlencode 'DB_PASSWORD=ci-password' \
  --data-urlencode 'DB_PREFIX=anishop_' "$BASE/install.php" >"$TMP"
php -r '$r=json_decode(file_get_contents($argv[1]), true); if(!is_array($r)||$r["ok"]!==true) {fwrite(STDERR,file_get_contents($argv[1])); exit(1);}' "$TMP"
echo '[smoke] successful database test passed'
curl -sS -b "$COOKIE" -c "$COOKIE" -D "$HEAD" -o "$TMP" \
  --data-urlencode "_csrf=$TOKEN" --data-urlencode 'action=finish' \
  --data-urlencode 'site_name=آنی شاپ' --data-urlencode 'full_name=مدیر آزمایشی' \
  --data-urlencode 'username=testadmin' --data-urlencode 'password=Example-CI-Pass-1234' \
  --data-urlencode 'password_confirm=Example-CI-Pass-1234' "$BASE/install.php"
grep -q 'نصب با موفقیت' "$TMP"
test -s storage/config.php
test -s storage/installed.lock
test ! -e public/install.php
grep -q 'Asia/Tehran' storage/config.php
echo '[smoke] installation and self-deletion passed'
curl -sS -b "$COOKIE" -c "$COOKIE" "$BASE/index.php?route=login" >"$TMP"
TOKEN="$(grep -oP 'name="_csrf" value="\K[^"]+' "$TMP" | head -1)"
curl -sS -b "$COOKIE" -c "$COOKIE" -D "$HEAD" -o /dev/null \
  --data-urlencode "_csrf=$TOKEN" --data-urlencode 'username=testadmin' \
  --data-urlencode 'password=Example-CI-Pass-1234' --data-urlencode 'remember=1' \
  "$BASE/index.php?route=login"
grep -qiE 'HTTP/1.1 303' "$HEAD"
grep -qi 'aid_remember=' "$HEAD"
curl -fsS -b "$COOKIE" -c "$COOKIE" "$BASE/index.php" >"$TMP"
grep -q 'id="dashboard-chart"' "$TMP"
grep -q 'آنی شاپ' "$TMP"
grep -q 'مشتریان' "$TMP"
echo '[smoke] login, remember cookie and authenticated dashboard passed'
TOKEN="$(grep -oP 'name="_csrf" value="\K[^"]+' "$TMP" | head -1)"
curl -sS -b "$COOKIE" -c "$COOKIE" -D "$HEAD" -o /dev/null \
  --data-urlencode "_csrf=$TOKEN" "$BASE/index.php?route=logout"
grep -qiE 'HTTP/1.1 303' "$HEAD"
curl -sS -b "$COOKIE" -c "$COOKIE" -D "$HEAD" -o /dev/null "$BASE/index.php"
grep -qiE 'HTTP/1.1 303' "$HEAD"
echo '[smoke] logout and access control passed'
