<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Cli;

use IndianOpinion\JetpackSyncQuiet\Exception\InvalidTunableException;
use IndianOpinion\JetpackSyncQuiet\Format;
use IndianOpinion\JetpackSyncQuiet\Installer;
use IndianOpinion\JetpackSyncQuiet\Plugin;
use IndianOpinion\JetpackSyncQuiet\Status;
use IndianOpinion\JetpackSyncQuiet\Tunables;
use WP_CLI;

/**
 * Inspect and tune Jetpack Sync Quiet.
 *
 * ## EXAMPLES
 *
 *     wp jetpack-sync-quiet status
 *     wp jetpack-sync-quiet status --strict
 *     wp jetpack-sync-quiet settings set max_queue_size 10000
 *     wp jetpack-sync-quiet guard run
 */
class Command
{
    private const FORMATS = ['table', 'json', 'yaml'];

    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * Show what Jetpack reads, the queue, cron health and warnings.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     *
     * [--strict]
     * : Exit with status 1 when any warning is present.
     *
     * [--network]
     * : On multisite, list every site in one table.
     *
     * @param list<string>          $args
     * @param array<string, string|bool> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $format = $this->format($assoc);
        $strict = !empty($assoc['strict']);

        if (!empty($assoc['network'])) {
            $this->networkStatus($format, $strict);

            return;
        }

        $status = $this->plugin->status()->collect();
        if ($format === 'json') {
            WP_CLI::line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            WP_CLI\Utils\format_items($format, Status::rows($status), ['field', 'value']);
        }

