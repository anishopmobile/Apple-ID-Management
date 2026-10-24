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
  --data-urlencode "_csrf=$TOKEN" --data-urlencode 'action=finish' \
  --data-urlencode 'site_name=آنی شاپ' --data-urlencode 'full_name=مدیر آزمایشی' \
  --data-urlencode 'username=testadmin' --data-urlencode 'password=Example-CI-Pass-1234' \
  --data-urlencode 'password_confirm=Example-CI-Pass-1234' "$BASE/install.php" >"$TMP"
grep -q 'ابتدا اتصال دیتابیس' "$TMP"
test ! -e storage/config.php
echo '[smoke] direct administrator setup before DB verification rejected'
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

php tests/records_integration.php
php tests/customers_backup_integration.php
php tests/customer_export_settings.php
php tests/apple_id_card_integration.php

curl -fsS -b "$COOKIE" -c "$COOKIE" "$BASE/index.php?route=records_fields" >"$TMP"
grep -q 'ایجاد فیلد جدید' "$TMP"
grep -q 'فیلد تست' "$TMP"
curl -fsS -b "$COOKIE" -c "$COOKIE" "$BASE/index.php?route=records" >"$TMP"
grep -q 'ایجاد رکورد جدید' "$TMP"
grep -q 'ورود از اکسل' "$TMP"
grep -q 'خروجی اکسل' "$TMP"
curl -fsS -b "$COOKIE" -c "$COOKIE" "$BASE/index.php?route=reports_apple_id_card" >"$TMP"
grep -q 'id="apple-card-search"' "$TMP"
grep -q 'id="apple-card-table"' "$TMP"
grep -q 'data-record-id=' "$TMP"
curl -fsS -b "$COOKIE" -c "$COOKIE" "$BASE/index.php?route=reports_apple_id_card_api&per_page=25" >"$TMP"
php -r '$j=json_decode(file_get_contents($argv[1]),true); if (!is_array($j) || empty($j["ok"]) || count($j["rows"])!==25 || $j["pages"]<2) exit(1);' "$TMP"
METHOD_CODE="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE" -c "$COOKIE" -X POST "$BASE/index.php?route=reports_apple_id_card_api")"
test "$METHOD_CODE" = '405'
echo '[smoke] authenticated Apple ID Card page/API, 25-row pagination and rejected POST passed'
# Verify the real PDF HTTP download, binary magic, dimensions and security.
curl -fsS -b "$COOKIE" -c "$COOKIE" "$BASE/index.php?route=reports_apple_id_card" >"$TMP"
PDF_CSRF="$(grep -oP 'name="_csrf" value="\K[^"]+' "$TMP" | head -1)"
CARD_ID="$(php -r 'require "src/common.php"; echo db()->query("SELECT MAX(r.id) FROM ".table("apple_records")." r LEFT JOIN ".table("record_deletions")." d ON d.record_id=r.id WHERE d.record_id IS NULL")->fetchColumn();')"
PDF_OUT="$(mktemp --suffix=.pdf)"
PDF_HEADERS="$(mktemp)"
curl -fsS -b "$COOKIE" -c "$COOKIE" -D "$PDF_HEADERS" -o "$PDF_OUT" \
  --data-urlencode "_csrf=$PDF_CSRF" --data-urlencode "record_id=$CARD_ID" \
  --data-urlencode 'include_sensitive=0' \
  "$BASE/index.php?route=reports_apple_id_card_pdf"
echo '[smoke pdf diagnostics] HTTP response headers:'
grep -Ei 'HTTP/|Content-Type:|Content-Disposition:|Content-Length:' "$PDF_HEADERS" || true
echo '[smoke pdf diagnostics] measured PDF size:'
pdfinfo -box "$PDF_OUT" | grep -E 'Pages:|Page size:|MediaBox:' || true
grep -qi 'Content-Type: application/pdf' "$PDF_HEADERS"
grep -qi "account-card-$CARD_ID.pdf" "$PDF_HEADERS"
test "$(head -c 5 "$PDF_OUT")" = '%PDF-'
pdfinfo "$PDF_OUT" | grep -q 'Pages: *1'
pdfinfo "$PDF_OUT" | grep -q 'Page size: *240.9.*x 155.9.*pts'
GET_PDF="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE" -c "$COOKIE" \
  "$BASE/index.php?route=reports_apple_id_card_pdf")"
test "$GET_PDF" = '405'
NO_CSRF="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE" -c "$COOKIE" \
  --data-urlencode "record_id=$CARD_ID" "$BASE/index.php?route=reports_apple_id_card_pdf")"
test "$NO_CSRF" = '419'
BAD_RECORD="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE" -c "$COOKIE" \
  --data-urlencode "_csrf=$PDF_CSRF" --data-urlencode 'record_id=1;DROP TABLE admins' \
  "$BASE/index.php?route=reports_apple_id_card_pdf")"
