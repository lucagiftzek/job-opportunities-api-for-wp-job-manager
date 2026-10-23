#!/usr/bin/env bash
# Brings up the local test site (docker compose project "joawp") and installs
# WordPress, WP Job Manager and this plugin. Idempotent.
set -euo pipefail
cd "$(dirname "$0")/.."

ADMIN_PASS="${JOAWP_ADMIN_PASS:-joawp-local-test}"   # throwaway local-only test site
docker compose up -d

wp() { docker compose exec -T cli wp "$@"; }

for _ in $(seq 1 60); do
  docker compose exec -T cli test -f /var/www/html/wp-config.php && break
  sleep 2
done
sleep 3

if ! wp core is-installed >/dev/null 2>&1; then
  wp core install --url=http://127.0.0.1:8088 --title="Job Board Demo" \
    --admin_user=admin --admin_password="$ADMIN_PASS" --admin_email=admin@example.com --skip-email
fi
wp plugin is-installed wp-job-manager || wp plugin install wp-job-manager
wp plugin activate wp-job-manager
# WP Job Manager 2.4.8+ only registers the job_listing_type taxonomy when
# "Enable listing types" is saved in its settings: the checkbox defaults to on
# ("1"), but a fresh install never stores the option, so the taxonomy (and with
# it the tests' job type mapping) silently goes missing. Store the default.
wp option update job_manager_enable_types 1 >/dev/null
wp plugin activate job-opportunities-api-for-wp-job-manager

# Pretty permalinks (the wordpress image ships mod_rewrite).
wp rewrite structure '/%postname%/' >/dev/null
docker compose exec -T cli sh -c 'cat > /var/www/html/.htaccess <<EOF
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF'

# A /jobs page with the WP Job Manager [jobs] shortcode.
JOBS_PAGE=$(wp post list --post_type=page --name=jobs --field=ID --format=ids)
if [ -z "$JOBS_PAGE" ]; then
  JOBS_PAGE=$(wp post create --post_type=page --post_title=Jobs --post_name=jobs --post_status=publish --post_content='[jobs]' --porcelain)
fi
wp option update job_manager_jobs_page_id "$JOBS_PAGE" >/dev/null
wp rewrite flush >/dev/null

echo "WordPress $(wp core version), WP Job Manager $(wp plugin get wp-job-manager --field=version), PHP $(docker compose exec -T wordpress php -r 'echo PHP_VERSION;')"
