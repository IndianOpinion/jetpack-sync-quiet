<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet;

/**
 * Removes everything this plugin stored. Used by uninstall.php (normal
 * installs) and `wp jetpack-sync-quiet purge` (must-use installs, which
 * have no uninstall hook).
 *
 * Jetpack's own options are never touched: once this plugin is gone, the
 * stored dedicated_sync_enabled value governs Jetpack again.
 */
final class Installer
{
    /** Upper bound on sites visited in one network purge. */
    public const MAX_SITES = 10000;

    /**
     * @param bool $network On multisite: every site plus the network option.
     */
    public static function purge(bool $network = false): void
    {
        if ($network && is_multisite()) {
            $ids = get_sites(['fields' => 'ids', 'number' => self::MAX_SITES]);
            foreach ($ids as $id) {
                switch_to_blog((int) $id);
                self::purgeSite();
                restore_current_blog();
            }
            delete_site_option(Tunables::NETWORK_OPTION);

            return;
        }
        self::purgeSite();
    }

    public static function purgeSite(): void
    {
        delete_option(Tunables::SITE_OPTION);
        delete_option(Guard::STATE_OPTION);
        wp_clear_scheduled_hook(Guard::HOOK);
        wp_clear_scheduled_hook(Guard::UPGRADE_HOOK);
    }
}
