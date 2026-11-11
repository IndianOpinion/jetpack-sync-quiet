<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Tests\Unit;

use Automattic\Jetpack\Sync\Settings;
use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState;
use IndianOpinion\JetpackSyncQuiet\Tests\TestCase;

/**
 * Multisite: C27, C28.
 */
final class MultisiteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WpState::$multisite = true;
        WpState::$sites = [1, 2];
        WpState::$options[1][self::DEDICATED_OPTION] = '1';
        WpState::$options[2][self::DEDICATED_OPTION] = '1';
    }

    /** T27 (C27). */
    public function testFilterOnEverySiteNetworkTunableAndSiteOverride(): void
    {
        $this->env->constants['WP_CLI'] = true;
        $plugin = $this->boot();
        $tunables = $plugin->tunables();
        $tunables->set('max_queue_size', 8000, true);
        switch_to_blog(2);
        $tunables->set('max_queue_size', 12000);
        restore_current_blog();

        self::assertFalse(Settings::is_dedicated_sync_enabled());
        self::assertSame(8000, Settings::get_setting('max_queue_size'));
        switch_to_blog(2);
        self::assertFalse(Settings::is_dedicated_sync_enabled());
        self::assertSame(12000, Settings::get_setting('max_queue_size'));
        restore_current_blog();

        $command = \WP_CLI::$commands['jetpack-sync-quiet'];
        self::assertInstanceOf(\IndianOpinion\JetpackSyncQuiet\Cli\Command::class, $command);
        $command->status([], ['network' => true]);
        $out = \WP_CLI::output();
        self::assertStringContainsString("1\tactive\toff", $out);
        self::assertStringContainsString("2\tactive\toff", $out);
        self::assertSame(1, WpState::$blogId);
    }

    /** T27 (C28): plugin active on one site only. */
    public function testSingleSiteActivationAffectsOneSite(): void
    {
        // Site 1 loads the plugin; site 2 is a separate request that does not.
        switch_to_blog(2);
        self::assertTrue(Settings::is_dedicated_sync_enabled());
        restore_current_blog();

        $plugin = $this->boot();
        self::assertFalse(Settings::is_dedicated_sync_enabled());
        self::assertSame('this site only', $plugin->installType());
        self::assertSame('this site only', $plugin->status()->collect()['install_type']);
    }

    public function testNetworkSettingNeedsMultisite(): void
    {
        WpState::$multisite = false;
        $plugin = $this->boot();

        $this->expectException(\IndianOpinion\JetpackSyncQuiet\Exception\InvalidTunableException::class);
        $plugin->tunables()->set('max_queue_size', 8000, true);
    }
}
