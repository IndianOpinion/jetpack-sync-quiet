<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Admin;

use IndianOpinion\JetpackSyncQuiet\Format;
use IndianOpinion\JetpackSyncQuiet\Guard;
use IndianOpinion\JetpackSyncQuiet\Plugin;
use IndianOpinion\JetpackSyncQuiet\Status;

/**
 * Read-only status page under Tools (and Network Admin, Settings on
 * multisite), one "Run guard now" button, and the fallback notice.
 */
class AdminPage
{
    public const SLUG = 'jetpack-sync-quiet';
    public const ACTION = 'jetpack_sync_quiet_run_guard';
    public const NONCE = 'jetpack_sync_quiet_run_guard';
    public const MEASURING_URL = 'https://github.com/IndianOpinion/jetpack-sync-quiet/blob/main/docs/MEASURING.md';

    /** Sites listed on the network page. */
    public const NETWORK_SITES = 200;

    /** Set false in tests so the redirect does not end the process. */
    public bool $exitAfterRedirect = true;

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('network_admin_menu', [$this, 'addNetworkMenu']);
        add_action('admin_post_' . self::ACTION, [$this, 'handleRunGuard']);
        add_action('admin_notices', [$this, 'renderNotice']);
        add_action('network_admin_notices', [$this, 'renderNotice']);
    }

    public function addMenu(): void
    {
        add_management_page(
            'Jetpack Sync Quiet',
            'Jetpack Sync Quiet',
            'manage_options',
            self::SLUG,
            [$this, 'render']
        );
    }

    public function addNetworkMenu(): void
    {
        add_submenu_page(
            'settings.php',
            'Jetpack Sync Quiet',
            'Jetpack Sync Quiet',
            'manage_network_options',
            self::SLUG,
            [$this, 'renderNetwork']
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html('You do not have permission to view this page.'), '', ['response' => 403]);
        }
        $status = $this->plugin->status()->collect();

        echo '<div class="wrap"><h1>' . esc_html('Jetpack Sync Quiet') . '</h1>';
        $this->renderResult();
        $this->renderWarnings($status);
        echo '<table class="widefat striped"><tbody>';
        foreach (Status::rows($status) as $row) {
            if (str_starts_with($row['field'], 'warnings')) {
                continue;
            }
            echo '<tr><th scope="row">' . esc_html($row['field']) . '</th>'
                . '<td>' . esc_html($row['value']) . '</td></tr>';
        }
        echo '</tbody></table>';
        $this->renderForm(false);
        echo '</div>';
    }

    public function renderNetwork(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html('You do not have permission to view this page.'), '', ['response' => 403]);
        }
        echo '<div class="wrap"><h1>' . esc_html('Jetpack Sync Quiet: network') . '</h1>';
        $this->renderResult();
        echo '<table class="widefat striped"><thead><tr>';
        foreach (['Site', 'Plugin', 'Dedicated sync', 'Queue size', 'Sync cron', 'Warnings'] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($this->siteIds() as $id) {
            switch_to_blog($id);
            $status = $this->plugin->status()->collect();
            $warnings = is_array($status['warnings'] ?? null) ? count($status['warnings']) : 0;
            echo '<tr><td>' . esc_html((string) $id) . '</td>'
                . '<td>' . esc_html(Format::raw($status['plugin'] ?? '')) . '</td>'
                . '<td>' . esc_html(Format::raw($status['dedicated_sync_effective'] ?? 'n/a')) . '</td>'
                . '<td>' . esc_html(Format::raw($status['queue_size'] ?? 'n/a')) . '</td>'
                . '<td>' . esc_html(Format::raw($status['sync_cron_health'] ?? 'n/a')) . '</td>'
                . '<td>' . esc_html((string) $warnings) . '</td></tr>';
            restore_current_blog();
        }
        echo '</tbody></table>';
        echo '<p>' . esc_html('Per-site detail: wp jetpack-sync-quiet status --url=<site>') . '</p>';
        $this->renderForm(true);
        echo '</div>';
    }

    /**
     * `admin_post_{action}` handler. Capability first, then nonce.
     */
    public function handleRunGuard(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- the nonce is checked below, after the capability.
        $network = isset($_POST['network']) && $_POST['network'] === '1' && is_multisite();
        $capability = $network ? 'manage_network_options' : 'manage_options';
        if (!current_user_can($capability)) {
            wp_die(esc_html('You do not have permission to do that.'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE);

        if ($network) {
            $results = [];
            foreach ($this->siteIds() as $id) {
                switch_to_blog($id);
                $results[] = $id . ': ' . $this->plugin->guard()->run('manual');
                restore_current_blog();
            }
            $result = implode(' | ', $results);
            $base = network_admin_url('settings.php');
        } else {
            $result = $this->plugin->guard()->run('manual');
            $base = admin_url('tools.php');
        }

        $url = add_query_arg(['page' => self::SLUG, 'jsq_result' => rawurlencode(substr($result, 0, 500))], $base);
        wp_safe_redirect($url);
        if ($this->exitAfterRedirect) {
            exit;
        }
    }

    /**
     * Notice only when the guard's fallback write was applied (for a week) or failed (until fixed).
     */
    public function renderNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $notice = $this->plugin->guard()->state()['notice'];
        if ($notice === null) {
            return;
        }
        $now = $this->plugin->environment()->now();
        if ($notice['result'] === Guard::FALLBACK_APPLIED && $now - $notice['time'] >= Guard::APPLIED_NOTICE_SECONDS) {
            return;
        }
        $message = $notice['result'] === Guard::FALLBACK_FAILED
            ? 'Jetpack Sync Quiet: the filter no longer reaches Jetpack (%s) and the fallback write failed. '
                . 'Jetpack may be sending loopback requests again.'
            : 'Jetpack Sync Quiet: the filter no longer reaches Jetpack (%s), so dedicated sync was set to 0 '
                . 'through Jetpack\'s settings. Please report your Jetpack version.';
        $message = sprintf($message, $notice['jetpack_version']);
        $class = $notice['result'] === Guard::FALLBACK_FAILED ? 'notice-error' : 'notice-warning';

        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($message)
            . ' <a href="' . esc_url(admin_url('tools.php?page=' . self::SLUG)) . '">'
            . esc_html('Status') . '</a></p></div>';
    }

    private function renderResult(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- display only, escaped.
        if (!isset($_GET['jsq_result']) || !is_string($_GET['jsq_result'])) {
            return;
        }
        $text = sanitize_text_field(rawurldecode(wp_unslash($_GET['jsq_result'])));
        echo '<div class="notice notice-info"><p>' . esc_html('Guard result: ' . $text) . '</p></div>';
    }

    /**
     * @param array<string, mixed> $status
     */
    private function renderWarnings(array $status): void
    {
        $warnings = is_array($status['warnings'] ?? null) ? $status['warnings'] : [];
        foreach ($warnings as $warning) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html(Format::raw($warning)) . '</p></div>';
        }
    }

    private function renderForm(bool $network): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        if ($network) {
            echo '<input type="hidden" name="network" value="1">';
        }
        wp_nonce_field(self::NONCE);
        echo '<p><button type="submit" class="button button-primary">' . esc_html('Run guard now') . '</button> ';
        echo '<a href="' . esc_url(self::MEASURING_URL) . '">' . esc_html('How to measure the effect') . '</a></p>';
        echo '</form>';
    }

    /**
     * @return list<int>
     */
    private function siteIds(): array
    {
        $ids = get_sites(['fields' => 'ids', 'number' => self::NETWORK_SITES]);

        return array_values(array_map('intval', $ids));
    }
}
