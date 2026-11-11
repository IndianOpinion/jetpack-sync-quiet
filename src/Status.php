<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet;

/**
 * Builds the status report shown by WP-CLI and the admin page.
 *
 * Reads only: Jetpack's public status method, Jetpack's settings reader,
 * WordPress cron data, constants and php.ini. Never writes, never makes a
 * remote request, never shows tokens or queue contents.
 */
class Status
{
    /** Seconds of headroom wanted between cron_sync_time_limit and max_execution_time. */
    public const TIME_LIMIT_MARGIN = 10;

    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * @return array<string, mixed> Field => value; `tunables` is a map and `warnings` a list.
     */
    public function collect(): array
    {
        $plugin = $this->plugin;
        $jetpack = $plugin->jetpack();
        $env = $plugin->environment();
        $guard = $plugin->guard();
        $switch = $plugin->optionFilter();
        $now = $env->now();
        $warnings = [];

        $present = $jetpack->isPresent();
        $state = $guard->state();

        $status = [
            'plugin'          => $plugin->stateDescription(),
            'plugin_version'  => Plugin::VERSION,
            'install_type'    => $plugin->installType(),
            'jetpack_sync'    => $present ? 'present' : 'not found',
            'jetpack_version' => $present ? $guard->jetpackVersion() : 'n/a',
        ];

        if (!$present) {
            $status['note'] = 'Jetpack Sync not found; doing nothing';

            return $this->finish($status, $warnings);
        }

        $inactive = $guard->inactiveReason();
        $status['connected'] = Format::yesNo($jetpack->isConnected());
        $syncEnabled = $jetpack->isSyncEnabled();
        $status['sync'] = match ($syncEnabled) {
            null => 'unknown',
            true => 'enabled',
            false => 'disabled by Jetpack setting',
        };
        if ($inactive !== null) {
            $status['sync'] = $inactive;
        }

        // Dedicated sync.
        $stored = $switch->storedValue(JetpackSync::DEDICATED);
        $status['dedicated_sync_stored'] = Format::raw($stored);
        if (!$jetpack->hasDedicatedSetting()) {
            $status['dedicated_sync_effective'] = 'not present in this Jetpack version';
            $status['dedicated_sync_match'] = 'n/a';
            $status['validation_loopback'] = 'not present in this Jetpack version';
        } else {
            $effective = $jetpack->isDedicatedEnabled();
            $status['dedicated_sync_effective'] = $effective === null ? 'unknown' : ($effective ? 'on' : 'off');
            $storedOn = $stored !== false && $stored !== null && $stored !== '' && $stored !== '0' && (bool) $stored;
            if ($effective === null) {
                $status['dedicated_sync_match'] = 'unknown';
            } elseif ($effective === $storedOn) {
                $status['dedicated_sync_match'] = 'match';
            } else {
                $status['dedicated_sync_match'] = sprintf(
                    'drift (stored %s, effective %s)',
                    $storedOn ? 'on' : 'off',
                    $effective ? 'on' : 'off'
                );
            }
            $status['validation_loopback'] = $effective === false
                ? 'unreachable: dedicated sync is off, so Jetpack never runs its blocking 30 s validation request'
                : 'reachable';
            if ($effective === true && $switch->isRegistered() && $switch->isEnabled()) {
                $warnings[] = 'Jetpack reads dedicated sync on although this plugin filters it: the filter is not '
                    . 'reaching Jetpack. Run `wp jetpack-sync-quiet guard run` and report your Jetpack version.';
            }
        }

        $drift = $state['last_drift'];
        $status['last_drift'] = $drift === null
            ? 'none'
            : sprintf('%s: stored %s (%s)', Format::time($drift['time']), $drift['stored'], $drift['source']);
        $status['last_guard_run'] = $state['last_check'] === 0
            ? 'never'
            : sprintf('%s (%s): %s', Format::time($state['last_check']), $state['last_trigger'], $state['last_result']);
        $fallback = $state['last_fallback'];
        $status['last_fallback'] = $fallback === null
            ? 'none'
            : sprintf(
                '%s: %s on %s%s',
                Format::time($fallback['time']),
                $fallback['result'],
                $fallback['jetpack_version'],
                $fallback['detail'] === '' ? '' : ' (' . $fallback['detail'] . ')'
            );
        $notice = $state['notice'];
        if ($notice !== null && $notice['result'] === Guard::FALLBACK_FAILED) {
            $warnings[] = sprintf(
                'Guard fallback write failed on %s. Jetpack may be sending loopbacks again.',
                $notice['jetpack_version']
            );
        } elseif (
            $notice !== null
            && $notice['result'] === Guard::FALLBACK_APPLIED
            && $now - $notice['time'] < Guard::APPLIED_NOTICE_SECONDS
        ) {
            $warnings[] = sprintf(
                'Guard fallback write applied on %s: the filter stopped reaching Jetpack, '
                . 'so 0 was written through Jetpack\'s settings API.',
                $notice['jetpack_version']
            );
        }
        if (
            $fallback !== null
            && $fallback['result'] === Guard::FALLBACK_DISABLED
            && $fallback['day'] === gmdate('Y-m-d', $now)
        ) {
            $warnings[] = 'The filter is not reaching Jetpack and the guard fallback write is switched off.';
        }

        // sync_via_cron.
        $viaCron = $jetpack->setting(JetpackSync::SYNC_VIA_CRON);
        $forced = $switch->isRegistered() && $switch->isEnabled() && $switch->isCronForced();
        $status['sync_via_cron_effective'] = $viaCron === null ? 'unknown' : Format::raw($viaCron);
        $status['sync_via_cron_stored'] = Format::raw($switch->storedValue(JetpackSync::SYNC_VIA_CRON));
        $status['sync_via_cron_forced'] = $forced ? 'yes' : 'no';
        $cronOn = $viaCron === null ? null : (bool) $viaCron;
        if ($cronOn === false) {
            $warnings[] = 'sync_via_cron is off: sync only runs at the end of POST, admin and WP-CLI requests, '
                . 'which can stall on a site written to from outside WordPress.';
        }

        // Queue.
        $this->queue($status, $now);

        // Cron.
        $this->cron($status, $warnings, $now, $cronOn, $inactive === null);

        // Time limit.
        $limit = $jetpack->setting('cron_sync_time_limit');
        $limit = is_numeric($limit) ? (int) $limit : null;
        $maxExec = (int) $env->iniGet('max_execution_time');
        $status['cron_sync_time_limit'] = $limit === null ? 'unknown' : $limit . ' s';
        $status['max_execution_time'] = $maxExec === 0
            ? '0 (no limit in this process; web requests may have one)'
            : $maxExec . ' s';
        if ($limit !== null && $maxExec > 0 && $limit > $maxExec - self::TIME_LIMIT_MARGIN) {
            $warnings[] = sprintf(
                'cron_sync_time_limit (%d s) is not at least %d s below max_execution_time (%d s): cron sync '
                . 'over HTTP may be killed mid-batch. Lower cron_sync_time_limit or raise max_execution_time.',
                $limit,
                self::TIME_LIMIT_MARGIN,
                $maxExec
            );
        }

        // Tunables.
        $resolved = $plugin->tunables()->resolve();
        $tunables = [];
        foreach ($plugin->tunables()->keys() as $key) {
            if (isset($resolved['values'][$key])) {
                $tunables[$key] = sprintf('%d (%s)', $resolved['values'][$key], $resolved['sources'][$key]);
            } else {
                $default = $plugin->tunables()->jetpackDefault($key);
                $tunables[$key] = sprintf('%d (%s)', $default, Tunables::SOURCE_JETPACK);
            }
        }
        $status['tunables'] = $tunables;
        foreach ($resolved['invalid'] as $message) {
            $warnings[] = 'Ignored setting: ' . $message;
        }

        return $this->finish($status, $warnings);
    }

