=== Kreativ Report Broken Link ===
Contributors: anolaru
Tags: broken links, link report, seo, link checker, website health
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple, one-click way for visitors to report broken links directly from your posts.

== Description ==
Kreativ Report Broken Link lets your readers report broken links with one click.
Each report is automatically saved in your dashboard and emailed to you.

**Perfect for:**
- Maintaining a clean website
- Improving SEO by finding dead URLs
- Collecting feedback from readers
- Monitoring affiliate links and external resources

== Features ==
* Automatic “Report Broken Link” button added to every post
* One-click AJAX reporting — no form fields needed
* Dashboard with sortable reports and status management (New, Resolved, Ignored)
* Email notifications to admin or a custom email address
* Lightweight and GDPR-friendly (no cookies, minimal data)
* Pagination for easier management of large numbers of reports

== Installation ==
1. Upload the plugin ZIP via *Plugins → Add New → Upload*.
2. Activate the plugin.
3. Go to *Kreativ Broken Links → Settings* to configure your notification email.

== Frequently Asked Questions ==

= Does it slow down my site? =
No. The plugin loads a tiny inline JavaScript **only on single posts**, and only when needed.

= Does it collect personal data? =
No user accounts or cookies. Only the reporting visitor’s IP is stored (required for anti-spam), and you can delete reports anytime.

= Can I customize the button? =
Yes — you can override the CSS in your theme.

== Changelog ==

= 1.2.0 =
* Security improvements based on WordPress.org review feedback.
* Added full sanitization for GET, POST, and SERVER inputs.
* Added strict nonce validation (sanitize + wp_verify_nonce).
* Updated all action names and slugs to use the 4+ character prefix (krbl_).
* Fixed pagination URL handling.
* Improved escaping in admin table output.
* Matching PHPCS/PluginCheck compliance.
* General cleanup and prep for public release.

= 1.1.0 =
* Added pagination to the Reports admin table for large datasets.
* Improved database query performance.
* Compatibility improvements for WordPress 6.8.
* Additional sanitization for admin actions.

= 1.0.0 =
* Market-ready release.
* Added status management (New, Resolved, Ignored).
* Added filters for status views (All, New, Resolved, Ignored).
* Improved email notification formatting.
* Added settings page for notification email.
* Added uninstall cleanup and improved sanitization.

= 0.2.0 =
* Added admin Reports screen.
* Added email notifications when a broken link is reported.
* Added plugin settings page.

= 0.1.0 =
* Added one-click AJAX "Report Broken Link" button to posts.
* Added database table for storing broken link reports.

= 0.0.1 =
* Initial internal version.
* Base plugin structure and activation hook.
