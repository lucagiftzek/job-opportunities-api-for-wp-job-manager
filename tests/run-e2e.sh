#!/usr/bin/env bash
# End-to-end tests for "Job Opportunities API for WP Job Manager" against the
# mock JOA API (tests/mock/router.php). Reproducible: resets plugin data first.
#
#   ./tests/setup.sh      # once: brings up WordPress + WP Job Manager + plugin
#   ./tests/run-e2e.sh    # runs every test, exits non-zero on any failure
set -uo pipefail
cd "$(dirname "$0")/.."

STATE=tests/mock/state
SITE=http://127.0.0.1:8088
ADMIN_PASS="${JOAWP_ADMIN_PASS:-joawp-local-test}"
JAR=$(mktemp)
trap 'rm -f "$JAR"' EXIT
PASS=0; FAIL=0; FAILED=()

wp()  { docker compose exec -T cli wp "$@" 2>&1; }
h()   { docker compose exec -T cli wp eval-file /tests/wp-helper.php "$@" 2>/dev/null; }
php() { docker compose exec -T cli wp eval "$1" 2>/dev/null; }
scen() { python3 tests/scenario.py "$@"; }
ok()  { PASS=$((PASS+1)); echo "  PASS  $1"; }
bad() { FAIL=$((FAIL+1)); FAILED+=("$1"); echo "  FAIL  $1  (expected: $2 | got: $(echo "$3" | head -c 400))"; }
eq()  { if [ "$2" == "$3" ]; then ok "$1"; else bad "$1" "$2" "$3"; fi; }
has() { if grep -qF -- "$2" <<<"$3"; then ok "$1"; else bad "$1" "contains '$2'" "$3"; fi; }
hasnt() { if grep -qF -- "$2" <<<"$3"; then bad "$1" "no '$2'" "found"; else ok "$1"; fi; }
reqs_reset() { : > "$STATE/requests.log"; }
reqs() { grep -c "\"path\":\"$1\"" "$STATE/requests.log" || true; }
meta() { [ -n "$1" ] && wp post meta get "$1" "$2" | tr -d '\r'; }
field() { [ -n "$1" ] && wp post get "$1" --field="$2" | tr -d '\r'; }
login() {
  curl -s -c "$JAR" -b "$JAR" -o /dev/null "$SITE/wp-login.php"
  curl -s -c "$JAR" -b "$JAR" -o /dev/null -d "log=admin&pwd=$ADMIN_PASS&testcookie=1&wp-submit=Log+In&redirect_to=$SITE/wp-admin/" "$SITE/wp-login.php"
}
page() { curl -s -b "$JAR" "$SITE/wp-admin/edit.php?post_type=job_listing&page=joawpjm$1"; }
nonce_for() { python3 -c 'import re,sys; m=re.search(r"value=\"joawpjm_'"$1"'\"><input type=\"hidden\" id=\"_wpnonce\" name=\"_wpnonce\" value=\"([a-f0-9]+)\"", sys.stdin.read()); print(m.group(1) if m else "")'; }
recurrence() { wp cron event list --hook=joawpjm_cron_import --fields=recurrence --format=csv | tail -1 | tr -d '\r"'; }

J1=00000000-0000-4000-8000-000000000001
J2=00000000-0000-4000-8000-000000000002
J3=00000000-0000-4000-8000-000000000003
J4=00000000-0000-4000-8000-000000000004
J5=00000000-0000-4000-8000-000000000005
J6=00000000-0000-4000-8000-000000000006
J7=00000000-0000-4000-8000-000000000007

echo "== setup"
docker compose exec -T cli sh -c 'echo http://mock:8000 > /var/www/html/wp-content/joawpjm-test-api-base.txt'
wp plugin activate wp-job-manager job-opportunities-api-for-wp-job-manager >/dev/null
h reset >/dev/null
h search main '{"label":"Main search","job_type":"auto","max_per_run":50}' >/dev/null
reqs_reset
scen --jobs default=J1,J2,J3,J4,J5 dup=J1DUP --feed 1
eq "API base points at the mock" "API base:         http://mock:8000" "$(wp joawpjm status | grep 'API base' | tr -d '\r')"
[ "$FAIL" -eq 0 ] || { echo "Refusing to continue: the plugin is not pointed at the mock API."; exit 1; }

