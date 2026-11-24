# How to Let Visitors Report Broken Links in WordPress

Broken links can frustrate visitors, especially on content-heavy sites where external resources and product pages change over time. One way to catch problems is to let readers report them as they browse.

[Kreativ Report Broken Link](https://wordpress.org/plugins/kreativ-report-broken-link/) adds a report button to selected content and collects submissions in your WordPress dashboard. It does not crawl your site or automatically check URLs, so it works best alongside your regular link-maintenance process.

## 1. Install the plugin

In your WordPress dashboard, open **Plugins > Add New Plugin**, search for **Kreativ Report Broken Link**, then install and activate it. You can also find it in the [WordPress.org plugin directory](https://wordpress.org/plugins/kreativ-report-broken-link/).

## 2. Choose where the button appears

Open **Report Broken Links > Settings**. Under **Enabled Post Types**, choose the public content types where visitors should see the report button, then save your changes. Posts are enabled by default; enable pages or other public post types if you need them.

The button is added at the end of the content on enabled individual posts and pages. Its appearance may vary with your theme.

## 3. Configure notifications and the optional URL field

In the same Settings tab, choose the notification email address. Leave it blank if you prefer to review reports without email notifications.

By default, visitors report the page without specifying a URL. If you want them to be able to identify the exact link, enable **Broken Link URL Field** and save. Visitors will then see an **Add broken link URL** toggle; entering a URL remains optional.

## 4. Try a report

Open an enabled post on the front end and select **Report broken links on this page**. If you enabled the optional URL field, you can expand it and enter a complete HTTP or HTTPS URL before submitting.

Use a test report only where you are comfortable adding one to your site's reports. The plugin limits repeated reports, so an immediate repeat of the same report may be treated as a duplicate.

## 5. Review and resolve the report

Return to **Report Broken Links** in your dashboard. A report identifies the page where it was submitted and, if the visitor provided it, the reported URL. If no URL was supplied, check the page's links to identify the problem.

After investigating and fixing the link, select **Resolve**. You can also **Ignore** a report or **Reopen** one later. Filters help you review reports by status or link type, and **Export CSV** downloads the current filtered list. Reports without a supplied URL have an unknown link type.

## Privacy and retention

Reports store the page URL, optional reported URL, submission time, visitor IP address, and a site-salted IP hash used for duplicate protection. In Settings, you can enable IP anonymization for new reports and choose how long reports are retained. A retention value of zero keeps reports indefinitely; scheduled cleanup relies on WP-Cron and can be delayed.

Review these settings and your site's privacy disclosures according to your circumstances.

## Try it and share feedback

Install [Kreativ Report Broken Link from WordPress.org](https://wordpress.org/plugins/kreativ-report-broken-link/). If you find a problem or have an idea, visit the [support forum](https://wordpress.org/support/plugin/kreativ-report-broken-link/). If you've used the plugin, you're welcome to leave an [honest review](https://wordpress.org/support/plugin/kreativ-report-broken-link/reviews/#new-post).
