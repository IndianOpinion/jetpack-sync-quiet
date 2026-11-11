# Architecture

This document describes how Jetpack Sync Quiet is built and records every
decision the PRD left open. Read `docs/PRD.md` first for the behaviour.

## Jetpack versions checked

Read on 11 November 2026 from the public source on GitHub:

| Jetpack | Sync package | Where read |
|---|---|---|
| trunk, 16.5-a.1 | 5.5.1 | `Automattic/jetpack`, `projects/packages/sync/src/` |
| 16.4 | 5.4.4 | `Automattic/jetpack-production`, tag `16.4`, `jetpack_vendor/automattic/jetpack-sync/src/` |
| 16.3 | 5.4.0 | `Automattic/jetpack-production`, tag `16.3`, same path |

Jetpack ships a release with a new x.y number every month, and each one is a
major release in Jetpack's own scheme, so 16.4 and 16.3 are the two previous
majors. `class-settings.php`, `class-dedicated-sender.php`,
`class-actions.php`, `class-sender.php`, `class-listener.php` and
`class-queue.php` are identical across the three. `class-main.php` differs by
one unrelated hook. `class-defaults.php` differs only in its lists of synced
option names; the defaults of the settings used here are the same.

## Layout

```
jetpack-sync-quiet.php        Loader: plugin header, PHP check, require, boot.
mu-loader/                    One-line loader to copy into wp-content/mu-plugins/.
uninstall.php                 Normal installs: removes this plugin's data on every site.
src/requirements.php          PHP version check. Parses on old PHP; no namespace.
src/bootstrap.php             require_once for every class. No Composer at runtime.
src/Plugin.php                Builds the objects and adds every hook.
src/OptionFilter.php          The switch: pre_option filters on Jetpack's options.
src/Tunables.php              Keys, ranges, storage, precedence, validation.
src/CronSchedules.php         Optional cron schedules for Jetpack's sync events.
src/Guard.php                 Drift events, the fallback write, the daily check.
src/JetpackSync.php           Every call into Jetpack, each one existence-checked.
src/Status.php                Status report for WP-CLI and the admin page.
src/Admin/AdminPage.php       Tools page, network page, "Run guard now", notices.
src/Cli/Command.php           `wp jetpack-sync-quiet`.
src/Installer.php             Purge, used by uninstall.php and the CLI.
src/Environment.php           Constants, clock, php.ini. Replaced in tests.
src/Config.php                The four wp-config.php constants, read once.
src/Logger.php                One error_log line per event, fixed prefix.
src/Format.php                Times and durations for status output.
src/Exception/                InvalidTunableException, JetpackUnavailableException.
tests/stubs/                  Stand-ins for WordPress, WP-CLI and the Jetpack Sync package.
```

## Data flow

1. WordPress loads the loader (from `mu-plugins/` through the mu-loader, or
   from `plugins/`). The loader checks PHP, requires `src/bootstrap.php` and
   calls `Plugin::boot()`.
2. `Plugin::boot()` reads the constants once, then adds the `pre_option_*`
   filters for `dedicated_sync_enabled`, `sync_via_cron` and the six numeric
   tunables, the `cron_schedules` filter and the two Jetpack interval filters.
   All of this happens at file load, before `plugins_loaded`.
3. Jetpack's sync package reads its settings for the first time on
   `plugins_loaded` at priority 90. Every read goes through `get_option()`,
   so it meets the filter. `dedicated_sync_enabled` reads `"0"`.
4. On `plugins_loaded` at priority 1000 the plugin checks for the sync package
   class `Automattic\Jetpack\Sync\Settings`. If it is missing, every filter
   and the guard event are removed.
5. On `init` (priority 20) the daily guard event is scheduled if missing, and
   invalid tunables are logged if the set has changed.
6. On `wp_loaded` the guard asks Jetpack's own reader whether dedicated sync
   is on. In the normal case the filter answers "off" and nothing else
   happens. "On" means the filter has stopped reaching Jetpack; see decision 2.
7. When anything writes the stored option, `update_option_*` and
   `add_option_*` actions record a drift event (once per event).
8. Jetpack then does what it does with dedicated sync off: the shutdown
   sender sends on cron, POST, admin, `manage_options` and WP-CLI requests,
   and the `jetpack_sync_cron` event sends on its schedule.

## Decisions on the PRD's open questions

### 1. Versions checked, and reads before `init`

