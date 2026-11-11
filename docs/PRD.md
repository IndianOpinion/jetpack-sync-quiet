# PRD: jetpack-sync-quiet

Status: draft for review. Version target: 0.1.0.

This document describes behaviour, not code. Names of Jetpack settings, filters and hooks below are Jetpack's own public names, cited to the Jetpack source on GitHub. Names that start with `jetpack_sync_quiet` are this project's own public interface.

Sources (Automattic/jetpack, branch `trunk`, sync package):

- [S1] `projects/packages/sync/src/class-settings.php`: https://github.com/Automattic/jetpack/blob/trunk/projects/packages/sync/src/class-settings.php
- [S2] `projects/packages/sync/src/class-defaults.php`: https://github.com/Automattic/jetpack/blob/trunk/projects/packages/sync/src/class-defaults.php
- [S3] `projects/packages/sync/src/class-dedicated-sender.php`: https://github.com/Automattic/jetpack/blob/trunk/projects/packages/sync/src/class-dedicated-sender.php
- [S4] `projects/packages/sync/src/class-actions.php`: https://github.com/Automattic/jetpack/blob/trunk/projects/packages/sync/src/class-actions.php
- [S5] `projects/packages/sync/src/class-sender.php`: https://github.com/Automattic/jetpack/blob/trunk/projects/packages/sync/src/class-sender.php
- [S6] `projects/packages/sync/src/class-listener.php`: https://github.com/Automattic/jetpack/blob/trunk/projects/packages/sync/src/class-listener.php
- [S7] `projects/packages/sync/src/class-queue.php`: https://github.com/Automattic/jetpack/blob/trunk/projects/packages/sync/src/class-queue.php
- [S8] `projects/plugins/jetpack/class.jetpack-cli.php` (`wp jetpack sync`): https://github.com/Automattic/jetpack/blob/trunk/projects/plugins/jetpack/class.jetpack-cli.php
- [W1] WordPress `pre_option_{$option}` filter: https://developer.wordpress.org/reference/hooks/pre_option_option/
- [W2] WordPress must-use plugins: https://developer.wordpress.org/advanced-administration/plugins/mu-plugins/

The builder must re-read S1 to S8 at build time and record the Jetpack version they checked against in `docs/ARCHITECTURE.md`. Jetpack moves; the facts below were read on 11 November 2026.

## 1. Problem

Jetpack Sync keeps a queue of changes on the site and sends it to WordPress.com. With "dedicated sync" on, every request that ends with items left in the queue fires a non-blocking HTTP GET from the site to itself, to the REST route `jetpack/v4/sync/spawn-sync`, with a cache-busting `time` parameter [S3]. That loopback boots WordPress in full, sends a batch, and spawns the next loopback if more items remain [S5].

On a site that writes a lot (imports, feeds, bulk edits, automated publishing) the queue is never empty, so almost every request fires a loopback, and the loopbacks fire more loopbacks. Page caches cannot help: the `time` parameter makes each one unique, and they are REST requests.

Measured result: on a news site modifying about 9,000 posts a day, the spawn-sync loopback was the most requested URL on the origin: 173,358 requests in 14 days, 12.8% of all origin requests, each a full WordPress boot.

Jetpack already has a quieter flow: with dedicated sync off, the queue is sent at the end of POST, admin and WP-CLI requests and by its own WP-Cron event [S4]. This plugin switches the site to that flow and keeps it there.

## 2. Goals and non-goals

Goals:

1. Make Jetpack read "dedicated sync off" on every request, using Jetpack's own setting and WordPress's public option filter, without editing Jetpack and without changing the stored value.
2. Keep sync working: queued changes still reach WordPress.com through Jetpack's cron event and end-of-request sender.
3. Let the operator tune queue size, queue lag limit, batch size, cron interval and cron time limit through Jetpack's own settings, with validation.
4. Show the operator the state: dedicated sync effective and stored values, queue size, queue lag, last successful sync, next cron run, cron health. Through WP-CLI and a read-only admin page.
5. Detect and survive Jetpack resetting the setting (plugin update, WordPress.com header, remote settings write).
6. Do nothing, and say so, when the Jetpack Sync package is absent, inactive, disconnected or disabled.
7. Ship a documented, repeatable measurement method.

