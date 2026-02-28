=== Active Plugin Conflict Detector ===
Contributors: ridhwanahsann
Tags: debug, plugins, conflicts, tools
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Detect active plugin conflicts safely using a smart divide-and-conquer scan. Non-intrusive and visitor-safe.

== Description ==

Active Plugin Conflict Detector (APCD) helps pinpoint problematic plugins using a binary search approach without permanently deactivating them. It shows system info, active plugins, and lets you export a JSON debug snapshot.

== Features ==

* Smart scan: divide-and-conquer with REST and AJAX pings
* No persistent deactivation; visitors never see changes
* System overview and active plugins table
* Debug log parsing for recent fatal errors
* Export JSON snapshot (site info, active plugins, last 100 log lines)

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/`.
2. Activate via Plugins.
3. Open APCD menu in the dashboard.

== Frequently Asked Questions ==

**Does the scan deactivate my plugins?**  
No. It uses a runtime filter for the current request only.

== Changelog ==

= 1.0.0 =
* Initial release.