echo "== 1. first import creates listings with the right meta"
OUT=$(wp joawpjm import)
has "cli import succeeds" "Success: Import finished." "$OUT"
eq "5 listings created" "5" "$(h entry 'Main search' created)"
eq "5 imported listings exist" "5" "$(h count)"
eq "paged with plan max_page_size (3): 2 job requests" "2" "$(reqs /v1/jobs)"
has "page size clamped to max_page_size" '"limit":"3"' "$(grep '/v1/jobs"' $STATE/requests.log | head -1)"
has "second page uses cursor" '"cursor":"3"' "$(cat $STATE/requests.log)"
has "user agent JOA-WPJM/<version>" '"ua":"JOA-WPJM/1.0.0"' "$(cat $STATE/requests.log)"
has "descriptions requested" '"include_description":"true"' "$(cat $STATE/requests.log)"
hasnt "first run sends no posted_after" 'posted_after' "$(cat $STATE/requests.log)"
P1=$(h pid $J1); P2=$(h pid $J2); P3=$(h pid $J3); P4=$(h pid $J4); P5=$(h pid $J5)
eq "title" "Senior Backend Engineer" "$(field $P1 post_title)"
eq "post status publish (default)" "publish" "$(field $P1 post_status)"
eq "post_date = posted_at" "2026-10-01 09:00:00" "$(field $P1 post_date)"
eq "author from the saved search" "1" "$(field $P1 post_author)"
eq "_job_location" "Berlin, Germany" "$(meta $P1 _job_location)"
eq "_application = employer apply_url" "https://jobs.example.com/acme/1" "$(meta $P1 _application)"
eq "_company_name" "Acme GmbH" "$(meta $P1 _company_name)"
eq "_company_website" "https://example1.example" "$(meta $P1 _company_website)"
eq "_remote_position (remote)" "1" "$(meta $P1 _remote_position)"
eq "_remote_position (hybrid)" "0" "$(meta $P2 _remote_position)"
eq "_job_expires = post date + 30 days" "2026-10-31" "$(meta $P1 _job_expires)"
eq "_job_expires from expires_at" "2026-12-31" "$(meta $P2 _job_expires)"
eq "_filled 0" "0" "$(meta $P1 _filled)"
eq "_featured 0" "0" "$(meta $P1 _featured)"
eq "_job_salary range" "60000–80000" "$(meta $P1 _job_salary)"
eq "_job_salary_currency" "EUR" "$(meta $P1 _job_salary_currency)"
eq "_job_salary_unit YEAR" "YEAR" "$(meta $P1 _job_salary_unit)"
eq "_job_salary_unit HOUR" "HOUR" "$(meta $P5 _job_salary_unit)"
eq "inferred salary not imported" "" "$(meta $P3 _job_salary)"
eq "_joawpjm_id" "$J1" "$(meta $P1 _joawpjm_id)"
eq "_joawpjm_slug" "role-1" "$(meta $P1 _joawpjm_slug)"
eq "_joawpjm_source" "greenhouse" "$(meta $P1 _joawpjm_source)"
eq "_joawpjm_source_type" "ats" "$(meta $P1 _joawpjm_source_type)"
eq "_joawpjm_search" "main" "$(meta $P1 _joawpjm_search)"
eq "_joawpjm_status" "live" "$(meta $P1 _joawpjm_status)"
eq "_joawpjm_apply_hash set" "32" "$(meta $P1 _joawpjm_apply_hash | tr -d '\n' | wc -c)"
eq "_joawpjm_company_logo stored as URL only" "https://logos.example.com/1.png" "$(meta $P1 _joawpjm_company_logo)"
eq "no image sideloaded" "" "$(meta $P1 _thumbnail_id)"
eq "no media attachments created" "0" "$(wp post list --post_type=attachment --format=count | tr -d '\r')"
C1=$(field $P1 post_content)
has "description converted to paragraphs" "<p>Line one with an entity<br />" "$C1"
has "HTML in plain text is escaped" "&lt;script&gt;" "$C1"
hasnt "no raw script tag" "<script>" "$C1"
has "no-description fallback line with apply link" 'href="https://jobs.example.com/example-co-4/4"' "$(field $P4 post_content)"
eq "job type mapped: Full-time -> Full Time" "Full Time" "$(h terms $P1 job_listing_type)"
eq "job type mapped: Internship" "Internship" "$(h terms $P4 job_listing_type)"
eq "job type: Contract has no matching term" "" "$(h terms $P3 job_listing_type)"
eq "attribution row: _application = canonical_url" "https://erioun.example/roles/5" "$(meta $P5 _application)"
eq "attribution stored" "via Erioun for Teams" "$(meta $P5 _joawpjm_attribution)"
eq "canonical stored" "https://erioun.example/roles/5" "$(meta $P5 _joawpjm_canonical_url)"
eq "closure feed polled after import" "1" "$(reqs /v1/jobs/expired)"

