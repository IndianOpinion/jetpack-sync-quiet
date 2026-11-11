<?php

/**
 * Uninstall for normal plugin installs. Removes this plugin's options and
 * cron events on every site. Jetpack's own settings are left alone.
 *
 * Must-use installs have no uninstall hook: run `wp jetpack-sync-quiet purge`.
 *
 * @package IndianOpinion\JetpackSyncQuiet
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/requirements.php';

if (!jetpack_sync_quiet_requirements_met(PHP_VERSION_ID)) {
    return;
}

// A must-use copy may already have loaded the classes from another folder.
if (!class_exists(\IndianOpinion\JetpackSyncQuiet\Installer::class, false)) {
    require_once __DIR__ . '/src/bootstrap.php';
}

\IndianOpinion\JetpackSyncQuiet\Installer::purge(is_multisite());
