<?php

/**
 * Plugin Name: Jetpack Sync Quiet examples (filters)
 * Description: Example uses of the jetpack_sync_quiet_* filters and actions. Copy what you need.
 */

declare(strict_types=1);

// Leave Jetpack alone on a staging copy that has its own setup.
add_filter('jetpack_sync_quiet_enabled', static function (bool $enabled): bool {
    return function_exists('wp_get_environment_type') && wp_get_environment_type() === 'staging' ? false : $enabled;
});

// Let Jetpack decide sync_via_cron on this site.
add_filter('jetpack_sync_quiet_force_cron', '__return_false');

// Never let the guard write to Jetpack; warn only.
add_filter('jetpack_sync_quiet_guard_fallback', '__return_false');

// Final say over tunables, for example from an environment variable.
add_filter('jetpack_sync_quiet_settings', static function (array $settings): array {
    $size = getenv('JETPACK_SYNC_MAX_QUEUE_SIZE');
    if (is_string($size) && ctype_digit($size)) {
        $settings['max_queue_size'] = (int) $size;
    }

    return $settings;
});

// Allow a lower cron time limit on a host with a 60 second max_execution_time.
add_filter('jetpack_sync_quiet_ranges', static function (array $ranges): array {
    $ranges['cron_sync_time_limit'] = [30, 960];

    return $ranges;
});

// Send drift and fallback events to your own monitoring.
add_action(
    'jetpack_sync_quiet_drift_detected',
    static function (mixed $stored, string $effective, string $source): void {
        do_action('my_monitoring_event', 'jetpack-sync-quiet drift', compact('stored', 'effective', 'source'));
    },
    10,
    3
);

add_action('jetpack_sync_quiet_fallback_applied', static function (string $version, string $result): void {
    do_action('my_monitoring_event', 'jetpack-sync-quiet fallback', compact('version', 'result'));
}, 10, 2);

// Add a field to the status report.
add_filter('jetpack_sync_quiet_status', static function (array $status): array {
    $status['environment'] = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown';

    return $status;
});
