<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Tests\Unit;

use Automattic\Jetpack\Sync\Settings;
use IndianOpinion\JetpackSyncQuiet\Guard;
use IndianOpinion\JetpackSyncQuiet\JetpackSync;
use IndianOpinion\JetpackSyncQuiet\Tests\TestCase;

/**
 * Load order and presence: C15, C16, C18.
 */
final class PresenceTest extends TestCase
{
    /** T15 (C15). */
    public function testNoSyncPackageRemovesFiltersAndSchedulesNothing(): void
    {
        $plugin = $this->boot([], $this->missingJetpack());
        do_action('plugins_loaded');
        do_action('init');

        self::assertFalse($plugin->optionFilter()->isRegistered());
        self::assertFalse(has_filter('pre_option_' . self::DEDICATED_OPTION));
        self::assertFalse(has_filter('jetpack_sync_incremental_sync_interval'));
        self::assertFalse(wp_next_scheduled(Guard::HOOK));
        self::assertSame([], _get_cron_array());

        $status = $plugin->status()->collect();
        self::assertSame('not found', $status['jetpack_sync']);
        self::assertSame('Jetpack Sync not found; doing nothing', $status['note']);
        self::assertSame('no-op: Jetpack Sync not found', $status['plugin']);
        self::assertSame([], $status['warnings']);
        self::assertSame(Guard::RESULT_NOT_FOUND, $plugin->guard()->run('manual'));
    }

    /** T15: no admin notice nags when Jetpack is missing. */
    public function testNoNoticeWhenPackageMissing(): void
    {
        $plugin = $this->boot([], $this->missingJetpack());
        $page = new \IndianOpinion\JetpackSyncQuiet\Admin\AdminPage($plugin);
        \IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState::$caps['manage_options'] = true;

        ob_start();
        $page->renderNotice();
        self::assertSame('', ob_get_clean());
    }

    /** T16 (C15): the package bundled by another plugin, no Jetpack plugin constant. */
    public function testPackageWithoutJetpackPluginIsEnough(): void
    {
        $this->store(self::DEDICATED_OPTION, '1');
        $plugin = $this->boot();
        do_action('plugins_loaded');

        self::assertNull($this->env->constant('JETPACK__VERSION'));
        self::assertTrue($plugin->optionFilter()->isRegistered());
        self::assertSame('active', $plugin->stateDescription());
        self::assertFalse(Settings::is_dedicated_sync_enabled());
        self::assertSame('sync package 5.5.1', $plugin->guard()->jetpackVersion());
    }

    /** T17 (C16, C18). */
    public function testGuardEventFollowsThePackage(): void
    {
        $plugin = $this->boot();
        do_action('plugins_loaded');
        do_action('init');
        self::assertNotFalse(wp_next_scheduled(Guard::HOOK));

        // Next request: Jetpack deactivated.
        \IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState::$hooks = [];
        $this->boot([], $this->missingJetpack());
        do_action('plugins_loaded');
        do_action('init');
        self::assertFalse(wp_next_scheduled(Guard::HOOK));

        // Next request: Jetpack back.
        \IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState::$hooks = [];
        $plugin = $this->boot();
        do_action('plugins_loaded');
        do_action('init');
        self::assertNotFalse(wp_next_scheduled(Guard::HOOK));
        self::assertTrue($plugin->optionFilter()->isRegistered());
        self::assertFalse(Settings::is_dedicated_sync_enabled());
    }

    public function testDailyRunWithoutPackageUnschedulesItself(): void
    {
        $plugin = $this->boot([], $this->missingJetpack());
        wp_schedule_event(self::NOW, 'daily', Guard::HOOK);

        // Filters are still registered until plugins_loaded; the guard itself checks presence.
        self::assertSame(Guard::RESULT_NOT_FOUND, $plugin->guard()->run('daily'));
        self::assertFalse(wp_next_scheduled(Guard::HOOK));
    }

    public function testInstallTypes(): void
    {
        $this->env->constants['WPMU_PLUGIN_DIR'] = '/srv/wp/wp-content/mu-plugins';
        $mu = $this->boot([], null, '/srv/wp/wp-content/mu-plugins/jetpack-sync-quiet/jetpack-sync-quiet.php');
        self::assertSame('must-use', $mu->installType());

        $normal = $this->boot();
        self::assertSame('normal', $normal->installType());

        \IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState::$multisite = true;
        self::assertSame('this site only', $normal->installType());
        \IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState::$siteOptions['active_sitewide_plugins'] = ['jetpack-sync-quiet/jetpack-sync-quiet.php' => self::NOW];
        self::assertSame('network-activated', $normal->installType());
    }

    public function testJetpackInternalsMissingShowUnknown(): void
    {
        $jetpack = new JetpackSync(
            'Automattic\\Jetpack\\Sync\\Settings',
            'Missing\\Actions',
            'Missing\\Listener',
            'Missing\\Version',
            'Missing\\Manager'
        );
        $plugin = $this->boot([], $jetpack);

        $status = $plugin->status()->collect();

        self::assertSame('unknown', $status['queue_size']);
        self::assertSame('unknown', $status['queue_lag']);
        self::assertSame('unknown', $status['last_successful_sync']);
        self::assertSame('unknown', $status['connected']);
        self::assertSame('unknown version', $status['jetpack_version']);
    }
}
