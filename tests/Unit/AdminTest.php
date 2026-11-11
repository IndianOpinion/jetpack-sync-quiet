<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Tests\Unit;

use IndianOpinion\JetpackSyncQuiet\Admin\AdminPage;
use IndianOpinion\JetpackSyncQuiet\Guard;
use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpDieException;
use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState;
use IndianOpinion\JetpackSyncQuiet\Tests\TestCase;

/**
 * T31: admin page, capability, nonce, escaping.
 */
final class AdminTest extends TestCase
{
    private function page(): AdminPage
    {
        WpState::$isAdmin = true;
        $page = new AdminPage($this->boot());
        $page->exitAfterRedirect = false;

        return $page;
    }

    public function testMenusUseTheRightCapabilities(): void
    {
        $page = $this->page();
        $page->addMenu();
        $page->addNetworkMenu();

        self::assertSame(1, did_action('menu:tools:jetpack-sync-quiet:manage_options'));
        self::assertSame(1, did_action('menu:settings.php:jetpack-sync-quiet:manage_network_options'));
    }

    public function testRunGuardNeedsCapability(): void
    {
        $page = $this->page();
        $_POST = $_REQUEST = ['_wpnonce' => wp_create_nonce(AdminPage::NONCE)];

        $this->expectException(WpDieException::class);
        try {
            $page->handleRunGuard();
        } finally {
            self::assertSame([], WpState::writesTo(Guard::STATE_OPTION));
        }
    }

    public function testRunGuardNeedsValidNonce(): void
    {
        $page = $this->page();
        WpState::$caps['manage_options'] = true;
        $_POST = $_REQUEST = ['_wpnonce' => 'forged'];

        $this->expectException(WpDieException::class);
        try {
            $page->handleRunGuard();
        } finally {
            self::assertSame([], WpState::writesTo(Guard::STATE_OPTION));
        }
    }

    public function testRunGuardWithCapabilityAndNonceRunsAndRedirects(): void
    {
        $page = $this->page();
        WpState::$caps['manage_options'] = true;
        $_POST = $_REQUEST = ['_wpnonce' => wp_create_nonce(AdminPage::NONCE)];

        $page->handleRunGuard();

        self::assertCount(1, WpState::writesTo(Guard::STATE_OPTION));
        self::assertCount(1, WpState::$redirects);
        self::assertSame('https://example.test/wp-admin/tools.php?page=jetpack-sync-quiet&jsq_guard_ran=1', WpState::$redirects[0]);
    }

    public function testResultComesFromGuardStateNotFromTheUrl(): void
    {
        $page = $this->page();
        WpState::$caps['manage_options'] = true;
        $_POST = $_REQUEST = ['_wpnonce' => wp_create_nonce(AdminPage::NONCE)];
        $page->handleRunGuard();
        $_GET = [AdminPage::RESULT_ARG => '1', 'jsq_result' => 'Visit evil.example to fix Jetpack'];

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Guard result: ' . Guard::RESULT_OK, $html);
        self::assertStringNotContainsString('evil.example', $html);
    }

    public function testNetworkRunNeedsNetworkCapability(): void
    {
        WpState::$multisite = true;
        $page = $this->page();
        WpState::$caps['manage_options'] = true;
        $_POST = $_REQUEST = ['_wpnonce' => wp_create_nonce(AdminPage::NONCE), 'network' => '1'];

        $this->expectException(WpDieException::class);
        $page->handleRunGuard();
    }

    public function testPageNeedsCapability(): void
    {
        $page = $this->page();

        $this->expectException(WpDieException::class);
        $page->render();
    }

    public function testOutputIsEscaped(): void
    {
        $page = $this->page();
        WpState::$caps['manage_options'] = true;
        add_filter('jetpack_sync_quiet_status', static function (array $status): array {
            $status['<b>field</b>'] = '<script>alert(1)</script>';
            $status['warnings'][] = '<img src=x onerror=alert(1)>';

            return $status;
        });

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<b>field', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('name="_wpnonce" value="nonce-jetpack_sync_quiet_run_guard"', $html);
        self::assertStringContainsString('Run guard now', $html);
        self::assertStringContainsString('docs/MEASURING.md', $html);
    }

    public function testNetworkPageListsSites(): void
    {
        WpState::$multisite = true;
        WpState::$sites = [1, 2];
        $page = $this->page();
        WpState::$caps['manage_network_options'] = true;

        ob_start();
        $page->renderNetwork();
        $html = (string) ob_get_clean();

        self::assertSame(2, substr_count($html, '<td>active</td>'));
        self::assertStringContainsString('name="network" value="1"', $html);
    }

    public function testFailedNoticeStaysAppliedNoticeExpires(): void
    {
        $page = $this->page();
        WpState::$caps['manage_options'] = true;
        update_option(Guard::STATE_OPTION, ['notice' => ['time' => self::NOW, 'result' => Guard::FALLBACK_APPLIED, 'jetpack_version' => 'Jetpack <1>']]);

        ob_start();
        $page->renderNotice();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Jetpack &lt;1&gt;', $html);

        $this->env->time += Guard::APPLIED_NOTICE_SECONDS;
        ob_start();
        $page->renderNotice();
        self::assertSame('', ob_get_clean());

        update_option(Guard::STATE_OPTION, ['notice' => ['time' => self::NOW, 'result' => Guard::FALLBACK_FAILED, 'jetpack_version' => 'x']]);
        ob_start();
        $page->renderNotice();
        self::assertStringContainsString('notice-error', (string) ob_get_clean());
    }
}