echo "== 2. second import creates no duplicates and is incremental"
reqs_reset
wp joawpjm import >/dev/null
eq "0 created on re-import" "0" "$(h entry 'Main search' created)"
eq "5 unchanged" "5" "$(h entry 'Main search' unchanged)"
eq "still 5 listings" "5" "$(h count)"
has "posted_after = last run - 1 day" '"posted_after":"' "$(grep '/v1/jobs"' $STATE/requests.log | head -1)"
eq "/v1/me cached (not called again)" "0" "$(reqs /v1/me)"

echo "== 3. changed title updates the listing"
scen --jobs default=J1,J2,J3,J4,J5 --feed 1 --set "J2.title=Senior Product Designer"
wp joawpjm import >/dev/null
eq "1 updated" "1" "$(h entry 'Main search' updated)"
eq "title updated" "Senior Product Designer" "$(field $P2 post_title)"
eq "post date unchanged on update" "2026-10-02 09:00:00" "$(field $P2 post_date)"

echo "== 4. a second search returning the same apply_url does not duplicate"
h search dup '{"label":"Duplicate search","q":"dup"}' >/dev/null
scen --jobs default=J1,J2,J3,J4,J5 dup=J1DUP --feed 1 --set "J2.title=Senior Product Designer"
reqs_reset
wp joawpjm import --search=dup >/dev/null
eq "duplicate skipped" "1" "$(h entry 'Duplicate search' skipped)"
eq "nothing created" "0" "$(h entry 'Duplicate search' created)"
eq "still 5 listings" "5" "$(h count)"
eq "no post for the duplicate id" "" "$(h pid $J7)"
eq "--search run skips closure checks" "0" "$(reqs /v1/jobs/expired)"
has "q filter sent" '"q":"dup"' "$(cat $STATE/requests.log)"

echo "== 5. dry run writes nothing"
scen --jobs default=J1,J2,J3,J4,J5,J6 dup=J1DUP --feed 1 --set "J2.title=Senior Product Designer"
LOGN=$(h logsize)
OUT=$(wp joawpjm import --dry-run)
has "dry run reports" "Dry run: nothing was written." "$OUT"
eq "no listing created" "5" "$(h count)"
eq "log untouched" "$LOGN" "$(h logsize)"
wp joawpjm import >/dev/null
eq "real run creates J6" "6" "$(h count)"
P6=$(h pid $J6)
eq "Temporary mapped" "Temporary" "$(h terms $P6 job_listing_type)"

echo "== 6. admin lock stops updates; closure feed still expires"
wp post meta update $P1 _joawpjm_locked 1 >/dev/null
scen --jobs default=J1,J2,J3,J4,J5,J6 --feed 1 --set "J1.title=Changed upstream" "J2.title=Senior Product Designer" --expired J1,J3
reqs_reset
wp joawpjm import >/dev/null
eq "locked listing title kept" "Senior Backend Engineer" "$(field $P1 post_title)"
eq "locked counted as skipped" "1" "$(h entry 'Main search' skipped)"
eq "feed expired 2" "2" "$(h entry 'Closure checks' expired)"
eq "locked listing expired anyway" "expired" "$(field $P1 post_status)"
eq "_joawpjm_status closed" "closed" "$(meta $P1 _joawpjm_status)"
eq "J3 expired via feed" "expired" "$(field $P3 post_status)"
eq "feed cursor stored" '"seq:2"' "$(h state | python3 -c 'import json,sys; print(json.dumps(json.load(sys.stdin)["expired_since"]))')"
has "feed started from first import time" '"since":"20' "$(grep 'expired' $STATE/requests.log | head -1)"
scen --jobs default=J2,J4,J5,J6 --feed 1 --set "J2.title=Senior Product Designer" --expired J1,J3
reqs_reset
wp joawpjm import >/dev/null
has "next poll uses next_since" '"since":"seq:2"' "$(grep 'expired' $STATE/requests.log | head -1)"
eq "nothing more expired" "0" "$(h entry 'Closure checks' expired)"