    /**
     * @param array<string, mixed> $status
     */
    private function queue(array &$status, int $now): void
    {
        $jetpack = $this->plugin->jetpack();
        $sync = $jetpack->syncStatus();
        $size = null;
        $lag = null;
        $next = null;
        $last = null;
        if ($sync !== null) {
            $size = self::number($sync['queue_size'] ?? null);
            $lag = self::number($sync['queue_lag'] ?? null);
            $next = self::number($sync['queue_next_sync'] ?? null);
            $details = is_array($sync['debug_details'] ?? null) ? $sync['debug_details'] : [];
            // Jetpack spells the key "last_succesful_sync"; accept the correct spelling too.
            foreach (['last_succesful_sync', 'last_successful_sync'] as $key) {
                if (isset($details[$key]) && is_numeric($details[$key]) && (float) $details[$key] > 0) {
                    $last = (int) $details[$key];
                    break;
                }
            }
        }
        if ($size === null || $lag === null) {
            $stats = $jetpack->queueStats();
            if ($stats !== null) {
                $size ??= $stats['size'];
                $lag ??= (int) round($stats['lag']);
            }
        }

        $status['queue_size'] = $size === null ? 'unknown' : $size;
        $status['queue_lag'] = $lag === null ? 'unknown' : sprintf('%d s (%s)', $lag, Format::duration($lag));
        if ($last !== null) {
            $status['last_successful_sync'] = sprintf(
                '%s (%s ago, from Jetpack status)',
                Format::time($last),
                Format::duration($now - $last)
            );
        } elseif ($lag !== null && $lag > 0) {
            $status['last_successful_sync'] = sprintf(
                'no later than %s (estimated from queue lag)',
                Format::time($now - $lag)
            );
        } else {
            $status['last_successful_sync'] = 'unknown';
        }
        $status['next_sync'] = $next === null
            ? 'unknown'
            : ($next <= 0 ? 'now (not throttled)' : sprintf('in %s', Format::duration($next)));
    }

