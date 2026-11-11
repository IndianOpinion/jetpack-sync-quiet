<?php

/**
 * Runnable demo: the switch against the test stand-ins for WordPress and
 * Jetpack, no WordPress install needed.
 *
 *   composer install
 *   php examples/demo-without-wordpress.php
 */

declare(strict_types=1);

use Automattic\Jetpack\Sync\Listener;
use Automattic\Jetpack\Sync\Sender;
use Automattic\Jetpack\Sync\Settings;
use IndianOpinion\JetpackSyncQuiet\Plugin;
use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState;

require __DIR__ . '/../tests/bootstrap.php';

WpState::reset();
WpState::$options[1]['jetpack_sync_settings_dedicated_sync_enabled'] = '1';
Listener::get_instance()->queue->items = 25;

echo "Without the plugin\n";
echo '  Jetpack reads dedicated sync: ' . (Settings::is_dedicated_sync_enabled() ? 'on' : 'off') . "\n";
(new Sender())->do_sync();
echo '  loopback requests fired: ' . count(WpState::$httpRequests) . "\n\n";

WpState::$httpRequests = [];
$plugin = Plugin::boot('/demo/jetpack-sync-quiet/jetpack-sync-quiet.php');

echo "With the plugin\n";
echo '  Jetpack reads dedicated sync: ' . (Settings::is_dedicated_sync_enabled() ? 'on' : 'off') . "\n";
echo '  stored value is still: ' . WpState::$options[1]['jetpack_sync_settings_dedicated_sync_enabled'] . "\n";
(new Sender())->do_sync();
echo '  loopback requests fired: ' . count(WpState::$httpRequests) . "\n";
echo '  batches sent in-process: ' . count(Sender::$sentInProcess) . "\n\n";

$status = $plugin->status()->collect();
echo "Status excerpt\n";
$fields = ['plugin', 'dedicated_sync_effective', 'dedicated_sync_stored', 'dedicated_sync_match', 'queue_size'];
foreach ($fields as $field) {
    printf("  %-26s %s\n", $field, is_scalar($status[$field]) ? (string) $status[$field] : '');
}