Checked: Jetpack 16.3, 16.4 and trunk 16.5-a.1 (sync package 5.4.0, 5.4.4,
5.5.1). See the table above.

Yes, Jetpack reads settings before `init`. `Main::configure()` hooks
`on_plugins_loaded_late()` to `plugins_loaded` at priority 90, which calls
`Actions::init()`. That method reads `disable` and `network_disable` (through
`Settings::is_sync_enabled()`), `dedicated_sync_enabled` and `sync_via_cron`.
The listener reads `max_queue_size` and `max_queue_lag` when it is built in the
same call. No supported version reads at plugin file load.

Consequence for C14: the PRD's assumption ("init and later") is too late, but
the plugin is still in time because it adds its filters at file load, and
every plugin file (must-use or normal, any order) loads before
`plugins_loaded` fires. Tests T13 and T14 cover both orders.

### 2. The guard's fallback write

Kept, on by default, behind `JETPACK_SYNC_QUIET_GUARD_FALLBACK` and the
`jetpack_sync_quiet_guard_fallback` filter.

The option filter comes first and is the only mechanism in normal operation.
After `init` (on `wp_loaded`), the guard calls Jetpack's
`Settings::is_dedicated_sync_enabled()`. If it returns true while the filter
is registered and enabled, the filter is no longer reaching Jetpack. The
guard then:

1. checks that sync is active (connected, not disabled); if not, it writes
   nothing (C17);
2. checks whether it already tried today (UTC); if so it stops there;
3. writes `0` once through `Settings::update_settings()`;
4. reads Jetpack's reader again and records "applied" or "failed";
5. logs one warning, fires `jetpack_sync_quiet_fallback_applied`, and sets
   the admin notice.

It never loops: at most one write per UTC day, whatever triggers it (the
per-request check, the daily event, the post-upgrade event or the button).
The per-request check costs one call into Jetpack's reader, which the filter
answers, so normal requests do no database work for it.

### 3. Forcing `sync_via_cron`

Forced on by default (C21). Without dedicated sync, a site whose writes
arrive from outside WordPress could otherwise go a long time without a
sending request. Opt out with `JETPACK_SYNC_QUIET_FORCE_CRON = false` or the
`jetpack_sync_quiet_force_cron` filter; status then warns when Jetpack reads
it off.

### 4. Last successful sync time

In order:

1. `Actions::get_sync_status('debug_details')`, key
   `debug_details.last_succesful_sync` (Jetpack's spelling; the correct
   spelling is also accepted). This is public and present in all three
   versions.
2. If that is missing and the queue is not empty: "unknown", followed by
   how long the oldest unsent change has waited (the queue lag). The lag is
   not turned into a time for the last send: on a busy site a send can
   succeed and still leave older items behind when the batch is full, so
   queue age gives no bound on the last successful send.
3. Otherwise "unknown".

### 5. Tunable ranges

Half to four times Jetpack's default, from `Defaults` in the checked versions:

| Key | Jetpack default | Range |
|---|---|---|
| `max_queue_size` | 5000 items | 2500 to 20000 |
| `max_queue_lag` | 7200 s | 3600 to 28800 s |
| `upload_max_rows` | 500 rows | 250 to 2000 |
| `upload_max_bytes` | 600000 bytes | 300000 to 2400000 |
| `cron_sync_time_limit` | 240 s | 120 to 960 s |
| `sync_wait_time` | 10 s | 5 to 40 s |
| `cron_interval` | 300 s | 150 to 1200 s |
| `full_sync_interval` | 300 s | 150 to 1200 s |

These replace the PRD's starting table. A range keeps a value within one
order of magnitude of what Jetpack and WordPress.com are tuned for. Jetpack
itself applies no range: `Settings::get_setting()` casts numeric strings to
integers and nothing more. Nothing in the checked source shows WordPress.com
capping these values on the site side, but WordPress.com can overwrite any
sync setting with a remote settings write, which the plugin's filter then
overrides on read. The `jetpack_sync_quiet_ranges` filter widens or narrows a
range; for example a host with a 60 second `max_execution_time` can allow a
`cron_sync_time_limit` below 120.

### 6. Full-sync interval

Tunable separately as `full_sync_interval`, through Jetpack's
`jetpack_sync_full_sync_interval` filter and its own schedule
`jetpack_sync_quiet_full`. Setting `cron_interval` does not change it.

