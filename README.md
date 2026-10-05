# Job Opportunities API for WP Job Manager

A WordPress plugin that imports employer-direct job postings from the **Job Opportunities API (JOA)** into
[WP Job Manager](https://wordpress.org/plugins/wp-job-manager/) listings on a schedule, so a job board gets fresh
jobs from employers' own applicant tracking systems and career sites with zero code.

- Saved searches (keywords, title, description words, countries, city, remote/hybrid/on-site, employment type,
  seniority, category, company slugs or domains, salary stated), each with its own max jobs per run, job type
  (fixed or mapped automatically), category and author.
- WP-Cron imports (hourly / twice daily / daily), a "Run import now" button and WP-CLI
  (`wp joawpjm import [--search=<id>] [--dry-run]`, `wp joawpjm status`).
- One listing per JOA job, secondary de-duplication on the employer apply link, run lock against overlaps,
  incremental requests (`posted_after`) after the first run.
- Closed roles expire automatically: `/v1/jobs/expired` feed when the plan has it, otherwise a configurable
  number of per-listing checks per run. Locked listings keep admin edits but still expire.
- Attribution lines required by the data licence are always shown; the plugin's own credit link is opt-in.
- No telemetry, no remote assets, no image downloads.

Coverage figures: https://jobopportunitiesapi.org/coverage · API docs: https://jobopportunitiesapi.org/docs ·
free key (no card required): https://jobopportunitiesapi.org/register

## Install

1. Install and activate WP Job Manager.
2. Upload `dist/job-opportunities-api-for-wp-job-manager-1.0.0.zip` (Plugins > Add New > Upload) and activate.
3. Job Listings > JOA Import: save your API key, click **Test connection**, add a saved search, **Run import now**.

## Repository layout

| Path | What |
|---|---|
| `job-opportunities-api-for-wp-job-manager/` | the plugin (this folder is what ships) |
| `tests/` | mock API (`mock/router.php`), `setup.sh`, `run-e2e.sh`, `run-live.sh`, `screenshots.py` |
| `docker-compose.yml` | local test site (project `joawp`, 127.0.0.1:8088 only) |
| `phpcs.xml.dist` | WordPress Coding Standards config |
| `wporg-assets/` | wordpress.org icons, banners and screenshots |
| `dist/` | the release zip |

## Run the tests

Requires Docker with the compose plugin and Python 3.

```bash
./tests/setup.sh          # WordPress + WP Job Manager + this plugin on http://127.0.0.1:8088
./tests/run-e2e.sh        # end-to-end tests against the mock API (no network calls to JOA)

# Live smoke test against the real API (fewer than 10 calls). The key stays in the environment:
set -a; . ~/.config/joa-integrations/test.env; set +a   # provides JOA_API_KEY
./tests/run-live.sh

docker compose down       # stop (volumes kept)
```

Static checks:

```bash
docker compose exec cli wp plugin install plugin-check --activate
docker compose exec cli wp plugin check job-opportunities-api-for-wp-job-manager
# PHPCS with WordPress-Coding-Standards 3 + PHPCompatibilityWP (composer image):
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c \
  'composer global require -q wp-coding-standards/wpcs:^3 phpcompatibility/phpcompatibility-wp:* dealerdirect/phpcodesniffer-composer-installer -W && ~/.composer/vendor/bin/phpcs --standard=phpcs.xml.dist'
```

## License

GPL-2.0-or-later. Author: Loukas Tzekos <support@jobopportunitiesapi.org>, https://jobopportunitiesapi.org
