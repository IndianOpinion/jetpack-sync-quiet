<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Tests\Unit;

use Automattic\Jetpack\Sync\Actions;
use Automattic\Jetpack\Sync\Settings;
use IndianOpinion\JetpackSyncQuiet\CronSchedules;
use IndianOpinion\JetpackSyncQuiet\Exception\InvalidTunableException;
use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\CliExit;
use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState;
use IndianOpinion\JetpackSyncQuiet\Tests\TestCase;
use IndianOpinion\JetpackSyncQuiet\Tunables;

/**
 * Tuning: C19 to C21.
 */
final class TunablesTest extends TestCase
{
    /** T19 (C19). */
    public function testValidTunableAppliesToThatSettingOnly(): void
    {
        $plugin = $this->boot();
        $plugin->tunables()->set('max_queue_size', '10000');
        $this->store('jetpack_sync_settings_max_queue_lag', '9000');

        self::assertSame(10000, Settings::get_setting('max_queue_size'));
        self::assertSame(9000, Settings::get_setting('max_queue_lag'));
        self::assertSame(500, Settings::get_setting('upload_max_rows'));
        self::assertFalse(has_filter('pre_option_jetpack_sync_settings_dequeue_max_bytes'));
        self::assertSame('10000 (site)', $plugin->status()->collect()['tunables']['max_queue_size']);
        self::assertSame('5000', (string) Tunables::DEFINITIONS['max_queue_size'][1]);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function badValues(): array
    {
        return [
            'below range' => ['max_queue_size', '2499'],
            'above range' => ['max_queue_size', '20001'],
            'not integer' => ['max_queue_lag', '7200.5'],
            'words'       => ['sync_wait_time', 'ten'],
            'empty'       => ['upload_max_rows', ''],
            'unknown key' => ['dedicated_sync_enabled', '0'],
        ];
    }

    /**
     * T20 (C19): rejected at configuration time.
     *
     * @dataProvider badValues
     */
    public function testCliRejectsBadValues(string $key, mixed $value): void
    {
        $this->env->constants['WP_CLI'] = true;
        $this->boot();
        $command = \WP_CLI::$commands['jetpack-sync-quiet'];
        self::assertInstanceOf(\IndianOpinion\JetpackSyncQuiet\Cli\Command::class, $command);

        try {
            $command->settings(['set', $key, $value], []);
            self::fail('accepted');
        } catch (CliExit $e) {
            self::assertSame(1, $e->getCode());
        }
        self::assertStringContainsString('Error: ', \WP_CLI::output());
        self::assertFalse($this->stored(Tunables::SITE_OPTION));
    }

    /** T20 (C19): ignored at runtime with one log line. */
    public function testInvalidRuntimeValueIsIgnoredAndLoggedOnce(): void
    {
        $plugin = $this->boot(['JETPACK_SYNC_QUIET_SETTINGS' => ['max_queue_size' => 50, 'sync_wait_time' => 20]]);
        do_action('plugins_loaded');
        do_action('init');
        do_action('init');

        self::assertSame(5000, Settings::get_setting('max_queue_size'));
        self::assertSame(20, Settings::get_setting('sync_wait_time'));
        self::assertCount(1, $this->logger->lines);
        self::assertStringContainsString('max_queue_size must be a whole number from 2500 to 20000', $this->logger->lines[0]);

        // Next request, same configuration: no new line.
        $plugin->tunables()->reset();
        do_action('init');
        self::assertCount(1, $this->logger->lines);
        self::assertNotEmpty(array_filter($plugin->status()->collect()['warnings'], static fn (string $w): bool => str_contains($w, 'max_queue_size')));
    }

    public function testRangesAreHalfToFourTimesJetpackDefaults(): void
    {
        $tunables = $this->boot()->tunables();

        self::assertSame([2500, 20000], $tunables->range('max_queue_size'));
        self::assertSame([3600, 28800], $tunables->range('max_queue_lag'));
        self::assertSame([250, 2000], $tunables->range('upload_max_rows'));
        self::assertSame([300000, 2400000], $tunables->range('upload_max_bytes'));
        self::assertSame([120, 960], $tunables->range('cron_sync_time_limit'));
        self::assertSame([5, 40], $tunables->range('sync_wait_time'));
        self::assertSame([150, 1200], $tunables->range('cron_interval'));
        self::assertSame([150, 1200], $tunables->range('full_sync_interval'));
    }

    public function testValidateAcceptsIntegersAndDigitStrings(): void
    {
        $tunables = $this->boot()->tunables();

        self::assertSame(3000, $tunables->validate('max_queue_size', 3000));
        self::assertSame(3000, $tunables->validate('max_queue_size', ' 3000 '));
        $this->expectException(InvalidTunableException::class);
        $tunables->validate('max_queue_size', 3000.0);
    }

    /** T21 (C20). */
    public function testCronIntervalRegistersScheduleAndReturnsItsName(): void
    {
        $plugin = $this->boot(['JETPACK_SYNC_QUIET_SETTINGS' => ['cron_interval' => 150]]);

        $schedules = wp_get_schedules();
        self::assertSame(150, $schedules[CronSchedules::INCREMENTAL]['interval']);
        self::assertSame(CronSchedules::INCREMENTAL, apply_filters('jetpack_sync_incremental_sync_interval', 'jetpack_sync_interval'));
        self::assertSame('jetpack_sync_interval', apply_filters('jetpack_sync_full_sync_interval', 'jetpack_sync_interval'), 'full sync changed without being set');

        Actions::load();
        do_action('plugins_loaded');
        do_action('init');
        self::assertSame(CronSchedules::INCREMENTAL, wp_get_schedule('jetpack_sync_cron'));

        // Schedule absent (someone removed it): Jetpack's own name comes back.
        remove_filter('cron_schedules', [$plugin->cronSchedules(), 'addSchedules']);
        self::assertSame('jetpack_sync_interval', apply_filters('jetpack_sync_incremental_sync_interval', 'jetpack_sync_interval'));
    }

    /** Decision 6: the full-sync interval is tunable on its own. */
    public function testFullSyncIntervalIsSeparate(): void
    {
        $this->boot(['JETPACK_SYNC_QUIET_SETTINGS' => ['full_sync_interval' => 600]]);

        self::assertSame(CronSchedules::FULL, apply_filters('jetpack_sync_full_sync_interval', 'jetpack_sync_interval'));
        self::assertSame('jetpack_sync_interval', apply_filters('jetpack_sync_incremental_sync_interval', 'jetpack_sync_interval'));
        self::assertSame(600, wp_get_schedules()[CronSchedules::FULL]['interval']);
    }

    /** T22 (C21). */
    public function testSyncViaCronForcedOnByDefault(): void
    {
        $this->store(self::VIA_CRON_OPTION, '0');
        $plugin = $this->boot();

        self::assertSame(1, Settings::get_setting('sync_via_cron'));
        $status = $plugin->status()->collect();
        self::assertSame('yes', $status['sync_via_cron_forced']);
        self::assertSame('0', $status['sync_via_cron_stored']);
        self::assertSame('0', $this->stored(self::VIA_CRON_OPTION));
    }

    /** T22 (C21): with force off, the stored 0 shows through and status warns. */
    public function testSyncViaCronForceOffWarns(): void
    {
        $this->store(self::VIA_CRON_OPTION, '0');
        add_filter('jetpack_sync_quiet_force_cron', '__return_false');
        $plugin = $this->boot();

        self::assertSame(0, Settings::get_setting('sync_via_cron'));
        $status = $plugin->status()->collect();
        self::assertSame('no', $status['sync_via_cron_forced']);
        self::assertNotEmpty(array_filter($status['warnings'], static fn (string $w): bool => str_starts_with($w, 'sync_via_cron is off')));
        self::assertSame('not used (sync_via_cron is off)', $status['sync_cron_health']);
    }

    public function testForceCronConstant(): void
    {
        $this->store(self::VIA_CRON_OPTION, '0');
        $this->boot(['JETPACK_SYNC_QUIET_FORCE_CRON' => false]);

        self::assertSame(0, Settings::get_setting('sync_via_cron'));
    }

    public function testPrecedenceConstantSiteNetworkFilter(): void
    {
        WpState::$multisite = true;
        $plugin = $this->boot(['JETPACK_SYNC_QUIET_SETTINGS' => ['max_queue_size' => 4000]]);
        $tunables = $plugin->tunables();
        $tunables->set('max_queue_size', 3000);
        $tunables->set('max_queue_size', 3500, true);
        $tunables->set('max_queue_lag', 4000, true);
        $tunables->set('max_queue_lag', 5000);
        $tunables->set('upload_max_rows', 300, true);

        $resolved = $tunables->resolve();
        self::assertSame(4000, $resolved['values']['max_queue_size']);
        self::assertSame('constant', $resolved['sources']['max_queue_size']);
        self::assertSame(5000, $resolved['values']['max_queue_lag']);
        self::assertSame('site', $resolved['sources']['max_queue_lag']);
        self::assertSame('network', $resolved['sources']['upload_max_rows']);

        add_filter('jetpack_sync_quiet_settings', static function (array $s): array {
            $s['max_queue_size'] = 6000;

            return $s;
        });
        $tunables->reset();
        self::assertSame(6000, $tunables->value('max_queue_size'));
        self::assertSame('filter', $tunables->resolve()['sources']['max_queue_size']);
    }

    public function testCliSetGetUnset(): void
    {
        $this->env->constants['WP_CLI'] = true;
        $plugin = $this->boot();
        $command = \WP_CLI::$commands['jetpack-sync-quiet'];
        self::assertInstanceOf(\IndianOpinion\JetpackSyncQuiet\Cli\Command::class, $command);

        $command->settings(['set', 'cron_interval', '150'], []);
        self::assertSame(['cron_interval' => 150], $this->stored(Tunables::SITE_OPTION));
        $command->settings(['get', 'cron_interval'], []);
        self::assertStringContainsString('150 (site)', \WP_CLI::output());
        $command->settings(['unset', 'cron_interval'], []);
        self::assertFalse($this->stored(Tunables::SITE_OPTION));
        self::assertNull($plugin->tunables()->value('cron_interval'));
        $command->settings(['list'], []);
        self::assertStringContainsString("cron_interval\t\tjetpack default\t300\t150 to 1200", \WP_CLI::output());
    }
}
