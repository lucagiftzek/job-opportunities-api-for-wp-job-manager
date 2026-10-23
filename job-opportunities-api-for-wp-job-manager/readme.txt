=== Job Opportunities API for WP Job Manager ===
Contributors: jobopportunitiesapi
Tags: jobs, job board, wp job manager, job import, recruitment
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: wp-job-manager
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import employer-direct jobs from the Job Opportunities API (JOA) into WP Job Manager on a schedule, with de-duplication and automatic expiry.

== Description ==

**Job Opportunities API for WP Job Manager** fills your WP Job Manager job board with fresh, employer-direct job postings from the Job Opportunities API (JOA), with no code.

The Job Opportunities API (JOA) collects job postings directly from employers' own applicant tracking systems and career sites. Every field is tagged as published by the employer, inferred, or absent, and closed roles are tracked, so your board can drop a listing when the employer closes the role. See [the coverage page](https://jobopportunitiesapi.org/coverage) for live figures on countries and sources.

If you show the imported jobs publicly, the Job Opportunities API terms ask for a visible "Data: Job Opportunities API" credit linking to jobopportunitiesapi.org. The plugin can add it to every imported listing for you, but only after you switch it on: it is off until you tick it in the settings.

= What it does =

* **Saved searches**: as many as you like, each with its own filters: keywords, title words, words in the description, countries, city, remote/hybrid/on-site, employment type, seniority, category, specific companies (by slug or domain) and "salary stated" only.
* **Scheduled imports** with WP-Cron (hourly, twice daily or daily), a "Run import now" button and WP-CLI commands.
* **Real WP Job Manager listings**: title, description, location, company name and website, remote flag, salary (when the employer publishes one), expiry date and job type are filled in, so your theme, search and job alerts work as usual.
* **Apply goes to the employer**: the application link is always the employer's own apply page.
* **No duplicates**: one listing per job, plus a second check on the employer's apply link so the same role found by two searches is imported once. A run lock prevents overlapping imports.
* **Automatic expiry**: when the employer closes a role, the listing is set to "Expired". Plans with the closure feed use it; other plans re-check a configurable number of listings per run.
* **Keep your edits**: lock any imported listing to stop updates from the API (a locked listing still expires when the role closes).
* **Quota-friendly**: after the first run, each search only asks for recently posted jobs, page sizes follow your plan's limits, and the plugin paces itself against your key's monthly record allowance so a free key lasts the whole month.
* **Activity log** of the last 50 runs, with counts and errors.
* **The data credit, on your terms**: one tick adds a small "Data: Job Opportunities API" line, linking to jobopportunitiesapi.org, under the description of each imported listing. It is off until you switch it on, and a notice on the plugin's screens and the WP Job Manager listing screens (never site-wide) reminds administrators to decide. Attribution lines that come with some roles' data are always shown.
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

No. The plugin works with a free key (no card required). Be aware that every job the API returns counts towards your key's monthly record allowance, and the free Explore plan allows 1,000 records a month. To make that allowance last, the plugin paces itself: it reads the key's remaining records from the API's responses, and each scheduled run is limited to an equal share of what is left until the allowance resets, so a free key's 1,000 records stretch across the whole month instead of being used up in the first week. A run you start by hand ("Run import now" or WP-CLI) may use everything that is still left. When a run's share is used up, it stops and the activity log says so. The plugin only asks for recently posted jobs after the first run, and plans with the closure feed check for closed roles with a time cursor instead of one request per listing.

= Where do applicants apply? =

On the employer's own site: the listing's application link is the employer's apply URL.

= Will the same job be imported twice? =

No. Each listing remembers the job's id, and a second check on the employer's apply link stops the same role arriving from two searches. Overlapping runs are prevented by a lock.

= What happens when a job closes? =

The listing is set to "Expired" in WP Job Manager. With a plan that includes the closure feed this happens at the next run; otherwise the plugin re-checks a number of listings each run (setting "Closure checks per run"). Listings also expire on their WP Job Manager expiry date as usual.

= Can I edit an imported listing? =

Yes. Tick "Lock: keep my edits" in the Job Opportunities API box on the listing's edit screen and the import will not overwrite it. It will still expire when the role closes.

= Why do some listings show a line such as "Listed via Erioun for Teams"? =

Some roles are listed by employers through a partner service whose data licence requires that attribution. The plugin always shows it on those listings and links applications to the role's own page. This is separate from the "Data: Job Opportunities API" credit, which is off until you switch it on.

= Do I have to show a credit? =

When you show the listings publicly, the Job Opportunities API terms ask for a visible "Data: Job Opportunities API" credit linking to jobopportunitiesapi.org. The plugin can add it for you, under the description of each imported listing, but only after you switch it on (tick the box at the top of the Settings tab, or click "Show the credit" in the notice), as WordPress.org rules require. Until you decide, a notice on the plugin's screens and the WP Job Manager listing screens reminds administrators; "Not now" hides it.

= Are company logos downloaded? =

No. The logo URL is stored in the listing's meta (`_joawpjm_company_logo`) for themes that want it; nothing is added to your media library.

= What is removed when I uninstall? =

All plugin settings, saved searches, logs and scheduled events. Imported listings are kept unless you enable "Delete imported listings when the plugin is uninstalled".

= Can developers change the API URL? =

Yes, with the `joawpjm_api_base` filter or the `JOAWPJM_API_BASE` constant (useful for testing). Actions `joawpjm_listing_created`, `joawpjm_listing_updated`, `joawpjm_listing_expired` and `joawpjm_import_finished` are available too.

== Screenshots ==

1. Settings: the "Data: Job Opportunities API" credit option, API key (masked), schedule, listing defaults and the "Test connection" and "Run import now" buttons.
2. The saved search editor with filters and listing options.
3. Imported jobs in the WP Job Manager listings screen.
4. An imported job on the front end, with the Apply button linking to the employer.
5. The activity log of recent import runs.

== Changelog ==

= 1.0.2 =
* New: the plugin paces a key's monthly record allowance so a free key (1,000 records a month on the free Explore plan) lasts the whole month. It reads the remaining records from the API's responses and limits each scheduled run to an equal share of what is left until the allowance resets; a run started by hand may use everything still left.
* New: when the API reports an allowance, the settings page shows the key's remaining records and when the allowance resets.
* Changed: the FAQ answer about free keys now describes how the allowance is paced.

= 1.0.1 =
* New: an opt-in "Data: Job Opportunities API" credit linking to jobopportunitiesapi.org, shown under imported listings once you switch it on. The Job Opportunities API terms ask for it when listings are displayed publicly.
* New: until you decide, a notice on the plugin's screens and the WP Job Manager listing screens (administrators only) offers "Show the credit" and "Not now".
* Changed: replaces the old optional "Jobs via Job Opportunities API" link. Sites that had it switched on now show the new credit; everyone else is asked once.

= 1.0.0 =
* First release: saved searches, scheduled and manual imports, WP-CLI, de-duplication, automatic expiry of closed roles, listing lock, activity log.

== Upgrade Notice ==

= 1.0.2 =
Scheduled imports now pace themselves to your key's remaining monthly record allowance, so a free key's allowance lasts the whole month instead of running out in the first week.

= 1.0.1 =
Adds the opt-in "Data: Job Opportunities API" credit that the Job Opportunities API terms ask for when listings are shown publicly. It stays off until you switch it on.

= 1.0.0 =
First release.
