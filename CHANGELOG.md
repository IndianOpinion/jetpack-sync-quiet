# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-11

### Added

- `pre_option` filter that makes Jetpack read `dedicated_sync_enabled` as
  `"0"` without changing the stored value.
- `sync_via_cron` forced on by default, with a constant and a filter to opt out.
- Tunables for `max_queue_size`, `max_queue_lag`, `upload_max_rows`,
  `upload_max_bytes`, `cron_sync_time_limit`, `sync_wait_time`, the
  incremental sync cron interval and the full-sync cron interval, each limited
  to half to four times Jetpack's default.
- Guard: drift events when the stored value changes, a daily check, a check
  after plugin upgrades, and a once-a-day fallback write through Jetpack's
  settings API if the filter stops reaching Jetpack.
- Detection of the Jetpack Sync package by class, so plugins other than
  Jetpack that bundle it are covered.
- Status report: plugin state, install type, Jetpack version, effective and
  stored values, queue size and lag, last successful sync, cron health,
  `DISABLE_WP_CRON` and system cron, time limit against `max_execution_time`,
  tunables with their source, warnings.
- WP-CLI command `wp jetpack-sync-quiet` with `status`, `settings`,
  `guard run` and `purge`.
- Read-only admin page under Tools and under Network Admin, Settings, with a
  "Run guard now" button.
- Must-use loader, `uninstall.php`, examples and a measurement guide.

[Unreleased]: https://github.com/IndianOpinion/jetpack-sync-quiet/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/jetpack-sync-quiet/releases/tag/v0.1.0