Non-goals:

- Replacing, forking or reimplementing Jetpack Sync.
- Touching the Jetpack connection, tokens, or the WordPress.com side.
- Turning sync off. Features such as Jetpack Social and Subscriptions need it.
- Blocking the spawn-sync route at the web server or in PHP. (The docs may mention it as an operator choice; the plugin does not do it.)
- Editing any Jetpack file, or writing to Jetpack's queue.
- Starting full syncs.

## 3. Users and setting

- Who installs it: site operators and agencies running WordPress with Jetpack (or another plugin that bundles the Jetpack Sync package) on their own hosting, usually on sites with heavy automated writes, and anyone who sees `spawn-sync` near the top of their access log.
- Where it runs: preferably as a must-use plugin (one file or a folder plus loader in `wp-content/mu-plugins/`), so it cannot be deactivated by accident and loads before every normal plugin [W2]. It also works as a normal plugin, single site or network-activated.
- Runs in PHP 8.1 or later, WordPress 6.4 or later. No Composer at runtime.
- Operator tools: WP-CLI and wp-admin. No front-end output.

## 4. Behaviour

### 4.1 Jetpack facts this plugin relies on

F1. Every sync setting is stored as a WordPress option named `jetpack_sync_settings_` plus the setting name, and read with `get_option` (or `get_site_option` for names starting `network_`, on multisite only) [S1]. There is no Jetpack filter on the settings themselves [S1]. The WordPress filter `pre_option_{$option}` short-circuits `get_option` before the database is read [W1].

F2. The setting is `dedicated_sync_enabled`, option `jetpack_sync_settings_dedicated_sync_enabled`, read through the Jetpack settings class helper that checks whether dedicated sync is enabled [S1]. Jetpack's built-in default is `0` [S2]. It is not a `network_` setting, so it is per site.

F3. Jetpack turns it on or off by itself in three ways: (a) WordPress.com returns a `Jetpack-Dedicated-Sync` response header (`on` or `off`) on sync sends, which Jetpack applies [S4, S3]; (b) when queue lag passes 30 minutes Jetpack runs a blocking validation loopback (30 second timeout) that must return `DEDICATED SYNC OK`, and on failure writes `0` and sets a 6-hour temporary-disable transient [S3]; (c) any call to Jetpack's settings update method, including remote settings writes from WordPress.com [S1]. When the stored value is `false` (missing), Jetpack writes the default back with autoload [S1].

F4. When dedicated sync is enabled and the request is not cron, Jetpack's sender hands the sync queue to the dedicated sender instead of sending in-process. The dedicated sender spawns only if: dedicated sync is enabled, the queue is not locked, the queue is not empty, sending is not temporarily disabled, no retry-after is pending, the throttle allows, and a 60-second spawn lock is free [S3, S5].

F5. With dedicated sync off, Jetpack's end-of-request sender runs on `shutdown` (priorities 9998 and 9999) only for: cron requests when `sync_via_cron` is on, POST requests, users with `manage_options`, admin requests, and WP-CLI [S4]. The filter `jetpack_sync_sender_should_load` can widen or narrow that [S4].

F6. `sync_via_cron` (default `1`) schedules the WP-Cron hooks `jetpack_sync_cron` and `jetpack_sync_full_cron` on the schedule `jetpack_sync_interval` (every 300 seconds). The schedule name is filterable with `jetpack_sync_incremental_sync_interval` and `jetpack_sync_full_sync_interval`; an unregistered name falls back to `jetpack_sync_interval` [S4]. Each cron run sends batches until the queue empties or `cron_sync_time_limit` (default 240 seconds) passes [S2, S4]. If `sync_via_cron` is off, Jetpack clears both cron hooks [S4].

