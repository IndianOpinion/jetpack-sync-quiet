<?php

/**
 * Plugin Name: Jetpack Sync Quiet (must-use loader)
 * Description: Loads Jetpack Sync Quiet from wp-content/mu-plugins/jetpack-sync-quiet/.
 *
 * Copy this file to wp-content/mu-plugins/ and the plugin folder to
 * wp-content/mu-plugins/jetpack-sync-quiet/. WordPress loads single files in
 * mu-plugins/ but not folders, which is why this loader exists.
 *
 * @package IndianOpinion\JetpackSyncQuiet
 */

if (is_file(__DIR__ . '/jetpack-sync-quiet/jetpack-sync-quiet.php')) {
    require_once __DIR__ . '/jetpack-sync-quiet/jetpack-sync-quiet.php';
}
