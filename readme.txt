=== Jetpack Sync Quiet ===
Contributors: indianopinion
Tags: jetpack, sync, performance, loopback, cron
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Stops Jetpack's dedicated-sync loopback requests. Jetpack Sync keeps running on its cron event and at the end of write requests.

== Description ==

With dedicated sync on, Jetpack sends an HTTP request from your site to itself after almost every page view while its sync queue has items. On a busy site the queue always has items, so the loopbacks never stop, and each one boots WordPress in full.

This plugin makes Jetpack read dedicated sync as off, through WordPress's own option filter. Nothing is written to Jetpack's settings and no Jetpack file is changed. Sync keeps working: Jetpack sends its queue on its own cron event and at the end of POST, admin and WP-CLI requests.

Measured on a news site modifying about 9,000 posts a day: the loopback was 173,358 requests in 14 days, 12.8% of all origin requests.

Features:

* Works as a must-use plugin (preferred) or a normal plugin, single site or multisite.
* Detects the Jetpack Sync package, whichever plugin bundles it.
* Keeps Jetpack's sync_via_cron on, so sync does not stall.
* Optional tuning of queue size, queue lag, batch size, cron interval and cron time limit, with range checks.
* A guard that notices if a Jetpack update stops the filter from working.
* Read-only status page under Tools, and a WP-CLI command: wp jetpack-sync-quiet status.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

== Installation ==

As a normal plugin: upload the jetpack-sync-quiet folder to wp-content/plugins/ and activate it.

As a must-use plugin: copy the jetpack-sync-quiet folder to wp-content/mu-plugins/, then copy mu-loader/jetpack-sync-quiet-loader.php to wp-content/mu-plugins/.

Then run: wp jetpack-sync-quiet status

== Frequently Asked Questions ==

= Does this turn Jetpack Sync off? =

No. Sync keeps running on Jetpack's cron event and at the end of write requests.

= Why does status show the stored value as 1? =

WordPress.com can ask Jetpack to use dedicated sync, and Jetpack stores that. The plugin filters the read and leaves the stored value alone.

= How do I roll back? =

Delete the plugin. Nothing was written, so Jetpack goes back to its stored setting at once.

== Changelog ==

= 0.1.0 =
* First release.
