<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Tests\Unit;

use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState;
use IndianOpinion\JetpackSyncQuiet\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Failure modes (section 4.3) and the loader.
 */
final class RequirementsTest extends TestCase
{
    /** T30: PHP below 8.1 means a no-op with a notice, never a fatal. */
    public function testOldPhpIsRejectedWithANotice(): void
    {
        self::assertFalse(jetpack_sync_quiet_requirements_met(80027));
        self::assertFalse(jetpack_sync_quiet_requirements_met(70400));
        self::assertTrue(jetpack_sync_quiet_requirements_met(80100));
        self::assertTrue(jetpack_sync_quiet_requirements_met(80300));

        WpState::$caps['manage_options'] = true;
        ob_start();
        jetpack_sync_quiet_requirements_notice();
        self::assertStringContainsString('needs PHP 8.1 or later', (string) ob_get_clean());

        WpState::$caps = [];
        ob_start();
        jetpack_sync_quiet_requirements_notice();
        self::assertSame('', ob_get_clean());
    }

    /** The requirement file must parse on old PHP: no namespace, no types, no new syntax. */
    public function testRequirementFileAvoidsNewSyntax(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/requirements.php');

        self::assertStringNotContainsString('namespace ', $source);
        self::assertStringNotContainsString('declare(strict_types', $source);
        self::assertDoesNotMatchRegularExpression('/function \w+\([^)]*\b(int|string|bool|array)\s+\$/', $source);
        self::assertDoesNotMatchRegularExpression('/\)\s*:\s*\w+/', $source);
        self::assertStringNotContainsString('fn (', $source);
        self::assertStringNotContainsString('?->', $source);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLoaderBootsOnceAndRegistersTheSwitch(): void
    {
        WpState::reset();
        require __DIR__ . '/../../jetpack-sync-quiet.php';

        self::assertTrue(defined('JETPACK_SYNC_QUIET_FILE'));
        self::assertSame(1000, has_filter('pre_option_' . self::DEDICATED_OPTION, [\IndianOpinion\JetpackSyncQuiet\Plugin::instance()?->optionFilter(), 'filterDedicated']));
        $first = \IndianOpinion\JetpackSyncQuiet\Plugin::instance();

        require __DIR__ . '/../../jetpack-sync-quiet.php';
        self::assertSame($first, \IndianOpinion\JetpackSyncQuiet\Plugin::instance());
        self::assertCount(1, WpState::$hooks['pre_option_' . self::DEDICATED_OPTION][1000]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMuLoaderRequiresThePluginFolder(): void
    {
        WpState::reset();
        $dir = sys_get_temp_dir() . '/jsq-mu-' . getmypid();
        mkdir($dir . '/jetpack-sync-quiet', 0777, true);
        file_put_contents($dir . '/jetpack-sync-quiet/jetpack-sync-quiet.php', "<?php\ndefine('JSQ_MU_LOADED', true);\n");
        copy(__DIR__ . '/../../mu-loader/jetpack-sync-quiet-loader.php', $dir . '/jetpack-sync-quiet-loader.php');

        require $dir . '/jetpack-sync-quiet-loader.php';

        self::assertTrue(defined('JSQ_MU_LOADED'));
        unlink($dir . '/jetpack-sync-quiet/jetpack-sync-quiet.php');
        unlink($dir . '/jetpack-sync-quiet-loader.php');
        rmdir($dir . '/jetpack-sync-quiet');
        rmdir($dir);
    }
}
