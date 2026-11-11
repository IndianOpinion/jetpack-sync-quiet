<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Tests\Unit;

use IndianOpinion\JetpackSyncQuiet\Guard;
use IndianOpinion\JetpackSyncQuiet\Installer;
use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\CliExit;
use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState;
use IndianOpinion\JetpackSyncQuiet\Tests\TestCase;
use IndianOpinion\JetpackSyncQuiet\Tunables;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * T32: uninstall and purge.
 */
final class InstallerTest extends TestCase
{
    private function seed(): void
    {
        $this->store(self::DEDICATED_OPTION, '1');
        $this->store('jetpack_sync_settings_max_queue_size', '7000');
        $plugin = $this->boot();
        $plugin->tunables()->set('cron_interval', 150);
        $plugin->guard()->run('manual');
        $plugin->guard()->maybeSchedule();
        $plugin->guard()->onUpgrade();
    }

    public function testPurgeRemovesOwnDataOnlyAndCustomScheduleGoesAway(): void
    {
        $this->seed();
        self::assertNotFalse($this->stored(Tunables::SITE_OPTION));
        self::assertNotFalse($this->stored(Guard::STATE_OPTION));
        self::assertArrayHasKey('jetpack_sync_quiet_incremental', wp_get_schedules());

        Installer::purge();
        \IndianOpinion\JetpackSyncQuiet\Plugin::instance()?->tunables()->reset();

        self::assertFalse($this->stored(Tunables::SITE_OPTION));
        self::assertFalse($this->stored(Guard::STATE_OPTION));
        self::assertFalse(wp_next_scheduled(Guard::HOOK));
        self::assertFalse(wp_next_scheduled(Guard::UPGRADE_HOOK));
        self::assertArrayNotHasKey('jetpack_sync_quiet_incremental', wp_get_schedules());
        self::assertSame('1', $this->stored(self::DEDICATED_OPTION));
        self::assertSame('7000', $this->stored('jetpack_sync_settings_max_queue_size'));
    }

    public function testNetworkPurgeCoversEverySiteAndNetworkOption(): void
    {
        WpState::$multisite = true;
        WpState::$sites = [1, 2];
        $plugin = $this->boot();
        $plugin->tunables()->set('max_queue_size', 8000, true);
        $plugin->tunables()->set('max_queue_size', 9000);
        switch_to_blog(2);
        $plugin->tunables()->set('max_queue_size', 10000);
        restore_current_blog();

        Installer::purge(true);

        self::assertArrayNotHasKey(Tunables::NETWORK_OPTION, WpState::$siteOptions);
        self::assertArrayNotHasKey(Tunables::SITE_OPTION, WpState::$options[1]);
        self::assertArrayNotHasKey(Tunables::SITE_OPTION, WpState::$options[2]);
    }

    public function testCliPurgeNeedsConfirmation(): void
    {
        $this->env->constants['WP_CLI'] = true;
        $this->seed();
        $command = \WP_CLI::$commands['jetpack-sync-quiet'];
        self::assertInstanceOf(\IndianOpinion\JetpackSyncQuiet\Cli\Command::class, $command);

        try {
            $command->purge([], []);
            self::fail('purged without confirmation');
        } catch (CliExit) {
            self::assertNotFalse($this->stored(Tunables::SITE_OPTION));
        }

        $command->purge([], ['yes' => true]);
        self::assertFalse($this->stored(Tunables::SITE_OPTION));
        self::assertStringContainsString('Success: Purged.', \WP_CLI::output());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUninstallFile(): void
    {
        WpState::reset();
        WpState::$options[1] = [
            Tunables::SITE_OPTION => ['max_queue_size' => 8000],
            Guard::STATE_OPTION => ['last_check' => 1],
            self::DEDICATED_OPTION => '1',
        ];
        define('WP_UNINSTALL_PLUGIN', 'jetpack-sync-quiet/jetpack-sync-quiet.php');

        require __DIR__ . '/../../uninstall.php';

        self::assertSame([self::DEDICATED_OPTION => '1'], WpState::$options[1]);
    }
}