        $warnings = is_array($status['warnings'] ?? null) ? $status['warnings'] : [];
        $this->finish($warnings, $strict, $format);
    }

    /**
     * Read and write tunables, with range validation.
     *
     * ## OPTIONS
     *
     * <action>
     * : get, set, unset or list.
     *
     * [<key>]
     * : Setting key, for example max_queue_size.
     *
     * [<value>]
     * : Whole number, for set.
     *
     * [--network]
     * : Use the network-wide value (multisite). A site value still wins.
     *
     * ## EXAMPLES
     *
     *     wp jetpack-sync-quiet settings list
     *     wp jetpack-sync-quiet settings set cron_interval 150
     *     wp jetpack-sync-quiet settings unset cron_interval
     *
     * @param list<string>          $args
     * @param array<string, string|bool> $assoc
     */
    public function settings(array $args, array $assoc): void
    {
        $action = $args[0] ?? 'list';
        $key = $args[1] ?? '';
        $network = !empty($assoc['network']);
        $tunables = $this->plugin->tunables();

        try {
            switch ($action) {
                case 'list':
                    $this->listSettings();
                    return;
                case 'get':
                    if ($key === '') {
                        $this->listSettings();
                        return;
                    }
                    $this->getSetting($key);
                    return;
                case 'set':
                    if ($key === '' || !isset($args[2])) {
                        WP_CLI::error('Usage: wp jetpack-sync-quiet settings set <key> <value> [--network]');
                    }
                    $value = $tunables->set($key, $args[2], $network);
                    WP_CLI::success(sprintf('%s set to %d at %s level.', $key, $value, $network ? 'network' : 'site'));
                    $this->warnIfOverridden($key, $network);
                    return;
                case 'unset':
                    if ($key === '') {
                        WP_CLI::error('Usage: wp jetpack-sync-quiet settings unset <key> [--network]');
                    }
                    $removed = $tunables->unset($key, $network);
                    WP_CLI::success(
                        $removed
                            ? sprintf('%s unset; Jetpack\'s value applies unless another level sets it.', $key)
                            : sprintf('%s was not set at that level.', $key)
                    );
                    return;
                default:
                    WP_CLI::error(sprintf('Unknown action "%s". Use get, set, unset or list.', $action));
            }
        } catch (InvalidTunableException $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Run the guard now and print the result.
     *
     * ## OPTIONS
     *
     * <action>
     * : Only "run".
     *
     * @param list<string>          $args
     * @param array<string, string|bool> $assoc
     */
    public function guard(array $args, array $assoc): void
    {
        if (($args[0] ?? '') !== 'run') {
            WP_CLI::error('Usage: wp jetpack-sync-quiet guard run');
        }
        $result = $this->plugin->guard()->run('manual');
        $fine = str_starts_with($result, 'ok')
            || str_starts_with($result, 'Jetpack Sync not found')
            || str_contains($result, 'nothing to do');
        if ($fine) {
            WP_CLI::success($result);
        } else {
            WP_CLI::warning($result);
        }
    }

    /**
     * Delete everything this plugin stored. For must-use installs, which have no uninstall hook.
     *
     * Jetpack's own settings are not touched. Once the plugin files are gone,
     * the stored dedicated_sync_enabled value governs Jetpack again.
     *
     * ## OPTIONS
     *
     * [--network]
     * : On multisite, purge every site and the network settings.
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @param list<string>          $args
     * @param array<string, string|bool> $assoc
     */
    public function purge(array $args, array $assoc): void
    {
        $network = !empty($assoc['network']);
        WP_CLI::confirm(
            $network
                ? 'Delete Jetpack Sync Quiet settings and guard state on every site?'
                : 'Delete Jetpack Sync Quiet settings and guard state on this site?',
            $assoc
        );
        Installer::purge($network);
        $this->plugin->tunables()->reset();
        WP_CLI::success(
            'Purged. Jetpack\'s stored dedicated_sync_enabled governs Jetpack again '
            . 'once this plugin\'s files are removed.'
        );
    }

    private function networkStatus(string $format, bool $strict): void
    {
        if (!is_multisite()) {
            WP_CLI::error('--network needs a multisite install.');
        }
        $ids = get_sites(['fields' => 'ids', 'number' => Installer::MAX_SITES]);
        $rows = [];
        $full = [];
        $warnings = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            switch_to_blog($id);
            $status = $this->plugin->status()->collect();
            restore_current_blog();
            $siteWarnings = is_array($status['warnings'] ?? null) ? $status['warnings'] : [];
            foreach ($siteWarnings as $warning) {
                $warnings[] = sprintf('site %d: %s', $id, Format::raw($warning));
            }
            $full[(string) $id] = $status;
            $rows[] = [
                'site'           => (string) $id,
                'plugin'         => Format::raw($status['plugin'] ?? ''),
                'dedicated_sync' => Format::raw($status['dedicated_sync_effective'] ?? 'n/a'),
                'queue_size'     => Format::raw($status['queue_size'] ?? 'n/a'),
                'sync_cron'      => Format::raw($status['sync_cron_health'] ?? 'n/a'),
                'warnings'       => (string) count($siteWarnings),
            ];
        }
        if ($format === 'json') {
            WP_CLI::line((string) json_encode($full, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            WP_CLI\Utils\format_items(
                $format,
                $rows,
                ['site', 'plugin', 'dedicated_sync', 'queue_size', 'sync_cron', 'warnings']
            );
        }
        $this->finish($warnings, $strict, $format);
    }

    /**
     * @param array<mixed> $warnings
     */
    private function finish(array $warnings, bool $strict, string $format): void
    {
        if ($format === 'table') {
            foreach ($warnings as $warning) {
                WP_CLI::warning(Format::raw($warning));
            }
        }
        if ($strict && $warnings !== []) {
            WP_CLI::halt(1);
        }
    }

    private function listSettings(): void
    {
        $tunables = $this->plugin->tunables();
        $resolved = $tunables->resolve();
        $rows = [];
        foreach ($tunables->keys() as $key) {
            [$min, $max] = $tunables->range($key);
            $rows[] = [
                'key'     => $key,
                'value'   => isset($resolved['values'][$key]) ? (string) $resolved['values'][$key] : '',
                'source'  => $resolved['sources'][$key] ?? Tunables::SOURCE_JETPACK,
                'jetpack' => (string) $tunables->jetpackDefault($key),
                'range'   => sprintf('%d to %d', $min, $max),
                'unit'    => $tunables->unit($key),
            ];
        }
        WP_CLI\Utils\format_items('table', $rows, ['key', 'value', 'source', 'jetpack', 'range', 'unit']);
        foreach ($resolved['invalid'] as $message) {
            WP_CLI::warning('Ignored: ' . $message);
        }
    }

    private function getSetting(string $key): void
    {
        $tunables = $this->plugin->tunables();
        if (!$tunables->isKnown($key)) {
            throw new InvalidTunableException(
                sprintf('Unknown setting "%s". Known settings: %s.', $key, implode(', ', $tunables->keys()))
            );
        }
        $resolved = $tunables->resolve();
        if (isset($resolved['values'][$key])) {
            WP_CLI::line(sprintf('%d (%s)', $resolved['values'][$key], $resolved['sources'][$key]));

            return;
        }
        WP_CLI::line(sprintf('unset (Jetpack default %d)', $tunables->jetpackDefault($key)));
    }

    private function warnIfOverridden(string $key, bool $network): void
    {
        $resolved = $this->plugin->tunables()->resolve();
        $source = $resolved['sources'][$key] ?? null;
        $wanted = $network ? Tunables::SOURCE_NETWORK : Tunables::SOURCE_SITE;
        if ($source !== null && $source !== $wanted) {
            WP_CLI::warning(sprintf('%s is overridden by the %s value.', $key, $source));
        }
    }

    /**
     * @param array<string, string|bool> $assoc
     */
    private function format(array $assoc): string
    {
        $format = isset($assoc['format']) && is_string($assoc['format']) ? $assoc['format'] : 'table';
        if (!in_array($format, self::FORMATS, true)) {
            WP_CLI::error(sprintf('Unknown format "%s". Use table, json or yaml.', $format));
        }

        return $format;
    }
}
