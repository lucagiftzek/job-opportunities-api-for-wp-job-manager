=== Job Opportunities API for WP Job Manager ===
Contributors: joboppapi
Tags: jobs, job board, wp job manager, job import, recruitment
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: wp-job-manager
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import employer-direct jobs from the Job Opportunities API (JOA) into WP Job Manager on a schedule, with de-duplication and automatic expiry.

== Description ==

**Job Opportunities API for WP Job Manager** fills your WP Job Manager job board with fresh, employer-direct job postings from the Job Opportunities API (JOA), with no code.

The Job Opportunities API (JOA) collects job postings directly from employers' own applicant tracking systems and career sites. Every field is tagged as published by the employer, inferred, or absent, and closed roles are tracked, so your board can drop a listing when the employer closes the role. See [the coverage page](https://jobopportunitiesapi.org/coverage) for live figures on countries and sources.

= What it does =

* **Saved searches**: as many as you like, each with its own filters: keywords, title words, words in the description, countries, city, remote/hybrid/on-site, employment type, seniority, category, specific companies (by slug or domain) and "salary stated" only.
* **Scheduled imports** with WP-Cron (hourly, twice daily or daily), a "Run import now" button and WP-CLI commands.
* **Real WP Job Manager listings**: title, description, location, company name and website, remote flag, salary (when the employer publishes one), expiry date and job type are filled in, so your theme, search and job alerts work as usual.
* **Apply goes to the employer**: the application link is always the employer's own apply page.
* **No duplicates**: one listing per job, plus a second check on the employer's apply link so the same role found by two searches is imported once. A run lock prevents overlapping imports.
* **Automatic expiry**: when the employer closes a role, the listing is set to "Expired". Plans with the closure feed use it; other plans re-check a configurable number of listings per run.
* **Keep your edits**: lock any imported listing to stop updates from the API (a locked listing still expires when the role closes).
* **Quota-friendly**: after the first run, each search only asks for recently posted jobs, and page sizes follow your plan's limits.
* **Activity log** of the last 50 runs, with counts and errors.
* **No images are downloaded** into your media library, nothing is tracked, and no remote scripts or styles are loaded.

= Requirements =

* WP Job Manager (free, by Automattic).
* A Job Opportunities API key. A free key is available at [jobopportunitiesapi.org](https://jobopportunitiesapi.org/register), no card required.

= WP-CLI =

* `wp joawpjm import [--search=<id>] [--dry-run]` runs an import now.
* `wp joawpjm status` shows the configuration, saved searches and recent runs.

== External services ==

This plugin connects to the **Job Opportunities API (JOA)**, a job-postings data service operated by Job Opportunities API (https://jobopportunitiesapi.org). The API is required: it is where the imported jobs come from.

All requests go to `https://api.jobopportunitiesapi.org`. The plugin makes them only:

* when a scheduled import runs (WP-Cron) or an administrator clicks "Run import now" or runs `wp joawpjm import`: the plugin sends your API key and the filters of your saved searches (for example keywords, countries, remote preference, company slugs, and the date after which jobs were posted) and receives job postings;
* to check whether imported roles are still open: it sends your API key and either a time cursor (closure feed) or the ids of imported jobs, and receives their status;
* when an administrator clicks "Test connection" (and at most every 12 hours during imports): it sends your API key and receives your plan, limits and today's usage.

Your API key is sent in the Authorization header of each request. No personal data about your site's visitors or users is sent. The request's user agent identifies the plugin and its version.

* Terms of service: https://jobopportunitiesapi.org/terms
* Privacy policy: https://jobopportunitiesapi.org/privacy

Imported listings contain links to employers' websites and, when present, to employers' logo images; these are only shown or followed by your visitors like any other link on your site. Logo URLs are stored but never downloaded by the plugin.

== Installation ==

1. Install and activate **WP Job Manager**.
2. Install this plugin from Plugins > Add New (or upload the zip) and activate it.
3. Go to **Job Listings > JOA Import**, paste your API key and click **Save settings**, then **Test connection**.
4. Open the **Saved searches** tab, click **Add search**, choose your filters and save.
5. Click **Run import now** to import straight away, or wait for the schedule.

WP-Cron runs when your site gets visits. On low-traffic sites, set up a real cron job that calls `wp-cron.php` (or `wp cron event run --due-now`) so imports run on time.

== Frequently Asked Questions ==

= Do I need a paid plan? =

No. The plugin works with a free key (no card required). Each job returned counts towards your plan's monthly record allowance, so keep "Max jobs per run" and the schedule sensible. The plugin only asks for recently posted jobs after the first run.

= Where do applicants apply? =

On the employer's own site: the listing's application link is the employer's apply URL.

= Will the same job be imported twice? =

No. Each listing remembers the job's id, and a second check on the employer's apply link stops the same role arriving from two searches. Overlapping runs are prevented by a lock.

= What happens when a job closes? =

The listing is set to "Expired" in WP Job Manager. With a plan that includes the closure feed this happens at the next run; otherwise the plugin re-checks a number of listings each run (setting "Closure checks per run"). Listings also expire on their WP Job Manager expiry date as usual.

= Can I edit an imported listing? =

Yes. Tick "Lock: keep my edits" in the Job Opportunities API box on the listing's edit screen and the import will not overwrite it. It will still expire when the role closes.

= Why do some listings show a line such as "Listed via Erioun for Teams"? =

Some roles are listed by employers through a partner service whose data licence requires that attribution. The plugin always shows it on those listings and links applications to the role's own page. This is separate from the optional credit link, which is off unless you switch it on.

= Are company logos downloaded? =

No. The logo URL is stored in the listing's meta (`_joawpjm_company_logo`) for themes that want it; nothing is added to your media library.

= What is removed when I uninstall? =

All plugin settings, saved searches, logs and scheduled events. Imported listings are kept unless you enable "Delete imported listings when the plugin is uninstalled".

= Can developers change the API URL? =

Yes, with the `joawpjm_api_base` filter or the `JOAWPJM_API_BASE` constant (useful for testing). Actions `joawpjm_listing_created`, `joawpjm_listing_updated`, `joawpjm_listing_expired` and `joawpjm_import_finished` are available too.

== Screenshots ==

1. Settings: API key (masked), schedule, listing defaults and the "Test connection" and "Run import now" buttons.
2. The saved search editor with filters and listing options.
3. Imported jobs in the WP Job Manager listings screen.
4. An imported job on the front end, with the Apply button linking to the employer.
5. The activity log of recent import runs.

== Changelog ==

= 1.0.0 =
* First release: saved searches, scheduled and manual imports, WP-CLI, de-duplication, automatic expiry of closed roles, listing lock, activity log.

== Upgrade Notice ==

= 1.0.0 =
First release.
