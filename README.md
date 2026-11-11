# Jetpack Sync Quiet

Jetpack's "dedicated sync" makes a WordPress site send an HTTP request to
itself after almost every page view while its sync queue has items, and on a
busy site the queue always has items. This plugin makes Jetpack read
dedicated sync as off, so sync runs on Jetpack's own cron event and at the
end of write requests instead, with no loopbacks.

Measured: on a news site modifying about 9,000 posts a day, the spawn-sync
loopback was 173,358 requests in 14 days, 12.8% of all origin requests, each
a full WordPress boot.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

- Requires PHP 8.1+, WordPress 6.4+.
- Checked against Jetpack 16.3, 16.4 and 16.5-a.1 (sync package 5.4.0 to
  5.5.1). Works with any plugin that bundles the Jetpack Sync package.
- No HTTP requests of its own. One small guard record a day. Nothing written
  to Jetpack's settings (one guarded exception, below).

## Install

### Without Composer (most sites)

As a must-use plugin (preferred: it cannot be switched off by accident and
loads before every normal plugin):

```sh
cd wp-content/mu-plugins
git clone --depth 1 https://github.com/IndianOpinion/jetpack-sync-quiet.git
cp jetpack-sync-quiet/mu-loader/jetpack-sync-quiet-loader.php .
```

WordPress loads single files in `mu-plugins/`, not folders. The loader is the
one file that requires the plugin from its folder.

As a normal plugin: copy the `jetpack-sync-quiet` folder to
`wp-content/plugins/` and activate it (network-activate on multisite to cover
every site).

No `composer install` is needed at runtime. The plugin loads its own classes.

### With Composer

```sh
composer require indianopinion/jetpack-sync-quiet
```

The package type is `wordpress-plugin`, so with `composer/installers` it lands
in `wp-content/plugins/`. For a must-use install, add an installer path for it
under `mu-plugins/` and copy the loader as above.

## Quick start

```sh
wp jetpack-sync-quiet status
```

Look for `plugin: active` and `dedicated_sync_effective: off`. A stored value
of `1` with an effective value of `off` is normal: the plugin filters the
read and leaves the stored value alone.

To roll back, delete the plugin (or the mu-plugin loader). Nothing was
written, so Jetpack goes back to its stored setting on the next request.

## Configuration

Nothing is needed. The defaults: dedicated sync reads off, `sync_via_cron` is
forced on, the guard is on with its fallback allowed, every other Jetpack
setting is untouched.

### Constants (`wp-config.php`)

| Constant | Type | Default | Effect |
|---|---|---|---|
| `JETPACK_SYNC_QUIET_DISABLE` | bool | `false` | No-op without removing the plugin. Status and CLI still work. |
| `JETPACK_SYNC_QUIET_FORCE_CRON` | bool | `true` | Force Jetpack's `sync_via_cron` on. |
| `JETPACK_SYNC_QUIET_GUARD_FALLBACK` | bool | `true` | Allow the guard's single fallback write (see How it works). |
| `JETPACK_SYNC_QUIET_SETTINGS` | array | `[]` | Tunables, highest precedence. |

### Tunables

Unset means Jetpack's own value. Ranges are half to four times Jetpack's
default; change them with the `jetpack_sync_quiet_ranges` filter.

| Key | Jetpack setting | Jetpack default | Allowed range |
|---|---|---|---|
| `max_queue_size` | `max_queue_size` | 5000 | 2500 to 20000 |
| `max_queue_lag` | `max_queue_lag` | 7200 s | 3600 to 28800 s |
| `upload_max_rows` | `upload_max_rows` | 500 | 250 to 2000 |
| `upload_max_bytes` | `upload_max_bytes` | 600000 | 300000 to 2400000 |
| `cron_sync_time_limit` | `cron_sync_time_limit` | 240 s | 120 to 960 s |
| `sync_wait_time` | `sync_wait_time` | 10 s | 5 to 40 s |
| `cron_interval` | schedule of `jetpack_sync_cron` | 300 s | 150 to 1200 s |
| `full_sync_interval` | schedule of `jetpack_sync_full_cron` | 300 s | 150 to 1200 s |

Precedence: constant, then site value, then network value (multisite). The
`jetpack_sync_quiet_settings` filter sees the merged result and has the last
word. Values are validated after the filter; an invalid value is ignored,
logged once, and Jetpack's value applies.

```sh
wp jetpack-sync-quiet settings list
wp jetpack-sync-quiet settings set max_queue_size 20000
wp jetpack-sync-quiet settings set cron_interval 150 --network
wp jetpack-sync-quiet settings unset cron_interval
```

For a high-write site: raise `max_queue_size` first (when the queue is full,
Jetpack drops new changes), keep the cron interval at five minutes or lower,
and run WP-Cron from a system cron.

### Filters and actions