### 7. Layout

A loader file plus a directory. The plugin folder works as a normal plugin
as is. For must-use installs, copy the folder to `wp-content/mu-plugins/`
and copy `mu-loader/jetpack-sync-quiet-loader.php` next to it: WordPress loads
single files in `mu-plugins/`, not folders. Loading both copies is safe; the
first one wins.

## Other decisions

- **Filter priority 1000.** Late, so this plugin's answer wins over most other
  filters on the same option. The callback ignores `$pre` when enabled.
- **String return values.** The dedicated filter returns the string `"0"`
  (never `false`, which means "not short-circuited"). Tunables are returned as
  numeric strings; Jetpack casts them to integers.
- **Master switch evaluated per read.** `jetpack_sync_quiet_enabled` is read
  on every filtered read, so a filter added late still counts. When it
  returns false the plugin passes `$pre` through unchanged.
- **Tunables option autoloaded.** The PRD lists the tunables option with
  autoload off, but its own performance budget allows no database query per
  request, and Jetpack reads `max_queue_size` and `max_queue_lag` on most
  requests. The site option is small and only exists when the operator set
  something, so it is autoloaded. The guard state option stays autoload off;
  it is read only on the rare paths. On multisite the network option is a
  site option and is read once per request when no persistent object cache
  is present.
- **Drift is announced once per event.** A change of the stored value is
  recorded from `update_option_*` and `add_option_*`. The same stored value
  within 24 hours is the same event: no new log line, no new action. A drift
  that persists is announced again at most once a day by the daily guard.
- **Package detection by class.** `Automattic\Jetpack\Sync\Settings`, checked
  on `plugins_loaded` at priority 1000. Plugins other than Jetpack bundle the
  package, so the Jetpack plugin slug is not used.
- **Post-upgrade check runs a minute later.** On `upgrader_process_complete`
  the old Jetpack code is still in memory, so the guard schedules a single
  event 60 seconds later instead of checking at once.
- **No outbound requests, ever.** The plugin calls no HTTP function. Test T26
  checks this with every hook fired.
- **Failure is "unknown", not fatal.** Every call into Jetpack is checked with
  `class_exists`, `method_exists` and `is_callable` and wrapped so that an
  exception becomes "unknown" in status. The switch depends only on the
  option name and WordPress core.

## Known behaviour worth knowing

**WordPress.com asking for dedicated sync.** When a sync send gets the
`Jetpack-Dedicated-Sync: on` response header, Jetpack compares the header with
its reader (which says off, thanks to the filter) and calls
`Settings::update_settings()` with 1. That writes 1. Because the write
succeeded, Jetpack then runs `can_spawn_dedicated_sync_request()`, a blocking
loopback with a 30 second timeout whose result Jetpack caches for one hour in
a transient. The loopback request also reads dedicated sync as off, so it
does not answer `DEDICATED SYNC OK`, and Jetpack writes 0 back. So on a site
where WordPress.com keeps asking, expect at most one validation loopback per
hour and two small option writes per sync send. That is the price of not
fighting Jetpack's writes, and it is far below one loopback per request.
The drift event is recorded once, not on every flip. Blocking the write was
considered and rejected: the PRD asks the plugin to treat the stored value as
information and never fight it.

When the option has never been stored (no row in the options table), the
same header writes nothing at all: `update_option()` reads the old value
through `get_option()`, gets the filtered `"0"` instead of `false`, so it
skips its add path and runs an UPDATE that matches no row. It returns false,
so Jetpack does not run the validation loopback either. Checked against
WordPress 6.4 and trunk `wp-includes/option.php`.

**Status in WP-CLI.** `max_execution_time` in the CLI is usually 0, so the C25
comparison only says something on the admin page, which runs under the web
server's limits.

## Tests

PHPUnit 10 against `tests/stubs/`: a small WordPress (hooks, options with
`pre_option` and update semantics as in core, object cache, cron, multisite,
nonces, escaping), a WP-CLI stand-in whose `error()` and `halt()` throw, and a
model of the Jetpack Sync settings, actions, sender, dedicated sender, queue
and connection classes. The Jetpack model writes its default back on a
`false` read and fires a validation request when dedicated sync is enabled,
like the real package, so the tests can see the writes and requests the
plugin prevents. Each acceptance test in PRD section 12 maps to a test; T29
is the manual check in `docs/MEASURING.md`.