F7. Tunable settings and defaults [S2]: `max_queue_size` 5000, `max_queue_lag` 7200 seconds, `upload_max_rows` 500, `upload_max_bytes` 600000, `dequeue_max_bytes` 500000, `sync_wait_time` 10 seconds, `sync_wait_threshold` 10 seconds, `queue_max_writes_sec` 100, `cron_sync_time_limit` 240 seconds. Numeric values are cast to integers on read [S1].

F8. When the queue is over its size and lag limits, the listener drops the new item rather than enqueueing it and reports a data-loss event to WordPress.com [S6]. Raising limits therefore protects against lost changes; lowering them risks it.

F9. Jetpack exposes status through its public sync status method: `queue_size`, `queue_lag`, `queue_next_sync`, `cron_size`, `next_cron`, and, in the debug details, `dedicated_sync_enabled` and the last successful sync time [S4]. `wp jetpack sync status` prints the same [S8]. `wp jetpack sync settings` prints settings read-only [S8].

### 4.2 Cases

Each case has an id (C1 to C30) used in section 12.

Core switch:

C1. Jetpack Sync present, dedicated sync stored as `1`. The plugin makes every read of `dedicated_sync_enabled` return `0` by filtering `pre_option_jetpack_sync_settings_dedicated_sync_enabled` [F1, W1]. The stored value stays `1`. No loopback is spawned [F4].

C2. Dedicated sync stored as `0` or missing. Same filter; reads return `0`. Because the read is short-circuited, Jetpack's "write the default back" path does not run [F3], so the plugin causes no database write.

C3. The filter returns the string `"0"`, never boolean `false`: `false` from a `pre_option` filter means "not short-circuited" and would let the stored value through [W1].

C4. Removing the plugin (deleting the mu-plugin file or deactivating) restores the stored value at once, because nothing was written. This is the documented rollback.

C5. Sync still flows: on POST, admin, manage_options and WP-CLI requests, and on `jetpack_sync_cron` runs, Jetpack's shutdown sender sends the queue in-process [F5, F6]. The plugin adds no sender of its own.

C6. A spawn-sync request already in flight when the plugin is installed is handled by Jetpack as normal. The plugin does not block the route. Once that request ends it does not respawn, because the spawn path checks the setting first [F4].

Resets by Jetpack (the guard):

C7. WordPress.com sends `Jetpack-Dedicated-Sync: on`; Jetpack stores `1` [F3a]. The filter still wins on every read. The guard records a "drift" event (stored value differs from effective value) with time and source "stored value changed", and does not fight the write.

C8. Jetpack is updated (plugin upgrade, auto-update, or a plugin that bundles the sync package is updated). After `upgrader_process_complete`, and once a day on a WP-Cron event of this plugin, the guard checks Jetpack's own reader of the setting (the settings class helper in F2), not just the option. If Jetpack's reader says enabled while the plugin is active, the filter has stopped working (Jetpack renamed the option or changed how it reads). The guard then falls back: it writes `0` through Jetpack's public settings update method, records a "fallback applied" event, and shows an admin notice and a CLI warning naming the Jetpack version. This is the only case in which the plugin writes a Jetpack setting.

C9. Jetpack's settings update method refuses or reverts the fallback write (it validates on enable, not disable, today [S1], but that may change). The guard records "fallback failed", keeps the notice up, and does not retry more than once per day.

C10. Jetpack removes the setting entirely in a future version (no dedicated sync). Jetpack's reader is gone; the guard reports "dedicated sync not present in this Jetpack version; nothing to do" and does nothing else.

C11. Remote settings update from WordPress.com writes `dedicated_sync_enabled` = `1` [F3c]. Same as C7.

C12. Jetpack's own failure path (blocking validation loopback, temporary disable) [F3b] is never reached, because it lives inside the spawn path, which checks the setting first. The status page says so; this removes a blocking 30-second request that otherwise fires on lagging sites.

