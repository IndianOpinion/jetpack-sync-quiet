# Measuring the effect

How to show, with your own logs, what the plugin changes. This is acceptance
test T29 in `docs/PRD.md`.

## What you need

- At least seven days of web server access logs from the origin. Not from the
  CDN: the loopback goes from the server to itself and never reaches a CDN,
  so CDN analytics do not show it.
- Shell access with WP-CLI, or the plugin's admin page.
- A Jetpack feature that shows a post reached WordPress.com: the post in the
  WordPress.com dashboard, a Jetpack Social share, or a Subscriptions email.

## Steps

1. **Baseline.** Keep the last seven days of logs before installing.
2. **Count loopbacks per day.** A loopback is a request whose path is
   `/wp-json/jetpack/v4/sync/spawn-sync`, or whose query has
   `rest_route=/jetpack/v4/sync/spawn-sync`. For an nginx or Apache combined
   log:

   ```sh
   examples/count-spawn-sync.sh /var/log/nginx/access.log
   zcat /var/log/nginx/access.log.*.gz | examples/count-spawn-sync.sh -
   ```

   One line with standard tools, for a quick look:

   ```sh
   grep -c 'jetpack/v4/sync/spawn-sync' /var/log/nginx/access.log
   ```

   Record the count per day, all requests per day, and the share.
3. **Queue before.** A few times a day, run `wp jetpack sync status` and note
   `queue_size` and `queue_lag`.
4. **Install** the plugin (must-use preferred). Run
   `wp jetpack-sync-quiet status` and check that `dedicated_sync_effective`
   is `off` and there are no warnings.
5. **Wait seven days.** Repeat steps 2 and 3.
6. **Check sync end to end.** Publish a test post. It should reach
   WordPress.com within two cron intervals (ten minutes with the default
   interval).

## What to expect

- Loopbacks drop to near zero within a minute of install. Requests already in
  flight finish normally (C6).
- `queue_lag` stays within a few cron intervals.
- On a site where loopbacks were already blocked (firewall, basic auth on
  staging), the origin request count does not change. The gain there is no
  failed outbound attempts and no blocking 30 second validation requests.
- If WordPress.com keeps asking for dedicated sync, expect at most one
  validation loopback per hour. See "Known behaviour" in
  `docs/ARCHITECTURE.md`.

## Production figure

On a news site modifying about 9,000 posts a day: 173,358 spawn-sync
loopbacks in 14 days, 12.8% of all origin requests, before the change.

## If the numbers do not move

- `wp jetpack-sync-quiet status`: is the plugin `active`? Is
  `dedicated_sync_effective` `off`?
- Is the plugin loaded on the site that serves the traffic (multisite: check
  `install_type` and `--url`)?
- Are the requests really from this site? The user agent of the loopback is
  `WordPress/<version>; <site URL>` and the client address is the server's own.
