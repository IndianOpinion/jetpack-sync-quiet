<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Tests\Unit;

use Automattic\Jetpack\Sync\Settings;
use IndianOpinion\JetpackSyncQuiet\Guard;
use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState;
use IndianOpinion\JetpackSyncQuiet\Tests\TestCase;

/**
 * T33: every documented filter and constant.
 */
final class FiltersTest extends TestCase
{
    public function testDisableConstantMakesThePluginANoOp(): void
    {
        $this->store(self::DEDICATED_OPTION, '1');
        $this->env->constants['WP_CLI'] = true;
        $plugin = $this->boot(['JETPACK_SYNC_QUIET_DISABLE' => true]);
        do_action('plugins_loaded');
        do_action('init');

        self::assertTrue(Settings::is_dedicated_sync_enabled());
        self::assertFalse(has_filter('pre_option_' . self::DEDICATED_OPTION));
        self::assertFalse(wp_next_scheduled(Guard::HOOK));
        self::assertSame('no-op: disabled by JETPACK_SYNC_QUIET_DISABLE', $plugin->status()->collect()['plugin']);
        self::assertArrayHasKey('jetpack-sync-quiet', \WP_CLI::$commands, 'status must stay available');
        self::assertSame(Guard::RESULT_PLUGIN_OFF, $plugin->guard()->run('manual'));
    }

    public function testEnabledFilterSwitchesOffPerRequest(): void
    {
        $this->store(self::DEDICATED_OPTION, '1');
        $this->store(self::VIA_CRON_OPTION, '0');
        $plugin = $this->boot(['JETPACK_SYNC_QUIET_SETTINGS' => ['max_queue_size' => 9000]]);
        add_filter('jetpack_sync_quiet_enabled', '__return_false');

        self::assertTrue(Settings::is_dedicated_sync_enabled());
        self::assertSame(0, Settings::get_setting('sync_via_cron'));
        self::assertSame(5000, Settings::get_setting('max_queue_size'));
        self::assertSame('jetpack_sync_interval', apply_filters('jetpack_sync_incremental_sync_interval', 'jetpack_sync_interval'));
        self::assertStringStartsWith('no-op: switched off', $plugin->stateDescription());
    }

    public function testEnabledFilterDoesNotTriggerFallback(): void
    {
        $this->store(self::DEDICATED_OPTION, '1');
        $this->boot();
        add_filter('jetpack_sync_quiet_enabled', '__return_false');

        do_action('wp_loaded');

        self::assertSame('1', $this->stored(self::DEDICATED_OPTION));
        self::assertSame([], WpState::writesTo(Guard::STATE_OPTION));
    }

    public function testForceCronFilter(): void
    {
        $this->store(self::VIA_CRON_OPTION, '0');
        $this->boot(['JETPACK_SYNC_QUIET_FORCE_CRON' => false]);
        add_filter('jetpack_sync_quiet_force_cron', '__return_true');

        self::assertSame(1, Settings::get_setting('sync_via_cron'));
    }

    public function testGuardFallbackFilter(): void
    {
        Settings::$prefix = 'jetpack_sync_renamed_';
        $this->store('jetpack_sync_renamed_dedicated_sync_enabled', '1');
        $plugin = $this->boot();
        add_filter('jetpack_sync_quiet_guard_fallback', '__return_false');

        self::assertStringContainsString('warning only', $plugin->guard()->run('manual'));
        self::assertSame('1', $this->stored('jetpack_sync_renamed_dedicated_sync_enabled'));
    }

    public function testSettingsFilterHasTheLastWord(): void
    {
        $this->boot(['JETPACK_SYNC_QUIET_SETTINGS' => ['max_queue_size' => 9000]]);
        add_filter('jetpack_sync_quiet_settings', static function (array $settings): array {
            unset($settings['max_queue_size']);
            $settings['upload_max_rows'] = 1000;

            return $settings;
        });

        self::assertSame(5000, Settings::get_setting('max_queue_size'));
        self::assertSame(1000, Settings::get_setting('upload_max_rows'));
    }

    public function testRangesFilter(): void
    {
        $plugin = $this->boot();
        add_filter('jetpack_sync_quiet_ranges', static function (array $ranges): array {
            $ranges['cron_sync_time_limit'] = [30, 960];
            $ranges['max_queue_size'] = ['bad'];

            return $ranges;
        });
        $plugin->tunables()->reset();

        self::assertSame(30, $plugin->tunables()->validate('cron_sync_time_limit', 30));
        self::assertSame([2500, 20000], $plugin->tunables()->range('max_queue_size'));
    }

    public function testStatusFilter(): void
    {
        $plugin = $this->boot();
        add_filter('jetpack_sync_quiet_status', static function (array $status): array {
            $status['site_note'] = 'canary';

            return $status;
        });

        self::assertSame('canary', $plugin->status()->collect()['site_note']);
    }
}