echo "== 7. per-listing checks when the plan has no feed (403)"
scen --jobs default=J6 --feed 0 --closed J2 --gone J4 --withdrawn J5
reqs_reset
wp joawpjm import >/dev/null
eq "403 remembered for 24h" "1" "$(h transient joawpjm_no_expired_feed)"
eq "J2 closed -> expired" "expired" "$(field $P2 post_status)"
eq "J2 reason" "employer_closed" "$(meta $P2 _joawpjm_closed_reason)"
eq "J4 404 -> expired" "expired" "$(field $P4 post_status)"
eq "J5 410 -> expired" "expired" "$(field $P5 post_status)"
eq "J6 still live" "publish" "$(field $P6 post_status)"
eq "3 expired by checks" "3" "$(h entry 'Closure checks' expired)"
has "per-id check uses include_closed" '"include_closed":"true"' "$(cat $STATE/requests.log)"
reqs_reset
wp joawpjm import >/dev/null
eq "feed not retried while 403 is remembered" "0" "$(reqs /v1/jobs/expired)"

echo "== 8. a closed role that comes back live is re-listed"
scen --jobs default=J3,J6 --feed 0
wp joawpjm import >/dev/null
eq "J3 republished" "publish" "$(field $P3 post_status)"
eq "J3 status live" "live" "$(meta $P3 _joawpjm_status)"
h set closure_checks 1 >/dev/null
reqs_reset
wp joawpjm import >/dev/null
eq "closure checks capped by setting (1 of 2 live)" "1" "$(grep -c '"path":"/v1/jobs/0000' $STATE/requests.log)"
h set closure_checks 25 >/dev/null

echo "== 9. 429 stops the run cleanly and pauses"
wp transient delete joawpjm_no_expired_feed >/dev/null
scen --jobs default=J3,J6 --feed 1 --mode 429
h search dup '{"label":"Duplicate search","q":"dup"}' >/dev/null
reqs_reset
wp joawpjm import >/dev/null
has "429 reported" "HTTP 429" "$(h entry 'Main search' errors)"
has "run stopped" "Run stopped." "$(h entry 'Main search' errors)"
eq "only one jobs request (no retry, second search not run)" "1" "$(reqs /v1/jobs)"
eq "no closure work after 429" "0" "$(reqs /v1/jobs/expired)"
RA=$(h transient joawpjm_retry_after)
if [ -n "$RA" ] && [ "$RA" -gt "$(date +%s)" ]; then ok "Retry-After stored"; else bad "Retry-After stored" "future ts" "$RA"; fi
reqs_reset
has "next run waits for Retry-After" "slow down" "$(wp joawpjm import)"
eq "no request while paused" "0" "$(reqs /v1/jobs)"
wp transient delete joawpjm_retry_after >/dev/null

echo "== 10. 5xx stops and is logged"
scen --jobs default=J3,J6 --feed 1 --mode 500
wp joawpjm import >/dev/null
has "503 logged" "HTTP 503" "$(h entry 'Main search' errors)"

echo "== 11. 401 shows the invalid-key notice and stops"
scen --jobs default=J3,J6 --feed 1 --mode 401
wp transient delete joawpjm_me >/dev/null
reqs_reset
wp joawpjm import >/dev/null
has "401 logged" "HTTP 401" "$(h entry 'Connection check' errors)"
eq "key flagged invalid" "1" "$(h option joawpjm_key_invalid)"
eq "no job requests after 401" "0" "$(reqs /v1/jobs)"
has "later runs refuse until the key is fixed" "rejected" "$(wp joawpjm import)"
login
has "admin notice: invalid key" "Job Opportunities API: invalid key." "$(curl -s -b "$JAR" "$SITE/wp-admin/index.php")"
scen --jobs default=J3,J6 --feed 1
php 'JOAWPJM_Settings::set_api_key("test-key-123");' >/dev/null
eq "saving a key clears the flag" "__missing__" "$(h option joawpjm_key_invalid)"
hasnt "notice gone" "Job Opportunities API: invalid key." "$(curl -s -b "$JAR" "$SITE/wp-admin/index.php")"

