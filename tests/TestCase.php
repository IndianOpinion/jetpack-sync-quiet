<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Tests;

use Automattic\Jetpack\Connection\Manager;
use Automattic\Jetpack\Sync\Actions;
use Automattic\Jetpack\Sync\Listener;
use Automattic\Jetpack\Sync\Settings;
use IndianOpinion\JetpackSyncQuiet\JetpackSync;
use IndianOpinion\JetpackSyncQuiet\Plugin;
use IndianOpinion\JetpackSyncQuiet\Tests\Support\FakeEnvironment;
use IndianOpinion\JetpackSyncQuiet\Tests\Support\FakeLogger;
use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected const DEDICATED_OPTION = 'jetpack_sync_settings_dedicated_sync_enabled';
    protected const VIA_CRON_OPTION = 'jetpack_sync_settings_sync_via_cron';

    /** 2026-11-11 12:00:00 UTC. */
    protected const NOW = 1794398400;

    protected FakeEnvironment $env;
    protected FakeLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        WpState::reset();
        Settings::reset();
        Actions::reset();
        Listener::reset();
        Manager::$connected = true;
        \WP_CLI::reset();
        $this->env = new FakeEnvironment(self::NOW);
        $this->logger = new FakeLogger();
    }

    /**
     * @param array<string, mixed> $constants
     */
    protected function boot(array $constants = [], ?JetpackSync $jetpack = null, string $file = '/srv/wp/wp-content/plugins/jetpack-sync-quiet/jetpack-sync-quiet.php'): Plugin
    {
        $this->env->constants = array_merge($this->env->constants, $constants);

        return Plugin::boot($file, $this->env, $jetpack ?? new JetpackSync(), $this->logger);
    }

    protected function store(string $option, mixed $value): void
    {
        WpState::$options[WpState::$blogId][$option] = $value;
    }

    protected function stored(string $option): mixed
    {
        return WpState::$options[WpState::$blogId][$option] ?? false;
    }

    protected function missingJetpack(): JetpackSync
    {
        return new JetpackSync('Missing\\Sync\\Settings', 'Missing\\Sync\\Actions', 'Missing\\Sync\\Listener', 'Missing\\Sync\\Package_Version', 'Missing\\Connection\\Manager');
    }
}