Load order and presence:

C13. As a must-use plugin, the file loads before Jetpack [W2]. The `pre_option` filter is added at file load, so it is in place before Jetpack's sync package reads any setting. Registering a `pre_option` filter for an option nobody reads costs nothing.

C14. As a normal plugin that loads after Jetpack alphabetically. Jetpack's sync package reads settings on `init` and later, not at plugin file load [S4], so the filter is still in time. The builder confirms this against the checked Jetpack version and the test suite covers the order.

C15. Jetpack Sync package absent (Jetpack not installed, deactivated, and no other plugin bundles it). Detection is by the sync package's settings class, checked after `plugins_loaded` at a late priority, not by the Jetpack plugin slug, because Jetpack Boost, Jetpack Backup, Jetpack Social and others bundle the same package. If absent: the plugin removes its filters, schedules nothing, and status says "Jetpack Sync not found; doing nothing". No notice nags on every admin page; the status page and CLI say it.

C16. Jetpack deactivated while this plugin stays. Next request: C15. The plugin's own daily guard event unschedules itself when it finds no sync package, and is rescheduled when the package returns.

C17. Jetpack present but not connected to WordPress.com, or sync disabled by Jetpack's `disable` or `network_disable` setting. The filter stays (harmless). Status reports "sync inactive: not connected" or "sync disabled by Jetpack setting" and the guard does not write anything.

C18. Jetpack reactivated or reconnected. Filters are active from the first request; nothing to migrate.

Tuning:

C19. Operator sets a tunable (from the list in F7 plus the cron interval). The plugin applies it by the same `pre_option` method on `jetpack_sync_settings_<name>`, only for settings the operator set. Unset means "leave Jetpack's value alone". Each value is validated against a range (section 7); an out-of-range or non-integer value is rejected at configuration time with a clear message, and at runtime is ignored with a logged warning, falling back to Jetpack's value (fail open to Jetpack's behaviour, because a bad number could drop sync data [F8]).

C20. Cron interval. The operator gives seconds (60 to 3600). The plugin registers a WP-Cron schedule of that length under its own name and returns that name from `jetpack_sync_incremental_sync_interval` [F6]. If the schedule is not registered at the time Jetpack asks, Jetpack falls back to its 5-minute default [F6], which is safe. The full-sync interval is left alone unless the operator sets it separately.

C21. Operator sets `sync_via_cron` off through Jetpack or another plugin while dedicated sync is off. Sync then runs only on POST, admin and CLI requests [F5], which on a read-heavy site with automated writes from outside WordPress can stall. By default the plugin forces `sync_via_cron` to `1` through the same filter and says so in status; the operator can turn this off with a documented constant or filter.

WP-Cron variants:

C22. `DISABLE_WP_CRON` true with a system cron calling `wp-cron.php` or `wp cron event run --due-now`. Jetpack's cron sender runs on those calls (they set `DOING_CRON`) [F5]. Status reads `DISABLE_WP_CRON`, shows the age of the oldest due cron event, and reports "system cron: ok" if `jetpack_sync_cron` ran within twice its interval.

C23. `DISABLE_WP_CRON` true and no system cron (the classic misconfiguration). `jetpack_sync_cron` is overdue. Status warns "WP-Cron is not running; sync only happens on POST, admin and CLI requests" and the CLI status exits non-zero with `--strict`. The plugin does not try to run cron itself.

C24. WP-Cron enabled but driven by visitor traffic, and the site has little traffic. Same check as C22; warns when overdue.

C25. Sync cron overruns: `cron_sync_time_limit` (default 240 s) is longer than a host's PHP `max_execution_time` when cron runs over HTTP. Status compares the two and warns if the limit is higher than `max_execution_time` minus a margin. The operator can lower `cron_sync_time_limit` (C19).

Environment:

