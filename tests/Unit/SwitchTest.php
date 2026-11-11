<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Tests\Unit;

use Automattic\Jetpack\Sync\Actions;
use Automattic\Jetpack\Sync\Listener;
use Automattic\Jetpack\Sync\Sender;
use Automattic\Jetpack\Sync\Settings;
use IndianOpinion\JetpackSyncQuiet\OptionFilter;
use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState;
use IndianOpinion\JetpackSyncQuiet\Tests\TestCase;

/**
 * Core switch: C1 to C6, C13, C14, C26, C29, C30.
 */
final class SwitchTest extends TestCase
{
    /** T1 (C1). */
    public function testStoredOneReadsDisabledAndStoredValueStays(): void
    {
        $this->store(self::DEDICATED_OPTION, '1');
        Listener::get_instance()->queue->items = 12;
        $this->boot();

        self::assertFalse(Settings::is_dedicated_sync_enabled());
        self::assertSame('1', $this->stored(self::DEDICATED_OPTION));
        self::assertSame([], WpState::writesTo(self::DEDICATED_OPTION));

        (new Sender())->do_sync();
        self::assertSame([], WpState::$httpRequests, 'spawn path entered');
        self::assertSame(['sync'], Sender::$sentInProcess);
    }

    /** T2 (C2). */
    public function testMissingValueReadsZeroWithoutWritingTheDefault(): void
    {
        $this->boot();

        self::assertFalse(Settings::is_dedicated_sync_enabled());
        self::assertSame(0, Settings::get_setting('dedicated_sync_enabled'));
        self::assertSame([], WpState::writesTo(self::DEDICATED_OPTION));
        self::assertFalse($this->stored(self::DEDICATED_OPTION));
    }

    /** T2 control: without the plugin, Jetpack writes its default back. */
    public function testWithoutPluginJetpackWritesDefault(): void
    {
        Settings::get_setting('dedicated_sync_enabled');

        self::assertCount(1, WpState::writesTo(self::DEDICATED_OPTION));
    }

    /** T3 (C3). */
    public function testFilterReturnsStringZeroNeverFalse(): void
    {
        $plugin = $this->boot();
        $value = apply_filters('pre_option_' . self::DEDICATED_OPTION, false, self::DEDICATED_OPTION, false);

        self::assertSame('0', $value);
        self::assertSame('0', OptionFilter::DEDICATED_VALUE);
        self::assertNotFalse($plugin->optionFilter()->filterDedicated(false));
    }

    /** T4 (C4). */
    public function testRemovingFiltersRestoresStoredValue(): void
    {
        $this->store(self::DEDICATED_OPTION, '1');
        $plugin = $this->boot();
        self::assertFalse(Settings::is_dedicated_sync_enabled());

        $plugin->optionFilter()->unregister();

        self::assertTrue(Settings::is_dedicated_sync_enabled());
        self::assertSame([], WpState::writesTo(self::DEDICATED_OPTION));
    }

    /**
     * T5 (C5).
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function contexts(): array
    {
        return [
            'POST'           => ['post', true],
            'admin'          => ['admin', true],
            'manage_options' => ['manager', true],
            'WP-CLI'         => ['cli', true],
            'cron'           => ['cron', true],
            'front-end GET'  => ['get', false],
        ];
    }

    /**
     * @dataProvider contexts
     */
    public function testSyncFlowsInProcessAndNeverSpawns(string $context, bool $sends): void
    {
        $this->store(self::DEDICATED_OPTION, '1');
        Listener::get_instance()->queue->items = 40;
        match ($context) {
            'post' => Actions::$isPost = true,
            'admin' => WpState::$isAdmin = true,
            'manager' => WpState::$caps['manage_options'] = true,
            'cli' => Actions::$isCli = true,
            'cron' => Actions::$doingCron = true,
            default => null,
        };
        Actions::load();
        $this->boot();
        do_action('plugins_loaded');
        do_action('init');
        do_action('shutdown');

        self::assertSame($sends ? ['sync'] : [], Sender::$sentInProcess);
        self::assertSame([], WpState::$httpRequests);
    }