echo "== 12. overlapping runs: lock"
h lock 0 >/dev/null
has "second run refused while locked" "Another import is already running" "$(wp joawpjm import)"
h lock 2000 >/dev/null
has "stale lock (>15 min) is taken over" "Success: Import finished." "$(wp joawpjm import)"
eq "lock released after run" "__missing__" "$(h option joawpjm_lock)"

echo "== 13. WP-Cron path"
eq "recurring event scheduled" "scheduled" "$(h cron)"
eq "default schedule twicedaily" "12 hours" "$(recurrence)"
wp cron event run joawpjm_cron_import >/dev/null
eq "cron run logged" "cron" "$(h entry 'Main search' trigger)"

echo "== 14. search validation"
S=$(h sanitize '{"label":"","country":"de, at,xx1,usa","company":"Acme Corp, beta","company_domain":"https://www.Example.com/jobs, bad_domain","remote":"weird","max_per_run":"900","job_type":"auto"}')
has "countries normalised" '"country":"DE,AT"' "$S"
has "invalid countries reported" "XX1, USA" "$S"
has "company slugs" '"company":"acme-corp,beta"' "$S"
has "domains normalised" '"company_domain":"example.com"' "$S"
has "invalid domain reported" "bad_domain" "$S"
has "remote falls back to any" '"remote":"any"' "$S"
has "max per run clamped to 500" '"max_per_run":500' "$S"
has "empty label gets a summary" '"label":"Countries: DE,AT' "$S"

echo "== 15. admin pages: key masked, forms protected"
login
HTML=$(page "")
has "settings page renders" "Job Opportunities API for WP Job Manager" "$HTML"
has "key shown as last 4 only" "A key ending in …-123 is saved" "$HTML"
hasnt "full key never in HTML" "test-key-123" "$HTML"
has "key field is an empty password field" 'type="password" id="joawpjm-api-key" name="joawpjm_api_key" value=""' "$HTML"
has "searches tab" "Duplicate search" "$(page '&tab=searches')"
has "log tab" "Closure checks" "$(page '&tab=log')"
has "search editor" 'name="search[company_domain]"' "$(page '&tab=searches&action=new')"
eq "admin-post without nonce rejected" "403" "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -d 'action=joawpjm_run_now' "$SITE/wp-admin/admin-post.php")"
eq "admin-post logged out rejected" "403" "$(curl -s -o /dev/null -w '%{http_code}' -d 'action=joawpjm_run_now' "$SITE/wp-admin/admin-post.php" | sed 's/^302$/403/;s/^400$/403/')"
NONCE=$(nonce_for save_settings <<<"$HTML")
curl -s -o /dev/null -b "$JAR" --data-urlencode "action=joawpjm_save_settings" --data-urlencode "_wpnonce=$NONCE" --data-urlencode "joawpjm_api_key=" \
  --data-urlencode "joawpjm[schedule]=hourly" --data-urlencode "joawpjm[post_status]=pending" --data-urlencode "joawpjm[duration]=14" \
  --data-urlencode "joawpjm[keep_descriptions]=1" --data-urlencode "joawpjm[closure_checks]=25" "$SITE/wp-admin/admin-post.php"