C26. Loopbacks already blocked (firewall, basic auth on staging, host forbids self-requests, `DISABLE_WP_CRON` plus closed loopback). Without the plugin, Jetpack keeps trying non-blocking loopbacks that fail, lag grows past 30 minutes, the blocking 30-second validation runs and fails, Jetpack disables dedicated sync for 6 hours, then WordPress.com may turn it back on [F3]: it flaps. With the plugin, there are no attempts at all and no flapping. Status notes that the gain on such a site is fewer failed outbound attempts and no 30-second checks, not fewer origin hits.

C27. Multisite, plugin as mu-plugin or network-activated. The filter applies to every site because the setting is per site and the filter is per option name [F2]. Status and CLI work per site (`--url`) and CLI offers `--network` to list every site in one table. Tunables can be set network-wide (site option) with a per-site override; the per-site value wins.

C28. Multisite, plugin activated on one site only. Only that site is affected. Status says "this site only".

C29. Object cache present (Redis, Memcached). `pre_option` runs before the object cache is consulted [W1], so no stale cached value can leak through. Jetpack's spawn lock uses the object cache when present [S3]; irrelevant once spawning is off.

C30. Request is itself a spawn-sync request (route `jetpack/v4/sync/spawn-sync` or `rest_route` form) arriving from a stale process or an external caller. Jetpack handles it. The plugin does nothing to it, and status counts nothing (no logging of requests).

### 4.3 Failure modes

- Filter registration fails (fatal in this plugin): the site falls back to Jetpack's stored behaviour. The plugin is written so it cannot fatal: no Composer, no autoload outside its folder, PHP version check at file load that turns the plugin into a no-op with an admin notice on PHP below 8.1. Fail open, because the alternative (taking the site down) is worse than loopbacks.
- Jetpack internals change (class or method used for status or fallback is missing): status shows "unknown" for that field and a one-line explanation. Never fatals. The switch itself depends only on the option name and WordPress core, so it keeps working.
- Guard fallback write fails: notice and log, retry once a day (C9).
- Invalid tunable: ignored, warning, Jetpack value used (C19). Fail open to Jetpack defaults.
- Cron not running: warn only (C23). The plugin never re-enables dedicated sync to compensate; the operator decides.

### 4.4 Measurement procedure

Documented in the README and in `docs/MEASURING.md`:

1. Before installing, take at least 7 days of web server access logs from the origin (not the CDN).
2. Count requests whose path is `/wp-json/jetpack/v4/sync/spawn-sync` or whose query has `rest_route=/jetpack/v4/sync/spawn-sync`, per day. Count all requests per day. Record the share.
3. Record `queue_size` and `queue_lag` from `wp jetpack sync status` a few times a day.
4. Install the plugin. Wait 7 days. Repeat steps 2 and 3.
5. Expected: spawn-sync count drops to near zero within one minute of install (in-flight requests only, C6). Queue lag stays within a few cron intervals.
6. Check sync still works end to end: publish a test post and confirm it reaches WordPress.com (for example a Jetpack Social share or the post in the WordPress.com dashboard) within two cron intervals.

The README gives a one-line shell example using standard tools (grep and wc, or awk) for an nginx combined log, and states the production figure in one line: 173,358 loopbacks in 14 days, 12.8% of origin requests, on a site modifying about 9,000 posts a day.

## 5. Public interface

Plugin: name "Jetpack Sync Quiet", slug `jetpack-sync-quiet`, namespace `IndianOpinion\JetpackSyncQuiet`. Loader file usable directly in `mu-plugins/` or as a normal plugin.

Constants (optional, in `wp-config.php`, read once at load):

- `JETPACK_SYNC_QUIET_DISABLE` (bool): turn this plugin into a no-op without removing it. Default false.
- `JETPACK_SYNC_QUIET_FORCE_CRON` (bool): force `sync_via_cron` on (C21). Default true.
- `JETPACK_SYNC_QUIET_GUARD_FALLBACK` (bool): allow the guard to write `0` through Jetpack when the filter stops working (C8). Default true.
- `JETPACK_SYNC_QUIET_SETTINGS` (array): tunables, keys from section 7. Overrides stored settings.

