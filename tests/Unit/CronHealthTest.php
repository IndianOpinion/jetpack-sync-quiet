<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Tests\Unit;

use Automattic\Jetpack\Sync\Actions;
use Automattic\Jetpack\Sync\Listener;
use IndianOpinion\JetpackSyncQuiet\Plugin;
use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\CliExit;
use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState;
use IndianOpinion\JetpackSyncQuiet\Tests\TestCase;

/**
 * WP-Cron variants: C22 to C25, plus the queue fields.
 */
final class CronHealthTest extends TestCase
{
    private function scheduleSyncCron(int $next): void
    {
        add_filter('cron_schedules', [Actions::class, 'jetpack_cron_schedule']);
        wp_schedule_event($next, 'jetpack_sync_interval', 'jetpack_sync_cron');
    }

    /**
     * @param array<string, mixed> $status
     */
    private static function hasWarning(array $status, string $needle): bool
    {
        foreach ($status['warnings'] as $warning) {
            if (str_contains($warning, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** T23 (C22). */
    public function testSystemCronOk(): void
    {
        $plugin = $this->boot(['DISABLE_WP_CRON' => true]);
        $this->scheduleSyncCron(self::NOW + 120);

        $status = $plugin->status()->collect();

        self::assertSame('true', $status['disable_wp_cron']);
        self::assertSame('ok', $status['system_cron']);
        self::assertSame('ok', $status['sync_cron_health']);
        self::assertSame('jetpack_sync_interval (300 s)', $status['sync_cron_schedule']);
        self::assertSame([], $status['warnings']);
    }

    /** T23 boundary: one interval late still counts as having run within twice the interval. */
    public function testOneIntervalLateIsStillOk(): void
    {
        $plugin = $this->boot(['DISABLE_WP_CRON' => true]);
        $this->scheduleSyncCron(self::NOW - 300);

        self::assertSame('ok', $plugin->status()->collect()['system_cron']);
    }

    /** T24 (C23). */
    public function testSystemCronMissingWarnsAndStrictExitsOne(): void
    {
        $this->env->constants['WP_CLI'] = true;
        $plugin = $this->boot(['DISABLE_WP_CRON' => true]);
        $this->scheduleSyncCron(self::NOW - 3600);

        $status = $plugin->status()->collect();
        self::assertSame('not running', $status['system_cron']);
        self::assertSame('overdue', $status['sync_cron_health']);
        self::assertTrue(self::hasWarning($status, 'WP-Cron is not running; sync only happens on POST, admin and CLI requests'));
        self::assertTrue(self::hasWarning($status, 'DISABLE_WP_CRON is true'));
        self::assertSame('due 1 h 0 min ago', $status['oldest_due_cron_event']);

        $command = \WP_CLI::$commands['jetpack-sync-quiet'];
        self::assertInstanceOf(\IndianOpinion\JetpackSyncQuiet\Cli\Command::class, $command);
        $command->status([], []);
        try {
            $command->status([], ['strict' => true]);
            self::fail('strict did not exit');
        } catch (CliExit $e) {
            self::assertSame(1, $e->getCode());
        }
    }

    /** T24 (C24): traffic-driven WP-Cron on a quiet site. */
    public function testTrafficCronOverdueWarns(): void
    {
        $plugin = $this->boot();
        $this->scheduleSyncCron(self::NOW - 1000);

        $status = $plugin->status()->collect();

        self::assertArrayNotHasKey('system_cron', $status);
        self::assertTrue(self::hasWarning($status, 'depends on visitor traffic'));
    }

    public function testStrictPassesWithoutWarnings(): void
    {
        $this->env->constants['WP_CLI'] = true;
        $this->boot();
        $this->scheduleSyncCron(self::NOW + 60);
        $command = \WP_CLI::$commands['jetpack-sync-quiet'];
        self::assertInstanceOf(\IndianOpinion\JetpackSyncQuiet\Cli\Command::class, $command);

        $command->status([], ['strict' => true, 'format' => 'json']);

        $json = json_decode(\WP_CLI::$out[0], true);
        self::assertIsArray($json);
        self::assertSame('active', $json['plugin']);
        self::assertSame([], $json['warnings']);
    }

    public function testNotScheduledWarns(): void
    {
        $plugin = $this->boot();

        $status = $plugin->status()->collect();

        self::assertSame('not scheduled', $status['sync_cron_health']);
        self::assertTrue(self::hasWarning($status, 'jetpack_sync_cron is not scheduled'));
    }

    /** T25 (C25). */
    public function testTimeLimitAboveMaxExecutionTimeWarns(): void
    {
        $this->env->ini['max_execution_time'] = '120';
        $plugin = $this->boot();

        $status = $plugin->status()->collect();

        self::assertSame('240 s', $status['cron_sync_time_limit']);
        self::assertSame('120 s', $status['max_execution_time']);
        self::assertTrue(self::hasWarning($status, 'cron_sync_time_limit (240 s)'));

        $plugin->tunables()->set('cron_sync_time_limit', 120);
        $status = $plugin->status()->collect();
        self::assertTrue(self::hasWarning($status, 'cron_sync_time_limit (120 s)'));

        $this->env->ini['max_execution_time'] = '300';
        self::assertFalse(self::hasWarning($plugin->status()->collect(), 'cron_sync_time_limit'));
    }

    public function testQueueFieldsFromJetpackStatus(): void
    {
        Actions::$status = [
            'queue_size'      => 42,
            'queue_lag'       => 95.4,
            'queue_next_sync' => 7.2,
            'debug_details'   => ['last_succesful_sync' => (string) (self::NOW - 600) . '.25'],
        ];
        $plugin = $this->boot();

        $status = $plugin->status()->collect();

        self::assertSame(42, $status['queue_size']);
        self::assertSame('95 s (1 min 35 s)', $status['queue_lag']);
        self::assertSame('2026-11-11 11:50:00 UTC (10 min 0 s ago, from Jetpack status)', $status['last_successful_sync']);
        self::assertSame('in 7 s', $status['next_sync']);
    }

    public function testLastSyncEstimatedFromQueueWhenStatusLacksIt(): void
    {
        Listener::get_instance()->queue->items = 3;
        Listener::get_instance()->queue->lagSeconds = 120.0;
        $plugin = $this->boot();

        $status = $plugin->status()->collect();

        self::assertSame('no later than 2026-11-11 11:58:00 UTC (estimated from queue lag)', $status['last_successful_sync']);
    }

    public function testLastSyncUnknownWithEmptyQueue(): void
    {
        $plugin = $this->boot();

        self::assertSame('unknown', $plugin->status()->collect()['last_successful_sync']);
    }

    public function testStatusRowsFlattenNestedFields(): void
    {
        $rows = \IndianOpinion\JetpackSyncQuiet\Status::rows(['a' => 'x', 'tunables' => ['k' => '1'], 'warnings' => []]);

        self::assertSame([
            ['field' => 'a', 'value' => 'x'],
            ['field' => 'tunables.k', 'value' => '1'],
            ['field' => 'warnings', 'value' => 'none'],
        ], $rows);
        self::assertSame('0.1.0', Plugin::VERSION);
        self::assertSame([], WpState::$httpRequests);
    }
}