    /**
     * @param array<string, mixed> $status
     * @param list<string>         $warnings
     */
    private function cron(array &$status, array &$warnings, int $now, ?bool $cronOn, bool $syncActive): void
    {
        $env = $this->plugin->environment();
        $disabled = (bool) $env->constant('DISABLE_WP_CRON');
        $status['disable_wp_cron'] = $disabled ? 'true' : 'false';

        $cron = _get_cron_array();
        $oldest = is_array($cron) && $cron !== [] ? (int) min(array_keys($cron)) : null;
        $status['oldest_due_cron_event'] = ($oldest === null || $oldest > $now)
            ? 'none due'
            : sprintf('due %s ago', Format::duration($now - $oldest));

        $next = wp_next_scheduled(JetpackSync::CRON_HOOK);
        $schedule = wp_get_schedule(JetpackSync::CRON_HOOK);
        $schedules = wp_get_schedules();
        $interval = (is_string($schedule) && isset($schedules[$schedule]['interval']))
            ? (int) $schedules[$schedule]['interval']
            : JetpackSync::DEFAULT_INTERVAL;
        $status['sync_cron_schedule'] = is_string($schedule)
            ? sprintf('%s (%d s)', $schedule, $interval)
            : 'none';
        $status['sync_cron_next'] = $next === false
            ? 'not scheduled'
            : ($next >= $now ? 'in ' . Format::duration($next - $now) : 'overdue by ' . Format::duration($now - $next));

        if ($cronOn === false) {
            $status['sync_cron_health'] = 'not used (sync_via_cron is off)';

            return;
        }
        if ($next === false) {
            $status['sync_cron_health'] = 'not scheduled';
            if ($syncActive && $cronOn === true) {
                $warnings[] = 'jetpack_sync_cron is not scheduled. Jetpack schedules it on init; '
                    . 'load any page or run `wp cron event list` to check.';
            }

            return;
        }

        // It ran within twice its interval exactly when the next run is no more than one interval late.
        $overdue = $next < $now - $interval;
        $status['sync_cron_health'] = $overdue ? 'overdue' : 'ok';
        if ($disabled) {
            $status['system_cron'] = $overdue ? 'not running' : 'ok';
        }
        if ($overdue) {
            $warnings[] = 'WP-Cron is not running; sync only happens on POST, admin and CLI requests. ' . ($disabled
                ? 'DISABLE_WP_CRON is true: check the system cron that should call wp-cron.php '
                    . 'or `wp cron event run --due-now`.'
                : 'WP-Cron here depends on visitor traffic; consider a system cron.');
        }
    }

    /**
     * @param array<string, mixed> $status
     * @param list<string>         $warnings
     * @return array<string, mixed>
     */
    private function finish(array $status, array $warnings): array
    {
        $status['warnings'] = $warnings;

        /**
         * Filters the status fields before display.
         *
         * @param array<string, mixed> $status
         */
        $filtered = apply_filters('jetpack_sync_quiet_status', $status);

        return is_array($filtered) ? $filtered : $status;
    }

    private static function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    /**
     * Flatten a status array into field/value rows for tables.
     *
     * @param array<string, mixed> $status
     * @return list<array{field: string, value: string}>
     */
    public static function rows(array $status): array
    {
        $rows = [];
        foreach ($status as $field => $value) {
            if (is_array($value)) {
                if ($value === []) {
                    $rows[] = ['field' => (string) $field, 'value' => 'none'];
                    continue;
                }
                foreach ($value as $key => $item) {
                    $label = is_int($key) ? sprintf('%s[%d]', $field, $key) : sprintf('%s.%s', $field, $key);
                    $rows[] = ['field' => $label, 'value' => Format::raw($item)];
                }
                continue;
            }
            $rows[] = ['field' => (string) $field, 'value' => Format::raw($value)];
        }

        return $rows;
    }
}