    /** T5 control: without the plugin a front-end GET spawns a loopback. */
    public function testWithoutPluginFrontEndGetSpawnsLoopback(): void
    {
        $this->store(self::DEDICATED_OPTION, '1');
        Listener::get_instance()->queue->items = 40;
        Actions::load();
        do_action('plugins_loaded');
        do_action('init');
        do_action('shutdown');

        self::assertCount(1, WpState::$httpRequests);
        self::assertStringContainsString('spawn-sync', WpState::$httpRequests[0]['url']);
    }

    /** T6 (C6, C30). */
    public function testRegistersNothingOnRestOrSpawnRoute(): void
    {
        WpState::$isAdmin = true;
        $this->env->constants['WP_CLI'] = true;
        $this->boot();
        do_action('plugins_loaded');
        do_action('init');

        foreach (array_keys(WpState::$hooks) as $hook) {
            self::assertStringNotContainsString('rest_', $hook);
            self::assertStringNotContainsString('spawn', $hook);
            self::assertNotSame('parse_request', $hook);
        }
    }

    /** T13 (C13): loaded as a must-use plugin, before Jetpack. */
    public function testMuPluginLoadedBeforeJetpackFiltersFirstRead(): void
    {
        $this->store(self::DEDICATED_OPTION, '1');
        $this->boot([], null, '/srv/wp/wp-content/mu-plugins/jetpack-sync-quiet/jetpack-sync-quiet.php');
        Actions::load();
        self::assertSame(0, Settings::$reads, 'Jetpack read at file load');

        do_action('plugins_loaded');

        self::assertFalse(Actions::$dedicatedAtInit);
    }

    /** T14 (C14): normal plugin loaded after Jetpack's file, before plugins_loaded and init. */
    public function testNormalPluginLoadedAfterJetpackStillFiltersFirstRead(): void
    {
        $this->store(self::DEDICATED_OPTION, '1');
        Actions::load();
        $this->boot();

        do_action('plugins_loaded');

        self::assertFalse(Actions::$dedicatedAtInit);
    }

    /** T26 (C26). */
    public function testNoOutboundRequestAtAll(): void
    {
        $this->store(self::DEDICATED_OPTION, '1');
        Listener::get_instance()->queue->items = 500;
        Listener::get_instance()->queue->lagSeconds = 7200.0;
        $plugin = $this->boot();
        Actions::load();
        do_action('plugins_loaded');
        do_action('init');
        do_action('wp_loaded');
        $plugin->guard()->run('manual');
        $plugin->status()->collect();
        do_action('shutdown');

        self::assertSame([], WpState::$httpRequests);
    }

    /** T28 (C29). */
    public function testObjectCacheCannotLeakStoredValue(): void
    {
        WpState::$objectCache[1][self::DEDICATED_OPTION] = '1';
        $this->boot();

        self::assertSame('0', get_option(self::DEDICATED_OPTION));
        self::assertFalse(Settings::is_dedicated_sync_enabled());
    }

    public function testWpcomHeaderOnStoresOneButReadStaysOff(): void
    {
        $this->store(self::DEDICATED_OPTION, '0');
        $this->boot();

        \Automattic\Jetpack\Sync\Dedicated_Sender::maybe_change_dedicated_sync_status_from_wpcom_header('on');

        self::assertFalse(Settings::is_dedicated_sync_enabled());
    }

    public function testWpcomHeaderOnStoresOneAndJetpackRevertsItAfterOneValidation(): void
    {
        $this->store(self::DEDICATED_OPTION, '0');
        $this->boot();

        \Automattic\Jetpack\Sync\Dedicated_Sender::maybe_change_dedicated_sync_status_from_wpcom_header('on');

        $writes = array_column(WpState::writesTo(self::DEDICATED_OPTION), 'value');
        self::assertSame([1, 0], $writes);
        self::assertCount(1, WpState::$httpRequests);
    }

    public function testWpcomHeaderOnWritesNothingWhenNeverStored(): void
    {
        $this->boot();

        \Automattic\Jetpack\Sync\Dedicated_Sender::maybe_change_dedicated_sync_status_from_wpcom_header('on');

        self::assertSame([], WpState::writesTo(self::DEDICATED_OPTION));
        self::assertSame([], WpState::$httpRequests);
        self::assertFalse(Settings::is_dedicated_sync_enabled());
    }
}