test "$BAD_RECORD" = '400'
MISSING_RECORD="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE" -c "$COOKIE" \
  --data-urlencode "_csrf=$PDF_CSRF" --data-urlencode 'record_id=999999999999999' \
  "$BASE/index.php?route=reports_apple_id_card_pdf")"
test "$MISSING_RECORD" = '404'
rm -f "$PDF_OUT" "$PDF_HEADERS"
echo '[smoke] real 85x55mm PDF download, CSRF, POST-only, invalid/deleted IDs protected: PASS'
# Keep the authenticated HTML page in TMP: later CSRF/logout checks read it.
curl -fsS -b "$COOKIE" -c "$COOKIE" "$BASE/index.php?route=records" >"$TMP"
echo '[smoke] dynamic record field pages and server integration passed'
node tests/modal_browser.mjs "$BASE"
# Run extra PDF fixture creation after the UI suite so its search-count
# assertions still target the original two 'card-' regression records.
php tests/apple_id_card_pdf_integration.php

# The Chromium suite just saved a real sanitized commitment and editor mode.
curl -fsS -b "$COOKIE" -c "$COOKIE" "$BASE/index.php?route=reports_commitment" >"$TMP"
grep -q 'id="apple-card-search"' "$TMP"
grep -q 'data-report-kind="commitment"' "$TMP"
CM_CSRF="$(grep -oP 'name="_csrf" value="\K[^"]+' "$TMP" | head -1)"
CM_ID="$(php -r 'require "src/common.php"; echo db()->query("SELECT MAX(r.id) FROM ".table("apple_records")." r LEFT JOIN ".table("record_deletions")." d ON d.record_id=r.id WHERE d.record_id IS NULL")->fetchColumn();')"
CM_PDF="$(mktemp --suffix=.pdf)"
CM_HEADER="$(mktemp)"
curl -fsS -b "$COOKIE" -c "$COOKIE" -D "$CM_HEADER" -o "$CM_PDF" \
  --data-urlencode "_csrf=$CM_CSRF" --data-urlencode "record_id=$CM_ID" \
  --data-urlencode 'include_sensitive=0' \
  "$BASE/index.php?route=reports_commitment_pdf"
grep -qi 'Content-Type: application/pdf' "$CM_HEADER"
grep -qi "commitment-$CM_ID.pdf" "$CM_HEADER"
test "$(head -c 5 "$CM_PDF")" = '%PDF-'
pdfinfo "$CM_PDF" | grep -q 'Page size: *595.2.*x 841.8.*pts'
pdftotext -enc UTF-8 "$CM_PDF" "$TMP"
grep -q 'تعهدنامه مرورگر' "$TMP"
CM_GET="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE" \
  "$BASE/index.php?route=reports_commitment_pdf")"
test "$CM_GET" = '405'
CM_CSRF_STATUS="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE" \
  --data-urlencode "record_id=$CM_ID" "$BASE/index.php?route=reports_commitment_pdf")"
test "$CM_CSRF_STATUS" = '419'
CM_UNAUTH="$(curl -sS -o "$TMP" -w '%{http_code}' \
  --data-urlencode "record_id=$CM_ID" --data-urlencode "_csrf=$CM_CSRF" \
  "$BASE/index.php?route=reports_commitment_pdf")"
test "$CM_UNAUTH" != '200'
rm -f "$CM_PDF" "$CM_HEADER"
echo '[smoke] commitment A4 download, same card, saved Persian editor text, authentication, POST and CSRF: PASS'

php tests/date_display_integration.php
php tests/commitment_integration.php


