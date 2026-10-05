#!/usr/bin/env bash
# Live smoke test against the real Job Opportunities API (JOA).
# Needs JOA_API_KEY in the environment; the key is passed into the container
# through the environment only (never on a command line, never written to the repo):
#
#   set -a; . ~/.config/joa-integrations/test.env; set +a
#   ./tests/run-live.sh
#
# Makes fewer than 10 API calls: one search (country=DE, remote=remote, max 10 jobs), run twice.
set -euo pipefail
cd "$(dirname "$0")/.."
: "${JOA_API_KEY:?Set JOA_API_KEY first}"
export JOAWPJM_LIVE_KEY="$JOA_API_KEY"

SITE=http://127.0.0.1:8088
wp() { docker compose exec -T cli wp "$@"; }
h()  { docker compose exec -T cli wp eval-file /tests/wp-helper.php "$@"; }

docker compose exec -T cli rm -f /var/www/html/wp-content/joawpjm-test-api-base.txt
wp joawpjm status | grep 'API base' | grep -q 'https://api.jobopportunitiesapi.org' || { echo "API base is not the real API"; exit 1; }

h reset >/dev/null
docker compose exec -T -e JOAWPJM_LIVE_KEY cli wp eval 'JOAWPJM_Settings::set_api_key( (string) getenv( "JOAWPJM_LIVE_KEY" ) );'
h search live '{"label":"Remote jobs in Germany","country":"DE","remote":"remote","max_per_run":10,"job_type":"auto"}' >/dev/null

echo "== live import 1"
wp joawpjm import
N=$(h count)
echo "imported listings: $N"
[ "$N" -gt 0 ] && [ "$N" -le 10 ] || { echo "FAIL: expected 1..10 listings"; exit 1; }

echo "== live import 2 (must create 0)"
wp joawpjm import
C=$(h entry 'Remote jobs in Germany' created)
[ "$C" = "0" ] || { echo "FAIL: second run created $C"; exit 1; }

echo "== front end"
# WP Job Manager renders /jobs and loads the list through its AJAX endpoint.
code=$(curl -s -o /dev/null -w '%{http_code}' "$SITE/jobs/")
echo "/jobs/ HTTP $code"
curl -s "$SITE/jm-ajax/get_listings/?per_page=10" | python3 -c '
import json,sys,re
d=json.load(sys.stdin)
titles=re.findall(r"<h3>(.*?)</h3>", d.get("html",""))
print("listings on /jobs:", len(titles))
for t in titles[:10]: print("  -", t)
assert titles, "no listings rendered"
'
echo "== single listings and apply links"
for id in $(wp post list --post_type=job_listing --post_status=publish --format=ids | tr ' ' '\n' | head -3); do
  url=$(wp eval "echo get_permalink($id);")
  apply=$(wp post meta get "$id" _application)
  page=$(curl -s "$url")
  grep -qF "$apply" <<<"$page" && inpage=yes || inpage=no
  grep -q 'application_button' <<<"$page" && button=yes || button=no
  status=$(curl -s -o /dev/null -L --max-time 20 -A 'Mozilla/5.0 (link check)' -w '%{http_code}' "$apply" || true)
  echo "listing $id: apply button=$button, apply link in page=$inpage, employer apply page HTTP $status ($apply)"
  [ "$button" = yes ] && [ "$inpage" = yes ] || { echo "FAIL: apply link missing"; exit 1; }
done
wp joawpjm status
echo "LIVE SMOKE: OK"