Filters (each default can be changed):

- `jetpack_sync_quiet_enabled` (bool, default true): master switch per request.
- `jetpack_sync_quiet_force_cron` (bool, default from constant).
- `jetpack_sync_quiet_settings` (array): final tunables after constant, network and site values are merged.
- `jetpack_sync_quiet_guard_fallback` (bool).
- `jetpack_sync_quiet_status` (array): status fields before display.

Actions:

- `jetpack_sync_quiet_drift_detected` (stored value, effective value, source).
- `jetpack_sync_quiet_fallback_applied` (Jetpack version, result).

WP-CLI (`wp jetpack-sync-quiet`):

- `status [--format=table|json|yaml] [--strict] [--network]`: fields in section 11. `--strict` exits 1 when any warning is present.
- `settings get|set|unset <key> [<value>] [--network]`: read and write tunables, with range validation.
- `guard run`: run the guard now and print the result.
- `purge`: delete everything this plugin stored (for mu-plugin installs, which have no uninstall hook).

Admin page: Tools, "Jetpack Sync Quiet". Read-only status table, a "Run guard now" button (nonce plus `manage_options`), and a link to the measurement doc. On multisite also under Network Admin, Settings, with `manage_network_options`.

Uninstall: `uninstall.php` for normal plugin installs; `purge` for mu-plugin installs.

## 6. Data

- One site option (autoload off) for this plugin's tunables, and on multisite one network option for network-wide tunables. Only keys the operator set.
- One site option (autoload off) for guard state: last check time, last drift event (time, stored value, source), last fallback result, Jetpack version seen. Overwritten, not appended; no history beyond the last event of each kind.
- One WP-Cron event of its own (the daily guard), plus one custom cron schedule if the interval tunable is set.
- Nothing in Jetpack's options is written, except the single fallback write in C8.
- No request logs, no personal data, no custom tables.
- Uninstall and `purge` remove both options, the cron event and the schedule. They do not touch Jetpack's stored `dedicated_sync_enabled`; that value goes back to governing Jetpack, and the uninstall message says so.

## 7. Configuration and sensible defaults

Default install, no configuration: dedicated sync reads off, `sync_via_cron` forced on, guard on with fallback allowed, all other Jetpack settings untouched.

