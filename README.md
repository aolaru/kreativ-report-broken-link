# Kreativ Report Broken Link

Let visitors flag broken links without crawling your site. Review reports in your WordPress dashboard and receive optional email alerts.

## Features

* Report a page in one click, with an optional setting to let visitors include the exact broken URL.
* Filter internal and external link reports.
* Resolve, ignore, or reopen reports.
* Export reports to CSV.
* Choose supported post types and email notifications.
* Control IP masking and automatic report retention.

## Installation

1. Install and activate the plugin.
2. Open **Broken Links > Settings** to choose post types and notifications.

Enable **Broken Link URL Field** in Settings to offer visitors a collapsed optional URL field. It is disabled by default, including on existing installations.

The report button appears at the end of content on enabled posts, pages, and public custom post types. The plugin relies on visitor reports and does not automatically scan links.

Reports store the page URL, optional broken URL, timestamp, visitor IP, and a site-salted IP hash for duplicate protection. IP masking applies to new reports. Retention cleanup uses WP-Cron; 0 days keeps reports indefinitely.

## Development Checks

Run frontend regression checks with Node.js 18 or newer:

```sh
node --test tests/frontend.cjs
```

Run WordPress integration checks against a disposable installation with an administrator named `testadmin` and the option `krbl_isolated_test_site` set to `yes`:

```sh
KRBL_TEST_WP_ROOT=/path/to/disposable/wordpress php tests/integration.php
```

The integration suite replaces the plugin's reports table and modifies plugin settings in that test installation. Never point it at a production database. Keep `tests/` out of installable release packages.