eq "empty key submit keeps the key" "test-key-123" "$(php 'echo JOAWPJM_Settings::get_api_key();')"
eq "schedule saved" "hourly" "$(php 'echo JOAWPJM_Settings::get("schedule");')"
eq "rescheduled hourly" "1 hour" "$(recurrence)"
eq "duration saved" "14" "$(php 'echo JOAWPJM_Settings::get("duration");')"
eq "api key option not autoloaded" "off" "$(wp option list --search=joawpjm_api_key --fields=autoload --format=csv | tail -1 | tr -d '\r' | sed 's/^no$/off/')"
eq "searches option not autoloaded" "off" "$(wp option list --search=joawpjm_searches --fields=autoload --format=csv | tail -1 | tr -d '\r' | sed 's/^no$/off/')"
RN=$(nonce_for run_now <<<"$HTML")
curl -s -o /dev/null -b "$JAR" -d "action=joawpjm_run_now&_wpnonce=$RN" "$SITE/wp-admin/admin-post.php"
eq "Run import now works" "manual" "$(h entry 'Main search' trigger)"
has "result shown after redirect" "Import finished:" "$(page '')"
TC=$(nonce_for test_connection <<<"$HTML")
curl -s -o /dev/null -b "$JAR" -d "action=joawpjm_test_connection&_wpnonce=$TC" "$SITE/wp-admin/admin-post.php"
TCH=$(page '')
has "test connection shows plan" "Plan: growth" "$TCH"
has "test connection shows max page size" "Max page size: 3" "$TCH"
has "test connection shows usage" "Usage today: 7" "$TCH"
h set schedule twicedaily >/dev/null; h set post_status publish >/dev/null; h set duration 30 >/dev/null

echo "== 16. front end: attribution and optional credit"
scen --jobs default=J5 --feed 1
wp post update $P5 --post_status=publish >/dev/null
URL5=$(php "echo get_permalink($P5);")
F5=$(curl -s "$URL5")
has "attribution line shown" "Listed via Erioun for Teams" "$F5"
has "apply goes to canonical_url" "https://erioun.example/roles/5" "$F5"
hasnt "credit link off by default" "Jobs via Job Opportunities API" "$F5"
h set attribution 1 >/dev/null
has "credit link when opted in" "Jobs via Job Opportunities API" "$(curl -s "$URL5")"
h set attribution 0 >/dev/null
URL6=$(php "echo get_permalink($P6);")
hasnt "no attribution line on normal rows" "joawpjm-attribution" "$(curl -s "$URL6")"

echo "== 17. WP Job Manager inactive: graceful"
wp plugin deactivate wp-job-manager >/dev/null
eq "WPJM deactivated" "inactive" "$(wp plugin get wp-job-manager --field=status | tr -d '\r')"
eq "our plugin still active, no fatal" "active" "$(wp plugin get job-opportunities-api-for-wp-job-manager --field=status | tr -d '\r')"
has "import refuses cleanly" "WP Job Manager is not active." "$(wp joawpjm import)"
login
has "admin notice when WPJM inactive" "needs the WP Job Manager plugin" "$(curl -s -b "$JAR" "$SITE/wp-admin/index.php")"
eq "settings page moves under Settings" "200" "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$SITE/wp-admin/options-general.php?page=joawpjm")"
wp plugin activate wp-job-manager >/dev/null

echo "== 18. uninstall"
wp plugin uninstall job-opportunities-api-for-wp-job-manager --deactivate --skip-delete >/dev/null
eq "settings removed" "__missing__" "$(php 'echo get_option("joawpjm_settings","__missing__");')"
eq "key removed" "__missing__" "$(php 'echo get_option("joawpjm_api_key","__missing__");')"
eq "log removed" "__missing__" "$(php 'echo get_option("joawpjm_log","__missing__");')"
eq "cron removed" "" "$(php 'echo wp_next_scheduled("joawpjm_cron_import");')"
COUNT_SQL='global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key=\"_joawpjm_id\"");'
eq "listings kept when the option is off" "6" "$(php "$COUNT_SQL")"
wp plugin activate job-opportunities-api-for-wp-job-manager >/dev/null
h reset >/dev/null
h search main '{"label":"Main search"}' >/dev/null
scen --jobs default=J1,J2 --feed 1
wp joawpjm import >/dev/null
h set delete_on_uninstall 1 >/dev/null
wp plugin uninstall job-opportunities-api-for-wp-job-manager --deactivate --skip-delete >/dev/null
eq "listings deleted when the option is on" "0" "$(php "$COUNT_SQL")"
eq "no plugin options or transients left" "0" "$(php 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE \"%joawpjm%\"");')"
wp plugin activate job-opportunities-api-for-wp-job-manager >/dev/null

echo
echo "RESULT: $PASS passed, $FAIL failed"
for f in "${FAILED[@]:-}"; do [ -n "$f" ] && echo "  failed: $f"; done
[ "$FAIL" -eq 0 ]
