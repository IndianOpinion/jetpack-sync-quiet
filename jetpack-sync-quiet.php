<?php

/**
 * Plugin Name:       Jetpack Sync Quiet
 * Plugin URI:        https://github.com/IndianOpinion/jetpack-sync-quiet
 * Description:       Turns off Jetpack's dedicated-sync loopbacks; Jetpack Sync runs on cron and after write requests.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       jetpack-sync-quiet
 *
 * This file is the loader. It works in wp-content/plugins/jetpack-sync-quiet/
 * as a normal plugin, and from wp-content/mu-plugins/ through the one-line
 * loader in mu-loader/. It must parse on old PHP, so keep it plain.
 *
 * @package IndianOpinion\JetpackSyncQuiet
 */

if (!defined('ABSPATH')) {
    return;
}

// Loaded twice (for example both as a must-use plugin and a normal plugin): first copy wins.
if (defined('JETPACK_SYNC_QUIET_FILE')) {
    return;
}

define('JETPACK_SYNC_QUIET_FILE', __FILE__);
define('JETPACK_SYNC_QUIET_VERSION', '0.1.0');

require_once __DIR__ . '/src/requirements.php';

if (!jetpack_sync_quiet_requirements_met(PHP_VERSION_ID)) {
    add_action('admin_notices', 'jetpack_sync_quiet_requirements_notice');
    add_action('network_admin_notices', 'jetpack_sync_quiet_requirements_notice');
    return;
}

require_once __DIR__ . '/src/bootstrap.php';

\IndianOpinion\JetpackSyncQuiet\Plugin::boot(__FILE__);