Tunables (all optional; unset means Jetpack's value). Ranges are the builder's starting point and are documented in the README:

| Key | Jetpack setting | Jetpack default [S2] | Allowed range |
|---|---|---|---|
| `max_queue_size` | `max_queue_size` | 5000 | 1000 to 100000 |
| `max_queue_lag` | `max_queue_lag` | 7200 s | 900 to 86400 s |
| `upload_max_rows` | `upload_max_rows` | 500 | 10 to 2000 |
| `upload_max_bytes` | `upload_max_bytes` | 600000 | 100000 to 5000000 |
| `cron_sync_time_limit` | `cron_sync_time_limit` | 240 s | 30 to 900 s |
| `sync_wait_time` | `sync_wait_time` | 10 s | 0 to 300 s |
| `cron_interval` | schedule via `jetpack_sync_incremental_sync_interval` | 300 s | 60 to 3600 s |

Precedence: constant, then site option, then network option, then the `jetpack_sync_quiet_settings` filter sees the merged result and has the last word.

Advice in the README for a high-write site: raise `max_queue_size` before anything else (F8), keep the cron interval at 5 minutes or lower, and use a system cron.

## 8. Security and privacy

- No HTTP requests of its own. No remote calls.
- Admin page and the "Run guard now" action: `manage_options` (`manage_network_options` on network admin), nonce checked, all output escaped.
- WP-CLI writes validate every key and value against the allow list and ranges in section 7.
- No `eval`, no shell, no error suppression.
- Does not read or display tokens, connection secrets or queue contents. Status shows counts and times only.
- Does not change who can reach the spawn-sync route.

## 9. Performance budget

- Per request: one or a few `pre_option` filter callbacks returning constants. No database query, no remote call. Target under 0.05 ms.
- Status page and CLI: only reads Jetpack's status method and core cron data, on demand.
- Guard: at most once per day plus once after each upgrade; under 50 ms.
- Expected effect: removes one full WordPress boot for most requests that leave the sync queue non-empty (see section 1 for the measured share).

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 and later.
- Jetpack: the version range the builder tested against, recorded in ARCHITECTURE.md and the README; any plugin that bundles the Jetpack Sync package (C15).
- Single site and multisite.
- Must-use plugin (preferred) or normal plugin.
- Any object cache (C29). Any page cache: no effect, the plugin emits no headers.
- Hosts that block loopbacks (C26), hosts with `DISABLE_WP_CRON` and a system cron (C22).
- Managed hosts that disable or proxy WP-Cron: status reports what it sees; no special-casing.

## 11. Observability

Status fields (CLI and admin page):

- Plugin: active or no-op, and why (C15, C17, disabled by constant).
- Install type: must-use, normal, network-activated, this site only.
- Jetpack Sync: present, version, connected, sync enabled or disabled.
- Dedicated sync: effective value (what Jetpack reads), stored value, match or drift.
- Last drift event and last guard run and result.
- `sync_via_cron`: effective and stored, and whether forced.
- Queue size, queue lag (seconds and human), last successful sync time and its age, next sync time [F9].
- Next run of `jetpack_sync_cron`, schedule in use, overdue or ok.
- `DISABLE_WP_CRON` value and system cron health (C22, C23).
- Tunables in effect with their source (Jetpack default, constant, site, network, filter).
- Warnings list (C21, C23, C25, fallback failure).

Logging: drift, fallback and invalid tunables are written with `error_log` once per event (not per request) with the prefix `jetpack-sync-quiet:`. Admin notice only for fallback applied or failed.

## 12. Acceptance tests

Tests use PHPUnit 10 against a stub layer for WordPress and for the parts of the Jetpack Sync package the plugin calls. One manual check (T29) needs a real site.

| Test | Covers | Checks |
|---|---|---|
| T1 | C1 | Stored `1`: Jetpack's reader returns disabled; stored value still `1`; spawn path not entered. |
| T2 | C2 | Stored missing: read returns `0`; no option write recorded by the stub. |
| T3 | C3 | Filter return value is the string `"0"`, never `false`. |
| T4 | C4 | After filters are removed (plugin unloaded), Jetpack reader returns the stored value. |
| T5 | C5 | With dedicated off, POST, admin, CLI and cron contexts lead the stub sender to send in-process; GET front-end does not spawn. |
| T6 | C6, C30 | Plugin registers nothing on the spawn-sync REST route. |
| T7 | C7, C11 | Stored value changes to `1`: reads still `0`; guard records one drift event and fires the drift action once. |
| T8 | C8 | Stub Jetpack reader ignores the option (simulated rename): guard writes `0` through Jetpack's update method, records "fallback applied", notice shown. |
| T9 | C8 | Same as T8 with fallback disabled by constant: no write, warning only. |
| T10 | C9 | Update method returns failure: "fallback failed" recorded; second guard run on the same day does not retry. |
| T11 | C10 | Reader missing in stub: status "not present in this Jetpack version"; no write. |
| T12 | C12 | Status shows the validation path as unreachable when dedicated is off. |
| T13 | C13 | Load as mu-plugin before stub Jetpack: filter present before first settings read. |
| T14 | C14 | Load after stub Jetpack file but before `init`: first settings read still filtered. |
| T15 | C15 | No sync package: filters removed after `plugins_loaded`, no cron scheduled, status "not found". |
| T16 | C15 | Sync package present without the Jetpack plugin (bundled by another plugin): plugin active. |
| T17 | C16, C18 | Package disappears: guard event unscheduled; package returns: rescheduled. |
| T18 | C17 | Not connected, and `disable` set: status says why; guard writes nothing. |
| T19 | C19 | Valid tunable applied via `pre_option` for that setting only; unset settings untouched. |
| T20 | C19 | Out-of-range and non-integer values rejected by CLI with a message and ignored at runtime with one log line. |
| T21 | C20 | Interval 120: schedule registered at 120 s; interval filter returns its name. Schedule absent: Jetpack fallback name returned. |
| T22 | C21 | `sync_via_cron` stored `0`: reads `1` by default; with force off, reads `0` and status warns. |
| T23 | C22 | `DISABLE_WP_CRON` true and sync cron ran recently: "system cron: ok". |
| T24 | C23, C24 | Sync cron overdue: warning present; `status --strict` exits 1. |
| T25 | C25 | `cron_sync_time_limit` above `max_execution_time`: warning. |
| T26 | C26 | Loopback blocked in stub: no outbound request attempted at all. |
| T27 | C27, C28 | Multisite stub: filter effective on two sites; network tunable applies, site override wins; `--network` lists both; single-site activation affects one site. |
| T28 | C29 | Object cache stub holds `1` for the option: read still `0`. |
| T29 | 4.4 | Manual: on a test site with a non-empty queue, access log shows spawn-sync requests before install and none after; a test post reaches WordPress.com within two cron intervals. |
| T30 | 4.3 | Simulated PHP below 8.1 and missing Jetpack status method: no fatal; no-op or "unknown" fields. |
| T31 | 5, 8 | Admin action requires capability and valid nonce; output escaped. |
| T32 | 6 | Uninstall and `purge` remove both options, the cron event and the schedule; Jetpack options untouched. |
| T33 | 5 | Every documented filter changes its default as described; `JETPACK_SYNC_QUIET_DISABLE` makes the plugin a no-op. |

Every case C1 to C30 maps to at least one test above.

## 13. Open questions

Answered by the builder in `docs/ARCHITECTURE.md`:

1. Which Jetpack versions were checked, and does any supported version read settings before `init` (affects C14)?
2. Is the guard's fallback write (C8) worth having, or should the plugin only warn? Default in this PRD: write, behind a constant.
3. Should `sync_via_cron` be forced on by default (C21), or only warned about?
4. Exact way to read the last successful sync time: through Jetpack's public status method with debug details, or "unknown" if that is not public in the checked version.
5. Tunable ranges in section 7: confirm against Jetpack's own handling; are any of them capped by WordPress.com?
6. Should the full-sync cron interval be tunable separately, or left alone in 0.1.0?
7. One file for mu-plugin use plus a folder for normal use, or a folder plus a one-line mu-plugin loader?

## 14. Lessons from production

- Do not edit Jetpack and do not write its setting. Filter the read. Rollback is deleting one file.
- Return `"0"`, not `false`, from a `pre_option` filter. `false` means "carry on and read the database".
- Jetpack's default is off, yet sites still end up with it on: WordPress.com turns it on with a response header. Expect the stored value to change under you, and treat that as information, not a fault.
- Keep sync. Social sharing and subscriptions depend on it. The goal is fewer loopbacks, not less sync.
- On a high-write site the queue is never empty, so "only when the queue is non-empty" means "on almost every request".
- The loopback carries a cache-busting parameter and is a REST call: no page cache will absorb it. Every one is a full WordPress boot.
- Look at the origin access log, not the CDN: the loopback never leaves the server, so CDN analytics do not show it.
- Automated publishing from WP-CLI and cron is still synced at the end of those runs. Publish events reached WordPress.com within minutes after the change.
- Sync via cron needs cron that runs. With `DISABLE_WP_CRON`, check the system cron before relying on it.
- Detect the sync package, not the Jetpack plugin. Other plugins bundle it.