TOKEN="$(grep -oP 'name="_csrf" value="\K[^"]+' "$TMP" | head -1)"
curl -sS -b "$COOKIE" -c "$COOKIE" --data-urlencode "_csrf=$TOKEN" \
  --data-urlencode 'action=field_save' --data-urlencode 'label=فیلد ثبت با API' \
  --data-urlencode 'type=text' --data-urlencode 'max_length=100' \
  --data-urlencode 'sort_order=120' --data-urlencode 'active=1' \
  --data-urlencode 'show_in_table=1' "$BASE/index.php?route=records_api" >"$TMP"
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(empty($j["ok"])) exit(1);' "$TMP"
curl -sS -b "$COOKIE" -c "$COOKIE" --data-urlencode "_csrf=$TOKEN" \
  --data-urlencode 'action=record_save' "$BASE/index.php?route=records_api" >"$TMP"
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(($j["ok"]??null)!==false || empty($j["fields"])) exit(1);' "$TMP"
RECORD_ID="$(php -r 'require "src/common.php";echo db()->query("SELECT MAX(id) FROM ".table("apple_records"))->fetchColumn();')"
curl -sS -b "$COOKIE" -c "$COOKIE" \
  "$BASE/index.php?route=records_api&action=record&id=$RECORD_ID" >"$TMP"
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(empty($j["ok"]) || empty($j["record"]["values"])) exit(1);' "$TMP"
curl -sS -b "$COOKIE" -c "$COOKIE" --data-urlencode "_csrf=$TOKEN" \
  --data-urlencode 'action=record_delete' --data-urlencode "id=$RECORD_ID" \
  "$BASE/index.php?route=records_api" >"$TMP"
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(empty($j["ok"])) exit(1);' "$TMP"
curl -fsS -b "$COOKIE" -c "$COOKIE" "$BASE/index.php" >"$TMP"
grep -q 'id="dashboard-chart"' "$TMP"
echo '[smoke] AJAX field creation, record input validation, record loading/deletion and dashboard passed'

# Exercise actual uploaded image path, public safe delivery and both logo slots.
LOGO_IMAGE="$(mktemp --suffix=.png)"
php -r '$img=imagecreatetruecolor(64,64);imagefilledrectangle($img,0,0,63,63,imagecolorallocate($img,24,70,160));imagepng($img,$argv[1]);imagedestroy($img);' "$LOGO_IMAGE"
curl -fsS -b "$COOKIE" -c "$COOKIE" "$BASE/index.php?route=settings" >"$TMP"
TOKEN="$(grep -oP 'name="_csrf" value="\K[^"]+' "$TMP" | head -1)"
curl -sS -b "$COOKIE" -c "$COOKIE" -D "$HEAD" -o /dev/null \
  -F "_csrf=$TOKEN" -F "action=upload_logo" -F "logo=@$LOGO_IMAGE;type=image/png" \
  "$BASE/index.php?route=settings"
grep -qiE 'HTTP/1.1 303' "$HEAD"
curl -fsS -b "$COOKIE" "$BASE/index.php" >"$TMP"
grep -q 'site-logo-sidebar' "$TMP"
curl -fsS "$BASE/index.php?route=login" >"$TMP"
grep -q 'site-logo-login' "$TMP"
curl -fsS -D "$HEAD" -o "$TMP" "$BASE/index.php?route=site_logo"
grep -qi 'Content-Type: image/png' "$HEAD"
cmp "$LOGO_IMAGE" "$TMP"
curl -sS -b "$COOKIE" -c "$COOKIE" -D "$HEAD" -o /dev/null \
  --data-urlencode "_csrf=$TOKEN" --data-urlencode "action=remove_logo" \
  "$BASE/index.php?route=settings"
grep -qiE 'HTTP/1.1 303' "$HEAD"
rm -f "$LOGO_IMAGE"
echo '[smoke] validated PNG upload, login/sidebar logo and private image delivery passed'
curl -fsS -b "$COOKIE" -c "$COOKIE" "$BASE/index.php" >"$TMP"

TOKEN="$(grep -oP 'name="_csrf" value="\K[^"]+' "$TMP" | head -1)"
curl -sS -b "$COOKIE" -c "$COOKIE" -D "$HEAD" -o /dev/null \
  --data-urlencode "_csrf=$TOKEN" "$BASE/index.php?route=logout"
grep -qiE 'HTTP/1.1 303' "$HEAD"
curl -sS -b "$COOKIE" -c "$COOKIE" -D "$HEAD" -o /dev/null "$BASE/index.php"
grep -qiE 'HTTP/1.1 303' "$HEAD"
echo '[smoke] logout and access control passed'
# Test the persistent token separately from any PHP session cookie.
PERSISTENT="$(awk -F'\t' '$6=="aid_remember" {print $7}' "$COOKIE" | tail -1)"
# The current cookie jar is expected to have had its remember cookie revoked on logout.
# Confirm CSRF denies state-changing actions without a valid token.
CODE="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE" -c "$COOKIE" \
  --data-urlencode 'username=testadmin' --data-urlencode 'password=Example-CI-Pass-1234' \
  "$BASE/index.php?route=login")"
test "$CODE" = '419'
echo '[smoke] invalid login CSRF rejected'
curl -sS -b "$COOKIE" -c "$COOKIE" "$BASE/index.php?route=login" >"$TMP"
TOKEN="$(grep -oP 'name="_csrf" value="\K[^"]+' "$TMP" | head -1)"
for i in $(seq 1 8); do
  curl -sS -b "$COOKIE" -c "$COOKIE" \
    --data-urlencode "_csrf=$TOKEN" --data-urlencode 'username=testadmin' \
    --data-urlencode 'password=incorrect-password' "$BASE/index.php?route=login" >/dev/null
done
CODE="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE" -c "$COOKIE" \
  --data-urlencode "_csrf=$TOKEN" --data-urlencode 'username=testadmin' \
  --data-urlencode 'password=incorrect-password' "$BASE/index.php?route=login")"
test "$CODE" = '429'
echo '[smoke] IP/account login rate limit passed'
