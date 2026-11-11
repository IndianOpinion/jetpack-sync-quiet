<?php

/**
 * Uninstall for normal plugin installs. Removes this plugin's options and
 * cron events on every site. Jetpack's own settings are left alone.
 *
 * Must-use installs have no uninstall hook: run `wp jetpack-sync-quiet purge`.
 *
 * @package IndianOpinion\JetpackSyncQuiet
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/bootstrap.php';

\IndianOpinion\JetpackSyncQuiet\Installer::purge(is_multisite());
