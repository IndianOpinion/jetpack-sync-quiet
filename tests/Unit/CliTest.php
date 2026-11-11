<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Tests\Unit;

use Automattic\Jetpack\Sync\Settings;
use IndianOpinion\JetpackSyncQuiet\Cli\Command;
use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\CliExit;
use IndianOpinion\JetpackSyncQuiet\Tests\TestCase;

final class CliTest extends TestCase
{
    private function command(): Command
    {
        $this->env->constants['WP_CLI'] = true;
        $this->boot();
        $command = \WP_CLI::$commands['jetpack-sync-quiet'];
        self::assertInstanceOf(Command::class, $command);

        return $command;
    }

    public function testGuardRunPrintsResult(): void
    {
        $this->command()->guard(['run'], []);

        self::assertStringContainsString('Success: ok: Jetpack reads dedicated sync off', \WP_CLI::output());
    }

    public function testGuardRunWarnsOnFallback(): void
    {
        Settings::$prefix = 'jetpack_sync_renamed_';
        $this->store('jetpack_sync_renamed_dedicated_sync_enabled', '1');

        $this->command()->guard(['run'], []);

        self::assertStringContainsString('Warning: filter not reaching Jetpack; fallback applied', \WP_CLI::output());
    }

    public function testUnknownActionsFail(): void
    {
        $command = $this->command();
        foreach ([fn () => $command->guard(['go'], []), fn () => $command->settings(['drop'], []), fn () => $command->status([], ['format' => 'xml'])] as $call) {
            try {
                $call();
                self::fail('accepted');
            } catch (CliExit $e) {
                self::assertSame(1, $e->getCode());
            }
        }
    }

    public function testNetworkStatusNeedsMultisite(): void
    {
        $this->expectException(CliExit::class);
        $this->command()->status([], ['network' => true]);
    }

    public function testTableStatusListsFields(): void
    {
        $this->store(self::DEDICATED_OPTION, '1');
        $this->command()->status([], []);
        $out = \WP_CLI::output();

        self::assertStringContainsString("dedicated_sync_effective\toff", $out);
        self::assertStringContainsString("dedicated_sync_stored\t1", $out);
        self::assertStringContainsString("tunables.max_queue_size\t5000 (jetpack default)", $out);
    }
}
