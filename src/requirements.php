<?php

/**
 * Requirement check for Jetpack Sync Quiet.
 *
 * This file is loaded before anything else and must parse on old PHP
 * versions, so it uses no namespace, no type declarations and no syntax
 * newer than PHP 5.6. If the requirements are not met the plugin does
 * nothing except show one admin notice.
 *
 * @package IndianOpinion\JetpackSyncQuiet
 */

if (!function_exists('jetpack_sync_quiet_requirements_met')) {
    /**
     * Whether this PHP version can run the plugin.
     *
     * @param int $version_id A PHP_VERSION_ID style integer.
     * @return bool
     */
    function jetpack_sync_quiet_requirements_met($version_id)
    {
        return (int) $version_id >= 80100;
    }
}

if (!function_exists('jetpack_sync_quiet_requirements_notice')) {
    /**
     * Admin notice shown when PHP is too old. The plugin is a no-op then.
     *
     * @return void
     */
    function jetpack_sync_quiet_requirements_notice()
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>'
            . esc_html('Jetpack Sync Quiet needs PHP 8.1 or later and is doing nothing on this site.')
            . '</p></div>';
    }
}
