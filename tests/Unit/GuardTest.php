<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Tests\Unit;

use Automattic\Jetpack\Connection\Manager;
use Automattic\Jetpack\Sync\Dedicated_Sender;
use Automattic\Jetpack\Sync\Settings;
use IndianOpinion\JetpackSyncQuiet\Admin\AdminPage;
use IndianOpinion\JetpackSyncQuiet\Guard;
use IndianOpinion\JetpackSyncQuiet\JetpackSync;
use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState;
use IndianOpinion\JetpackSyncQuiet\Tests\TestCase;

/**
 * Resets by Jetpack: C7 to C12, C17.
 */
final class GuardTest extends TestCase
{
    /** @var list<array<int, mixed>> */
    private array $driftEvents = [];

    /** @var list<array<int, mixed>> */
    private array $fallbackEvents = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->driftEvents = [];
        $this->fallbackEvents = [];
    }

    private function listen(): void
    {
        add_action('jetpack_sync_quiet_drift_detected', function (mixed ...$args): void {
            $this->driftEvents[] = $args;
        }, 10, 3);
        add_action('jetpack_sync_quiet_fallback_applied', function (mixed ...$args): void {
            $this->fallbackEvents[] = $args;
        }, 10, 2);
    }

    /** T7 (C7, C11). */
    public function testStoredValueChangeIsRecordedOnceAndNotFought(): void
    {
        $this->store(self::DEDICATED_OPTION, '0');
        $plugin = $this->boot();
        $this->listen();

        // A remote settings write from WordPress.com.
        Settings::update_settings(['dedicated_sync_enabled' => 1]);
        WpState::$options[1][self::DEDICATED_OPTION] = 1; // Remote write succeeded; nothing reverted it.

        self::assertFalse(Settings::is_dedicated_sync_enabled());
        self::assertCount(1, $this->driftEvents);
        self::assertSame([1, '0', 'stored value changed'], $this->driftEvents[0]);

        // Daily guard on the same day: same event, not a second one.
        $plugin->guard()->run('daily');
        self::assertCount(1, $this->driftEvents);
        self::assertSame('1', $plugin->guard()->state()['last_drift']['stored'] ?? null);
        self::assertSame(1, $this->stored(self::DEDICATED_OPTION), 'the plugin fought the write');
        self::assertCount(1, array_filter($this->logger->lines, static fn (string $l): bool => str_contains($l, 'drift')));
    }

    /** T7 (C7): the WordPress.com header path, repeated, is one event. */
    public function testRepeatedHeaderFlapsAreOneDriftEvent(): void
    {
        $this->store(self::DEDICATED_OPTION, '0');
        $this->boot();
        $this->listen();

        Dedicated_Sender::maybe_change_dedicated_sync_status_from_wpcom_header('on');
        Dedicated_Sender::maybe_change_dedicated_sync_status_from_wpcom_header('on');

        self::assertFalse(Settings::is_dedicated_sync_enabled());
        self::assertCount(1, $this->driftEvents);
    }

    /** A drift that persists is announced again after a day, not on every request. */
    public function testPersistentDriftIsAnnouncedAtMostDaily(): void
    {
        $this->store(self::DEDICATED_OPTION, '1');
        $plugin = $this->boot();
        $this->listen();

        $plugin->guard()->run('daily');
        $plugin->guard()->run('daily');
        self::assertCount(1, $this->driftEvents);

        $this->env->time += 86400;
        $plugin->guard()->run('daily');
        self::assertCount(2, $this->driftEvents);
    }

    /** T8 (C8). */
    public function testFallbackWritesZeroWhenFilterStopsReachingJetpack(): void
    {
        $this->env->constants['JETPACK__VERSION'] = '99.0';
        Settings::$prefix = 'jetpack_sync_renamed_';
        $this->store('jetpack_sync_renamed_dedicated_sync_enabled', '1');
        $this->store('jetpack_sync_renamed_disable', 0);
        $this->store('jetpack_sync_renamed_network_disable', 0);
        WpState::$caps['manage_options'] = true;
        WpState::$isAdmin = true;
        $plugin = $this->boot();
        $this->listen();
        self::assertTrue(Settings::is_dedicated_sync_enabled());

        do_action('wp_loaded');

        self::assertFalse(Settings::is_dedicated_sync_enabled());
        self::assertSame(0, $this->stored('jetpack_sync_renamed_dedicated_sync_enabled'));
        $state = $plugin->guard()->state();
        self::assertSame(Guard::FALLBACK_APPLIED, $state['last_fallback']['result'] ?? null);
        self::assertStringContainsString('Jetpack 99.0', $state['last_fallback']['jetpack_version'] ?? '');
        self::assertSame([['Jetpack 99.0, sync package 5.5.1', 'applied']], $this->fallbackEvents);

        ob_start();
        (new AdminPage($plugin))->renderNotice();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Jetpack 99.0', $html);
        self::assertStringContainsString('notice-warning', $html);

        $warnings = $plugin->status()->collect()['warnings'];
        self::assertNotEmpty(array_filter($warnings, static fn (string $w): bool => str_contains($w, 'fallback write applied')));
    }

    /** T9 (C8). */
    public function testFallbackDisabledByConstantWarnsOnly(): void
    {
        Settings::$prefix = 'jetpack_sync_renamed_';
        $this->store('jetpack_sync_renamed_dedicated_sync_enabled', '1');
        $plugin = $this->boot(['JETPACK_SYNC_QUIET_GUARD_FALLBACK' => false]);

        $result = $plugin->guard()->run('manual');

        self::assertStringContainsString('warning only', $result);
        self::assertSame('1', $this->stored('jetpack_sync_renamed_dedicated_sync_enabled'));
        self::assertSame(Guard::FALLBACK_DISABLED, $plugin->guard()->state()['last_fallback']['result'] ?? null);
        self::assertNull($plugin->guard()->state()['notice']);
        self::assertCount(1, $this->logger->lines);

        // Per-request check later the same day: no new log line.
        do_action('wp_loaded');
        self::assertCount(1, $this->logger->lines);
    }

    /** T10 (C9). */
    public function testFailedFallbackIsRecordedAndNotRetriedTheSameDay(): void
    {
        Settings::$prefix = 'jetpack_sync_renamed_';
        Settings::$refuseUpdates = true;
        $this->store('jetpack_sync_renamed_dedicated_sync_enabled', '1');
        $plugin = $this->boot();
        $this->listen();

        $first = $plugin->guard()->run('manual');
        $second = $plugin->guard()->run('manual');
        $writes = count(WpState::$writes);
        do_action('wp_loaded');

        self::assertSame('filter not reaching Jetpack; fallback failed', $first);
        self::assertStringContainsString('already tried today', $second);
        self::assertCount(1, $this->fallbackEvents);
        self::assertSame(Guard::FALLBACK_FAILED, $plugin->guard()->state()['notice']['result'] ?? null);
        self::assertSame($writes, count(WpState::$writes), 'the per-request check wrote on the same day');

        // Next day: one more try.
        $this->env->time += 86400;
        $plugin->guard()->run('daily');
        self::assertCount(2, $this->fallbackEvents);
    }

    /** T11 (C10). */
    public function testDedicatedSettingMissingFromJetpackMeansNothingToDo(): void
    {
        $jetpack = new JetpackSync('Automattic\\Jetpack\\Sync\\Settings_Without_Dedicated');
        $plugin = $this->boot([], $jetpack);

        self::assertSame(Guard::RESULT_NOT_PRESENT, $plugin->guard()->run('manual'));
        self::assertSame('not present in this Jetpack version', $plugin->status()->collect()['dedicated_sync_effective']);
        self::assertSame([], WpState::writesTo(self::DEDICATED_OPTION));
    }

    /** T12 (C12). */
    public function testStatusShowsValidationLoopbackUnreachable(): void
    {
        $this->store(self::DEDICATED_OPTION, '1');
        $plugin = $this->boot();

        $status = $plugin->status()->collect();

        self::assertStringStartsWith('unreachable', $status['validation_loopback']);
        self::assertSame('off', $status['dedicated_sync_effective']);
        self::assertSame('1', $status['dedicated_sync_stored']);
        self::assertSame('drift (stored on, effective off)', $status['dedicated_sync_match']);
    }

    /** T18 (C17): not connected. */
    public function testNotConnectedGuardWritesNothing(): void
    {
        Manager::$connected = false;
        Settings::$prefix = 'jetpack_sync_renamed_';
        $this->store('jetpack_sync_renamed_dedicated_sync_enabled', '1');
        $plugin = $this->boot();

        $result = $plugin->guard()->run('manual');

        self::assertStringStartsWith('sync inactive: not connected', $result);
        self::assertSame('1', $this->stored('jetpack_sync_renamed_dedicated_sync_enabled'));
        self::assertStringContainsString('not connected', $plugin->stateDescription());
        self::assertSame('sync inactive: not connected', $plugin->status()->collect()['sync']);
    }

    /** T18 (C17): sync disabled by Jetpack's disable setting. */
    public function testDisabledByJetpackSettingGuardWritesNothing(): void
    {
        $this->store('jetpack_sync_settings_disable', 1);
        $this->store(self::DEDICATED_OPTION, '0');
        $plugin = $this->boot();
        $before = count(WpState::writesTo(self::DEDICATED_OPTION));

        $result = $plugin->guard()->run('manual');

        self::assertStringStartsWith('sync disabled by Jetpack setting', $result);
        self::assertSame($before, count(WpState::writesTo(self::DEDICATED_OPTION)));
        self::assertSame('sync disabled by Jetpack setting', $plugin->status()->collect()['sync']);
    }

    public function testNormalRequestDoesNotTouchGuardState(): void
    {
        $this->boot();
        do_action('wp_loaded');

        self::assertSame([], WpState::writesTo(Guard::STATE_OPTION));
    }

    public function testUpgradeSchedulesOneCheckAMinuteLater(): void
    {
        $this->boot();
        do_action('upgrader_process_complete');
        do_action('upgrader_process_complete');

        self::assertSame(self::NOW + 60, wp_next_scheduled(Guard::UPGRADE_HOOK));
        self::assertCount(1, array_filter(_get_cron_array(), static fn (array $hooks): bool => isset($hooks[Guard::UPGRADE_HOOK])));
    }

    public function testDailyGuardEventIsScheduledOnInit(): void
    {
        $this->boot();
        do_action('plugins_loaded');
        do_action('init');

        self::assertNotFalse(wp_next_scheduled(Guard::HOOK));
        self::assertSame('daily', wp_get_schedule(Guard::HOOK));
    }
}