| Hook | Type | Default | Use |
|---|---|---|---|
| `jetpack_sync_quiet_enabled` | filter, bool | `true` | Master switch, read on every filtered read. |
| `jetpack_sync_quiet_force_cron` | filter, bool | constant | Force `sync_via_cron` on or not. |
| `jetpack_sync_quiet_guard_fallback` | filter, bool | constant | Allow the fallback write. |
| `jetpack_sync_quiet_settings` | filter, array | merged | Final tunables before validation. |
| `jetpack_sync_quiet_ranges` | filter, array | half to 4x | Key => [min, max]. |
| `jetpack_sync_quiet_status` | filter, array | report | Status fields before display. |
| `jetpack_sync_quiet_drift_detected` | action | | `(stored, effective, source)`, once per event. |
| `jetpack_sync_quiet_fallback_applied` | action | | `(Jetpack version, "applied" or "failed")`. |

See `examples/filters.php` and `examples/wp-config-constants.php`.

### WP-CLI

```
wp jetpack-sync-quiet status [--format=table|json|yaml] [--strict] [--network]
wp jetpack-sync-quiet settings list|get|set|unset [<key>] [<value>] [--network]
wp jetpack-sync-quiet guard run
wp jetpack-sync-quiet purge [--network] [--yes]
```

`status --strict` exits 1 when there is any warning, for monitoring.
`purge` deletes this plugin's options and cron events; use it before removing
a must-use install, which has no uninstall hook.

### Admin page

Tools, Jetpack Sync Quiet: a read-only status table, a "Run guard now"
button, and a link to the measurement guide. On multisite also Network
Admin, Settings, Jetpack Sync Quiet, with one row per site.

## How it works

Jetpack stores each sync setting as a WordPress option named
`jetpack_sync_settings_<name>` and reads it with `get_option()`. WordPress
lets a plugin answer any `get_option()` call first through the
`pre_option_<option>` filter, before the object cache and the database. The
plugin answers `"0"` for `dedicated_sync_enabled`. The stored value is not
changed.

With dedicated sync off, Jetpack's sender sends the queue in-process at the
end of cron, POST, admin, `manage_options` and WP-CLI requests, and on its
`jetpack_sync_cron` event. Front-end GET requests do nothing. That is
Jetpack's own non-dedicated flow; the plugin adds no sender.

The filters are added when the plugin file loads. Jetpack first reads its
settings on `plugins_loaded`, which comes after every plugin file has loaded,
so the order of plugins does not matter.

**The guard.** WordPress.com can turn dedicated sync on by sending a response
header, and Jetpack stores it. The plugin records that as a drift event,
once, and does not fight it: the filter still wins on every read. If an
update to Jetpack ever stops the filter from reaching Jetpack's reader (for
example by renaming the option), the guard notices after `init` and writes
`0` once through Jetpack's own settings API, logs a warning, and shows an
admin notice. It tries at most once per UTC day and never loops. Switch the
write off with `JETPACK_SYNC_QUIET_GUARD_FALLBACK`.

More detail, and every design decision: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).
How to measure the effect on your site: [docs/MEASURING.md](docs/MEASURING.md).

## Limitations

- Sync needs cron that runs. With `DISABLE_WP_CRON`, check the system cron.
  Status warns when `jetpack_sync_cron` is overdue.
- A site that WordPress.com keeps asking to use dedicated sync still sees at
  most one validation loopback per hour, plus two small option writes per
  sync send (details in ARCHITECTURE.md).
- The plugin does not block the spawn-sync route. A request already in flight
  at install time finishes normally. If you also want to refuse external
  calls to that route, do it at the web server; that is your choice.
- `max_execution_time` in WP-CLI is usually 0, so the time-limit check only
  says something useful on the admin page.
- Checked against the Jetpack versions listed above. A later Jetpack may
  change; the guard is there to notice.

## FAQ

**Does this turn sync off?** No. Jetpack Social, Subscriptions and the rest
keep working. Changes reach WordPress.com on the next cron run (five minutes
by default) or at the end of the next write request.

**Why does status say "drift (stored on, effective off)"?** WordPress.com
asked for dedicated sync and Jetpack stored it. The plugin's filter still
wins. It is information, not a fault.

**Does it work with Jetpack Boost, Backup, Social or VaultPress?** Yes, if the
plugin bundles the Jetpack Sync package. Detection is by the sync package,
not by the Jetpack plugin.

**Does my page cache or object cache matter?** No. The loopback carries a
cache-busting parameter and is a REST request, so page caches never absorbed
it. The filter runs before the object cache, so a cached value cannot leak.

**I see no change in my CDN analytics.** Look at the origin's access log. The
loopback never leaves the server.

**How do I remove it cleanly?** Normal plugin: delete it in wp-admin;
`uninstall.php` removes its data. Must-use: run
`wp jetpack-sync-quiet purge --yes`, then delete the loader and the folder.
Jetpack's own settings are never touched.

## Development

```sh
composer install
composer check   # lint, phpcs, phpstan, phpunit
php examples/demo-without-wordpress.php
```

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Licence

MIT. See [LICENSE](LICENSE). Jetpack is a trademark of its owner; this
project is not affiliated with it.
