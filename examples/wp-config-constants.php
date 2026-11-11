<?php

/**
 * Example lines for wp-config.php. Put them above the line that says
 * "That's all, stop editing!". Every constant is optional.
 */

declare(strict_types=1);

// Turn the plugin into a no-op without removing it (for example while debugging).
define('JETPACK_SYNC_QUIET_DISABLE', false);

// Keep Jetpack's sync_via_cron forced on (default true). Set false to leave it to Jetpack.
define('JETPACK_SYNC_QUIET_FORCE_CRON', true);

// Let the guard write dedicated_sync_enabled = 0 through Jetpack if the filter stops working (default true).
define('JETPACK_SYNC_QUIET_GUARD_FALLBACK', true);

// Tunables for a high-write site. Highest precedence; see the README for ranges.
define('JETPACK_SYNC_QUIET_SETTINGS', [
    'max_queue_size'       => 20000, // Raise first: a full queue drops changes.
    'max_queue_lag'        => 14400, // Seconds.
    'cron_interval'        => 150,   // Run jetpack_sync_cron every 150 seconds.
    'cron_sync_time_limit' => 120,   // Keep below the web server's max_execution_time.
]);
