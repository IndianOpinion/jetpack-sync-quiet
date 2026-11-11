<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet;

use IndianOpinion\JetpackSyncQuiet\Exception\JetpackUnavailableException;

/**
 * Watches for Jetpack changing the dedicated sync setting under the plugin.
 *
 * Two kinds of event:
 *
 * - Drift: the stored value says on while the effective value is off. This
 *   is normal (WordPress.com turns it on with a response header). It is
 *   recorded and announced once, and never fought.
 * - Filter failure: Jetpack's own reader says on while the filter is active.
 *   That means the filter no longer reaches Jetpack (for example the option
 *   was renamed). The guard then writes 0 once through Jetpack's settings
 *   API, checks the result, and does not try again the same UTC day.
 */
class Guard
{
    public const STATE_OPTION = 'jetpack_sync_quiet_guard';
    public const HOOK = 'jetpack_sync_quiet_guard';
    public const UPGRADE_HOOK = 'jetpack_sync_quiet_guard_after_upgrade';

    public const FALLBACK_APPLIED = 'applied';
    public const FALLBACK_FAILED = 'failed';
    public const FALLBACK_DISABLED = 'disabled';

    public const RESULT_OK = 'ok: Jetpack reads dedicated sync off';
    public const RESULT_NOT_FOUND = 'Jetpack Sync not found; doing nothing';
    public const RESULT_NOT_PRESENT = 'dedicated sync not present in this Jetpack version; nothing to do';
    public const RESULT_PLUGIN_OFF = 'plugin is switched off; nothing to do';
    public const RESULT_UNKNOWN = 'unknown: could not read the setting through Jetpack';

    /** A repeat of the same drift within this window is not a new event. */
    public const DRIFT_REPEAT_SECONDS = 86400;

    /** How long a notice about a successful fallback stays up. */
    public const APPLIED_NOTICE_SECONDS = 604800;

    public function __construct(
        private readonly JetpackSync $jetpack,
        private readonly OptionFilter $switch,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly Environment $env,
    ) {
    }

    /**
     * Guard state with every key present.
     *
     * @return array{
     *     last_check: int,
     *     last_trigger: string,
     *     last_result: string,
     *     jetpack_version: string,
     *     last_drift: array{time: int, stored: string, source: string}|null,
     *     last_fallback: array{time: int, day: string, result: string, jetpack_version: string, detail: string}|null,
     *     notice: array{time: int, result: string, jetpack_version: string}|null,
     *     invalid_signature: string
     * }
     */
    public function state(): array
    {
        $defaults = [
            'last_check'        => 0,
            'last_trigger'      => '',
            'last_result'       => '',
            'jetpack_version'   => '',
            'last_drift'        => null,
            'last_fallback'     => null,
            'notice'            => null,
            'invalid_signature' => '',
        ];
        $stored = get_option(self::STATE_OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return array_merge($defaults, array_intersect_key($stored, $defaults));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save(array $state): void
    {
        update_option(self::STATE_OPTION, $state, false);
    }

    /**
     * `add_option_{$option}` handler: Jetpack (or anyone) created the stored value.
     */
    public function onStoredAdded(string $option, mixed $value): void
    {
        $this->recordDrift($value, 'stored value changed');
    }

    /**
     * `update_option_{$option}` handler: Jetpack (or anyone) changed the stored value.
     */
    public function onStoredUpdated(mixed $oldValue, mixed $value): void
    {
        $this->recordDrift($value, 'stored value changed');
    }

    /**
     * Record a drift event when the stored value says on. Returns whether one was recorded.
     */
    public function recordDrift(mixed $stored, string $source): bool
    {
        if (!$this->switch->isRegistered() || !$this->switch->isEnabled() || !self::truthy($stored)) {
            return false;
        }
        $state = $this->state();
        $last = $state['last_drift'];
        // Once per event: the same stored value within a day is the same event.
        // Jetpack can write 1 then 0 on every sync send while WordPress.com asks for "on".
        $repeat = $last !== null
            && $last['stored'] === self::describe($stored)
            && $this->env->now() - $last['time'] < self::DRIFT_REPEAT_SECONDS;
        if ($repeat) {
            return false;
        }
        $state['last_drift'] = [
            'time'   => $this->env->now(),
            'stored' => self::describe($stored),
            'source' => $source,
        ];
        $this->save($state);
        $this->logger->warning(sprintf(
            'drift: Jetpack stored dedicated_sync_enabled = %s; effective value stays 0 (%s).',
            self::describe($stored),
            $source
        ));

        /**
         * Fires once per drift event.
         *
         * @param mixed  $stored    Stored value.
         * @param string $effective Effective value Jetpack reads.
         * @param string $source    What noticed the change.
         */
        do_action('jetpack_sync_quiet_drift_detected', $stored, OptionFilter::DEDICATED_VALUE, $source);

        return true;
    }

    /**
     * Run every check and return a one-line result.
     *
     * @param string $trigger What started the run (daily, upgrade, manual, after init).
     * @param bool   $quiet   When true, nothing is saved unless something happened.
     */
    public function run(string $trigger, bool $quiet = false): string
    {
        $state = $this->state();
        $version = $this->jetpackVersion();
        $changed = !$quiet;

        if (!$this->switch->isRegistered() || !$this->switch->isEnabled()) {
            $result = $this->jetpack->isPresent() ? self::RESULT_PLUGIN_OFF : self::RESULT_NOT_FOUND;
        } elseif (!$this->jetpack->isPresent()) {
            $result = self::RESULT_NOT_FOUND;
            $this->unschedule();
        } elseif (!$this->jetpack->hasDedicatedSetting()) {
            $result = self::RESULT_NOT_PRESENT;
        } else {
            $stored = $this->switch->storedValue(JetpackSync::DEDICATED);
            if ($this->recordDrift($stored, 'guard check (' . $trigger . ')')) {
                $state = $this->state();
                $changed = true;
            }

            $inactive = $this->inactiveReason();
            $effective = $this->jetpack->isDedicatedEnabled();
            if ($effective === null) {
                $result = self::RESULT_UNKNOWN;
            } elseif ($effective === false) {
                $result = $inactive === null ? self::RESULT_OK : $inactive . '; Jetpack reads dedicated sync off';
                if ($state['notice'] !== null && $state['notice']['result'] === self::FALLBACK_FAILED) {
                    $state['notice'] = null;
                    $changed = true;
                }
            } elseif ($inactive !== null) {
                $result = $inactive
                    . '; Jetpack reads dedicated sync on, but nothing is written while sync is inactive';
            } else {
                [$result, $state, $acted] = $this->fallback($state, $version);
                $changed = $changed || $acted;
            }
        }

        if ($changed) {
            $state['last_check'] = $this->env->now();
            $state['last_trigger'] = $trigger;
            $state['last_result'] = $result;
            $state['jetpack_version'] = $version;
            $this->save($state);
        }

        return $result;
    }

    /**
     * Per-request check after init (Jetpack's reads have all happened by then).
     * Costs one call into Jetpack's reader, which this plugin's filter answers.
     * Only does more when the reader says on, which means the filter has stopped working.
     */
    public function checkAfterInit(): void
    {
        if (!$this->switch->isRegistered() || !$this->switch->isEnabled()) {
            return;
        }
        if ($this->jetpack->isDedicatedEnabled() !== true) {
            return;
        }
        $this->run('after init', true);
    }

    /**
     * Why sync is inactive, or null when it is active or unknown.
     */
    public function inactiveReason(): ?string
    {
        if ($this->jetpack->isSyncEnabled() === false) {
            return 'sync disabled by Jetpack setting';
        }
        if ($this->jetpack->isConnected() === false) {
            return 'sync inactive: not connected';
        }

        return null;
    }

    public function fallbackAllowed(): bool
    {
        /**
         * Filters whether the guard may write dedicated_sync_enabled = 0
         * through Jetpack when the filter stops working.
         *
         * @param bool $allowed Default from JETPACK_SYNC_QUIET_GUARD_FALLBACK, true when unset.
         */
        return (bool) apply_filters('jetpack_sync_quiet_guard_fallback', $this->config->guardFallback);
    }

    /**
     * @param array<string, mixed> $state
     * @return array{0: string, 1: array<string, mixed>, 2: bool} Result, new state, whether anything happened.
     */
    private function fallback(array $state, string $version): array
    {
        $now = $this->env->now();
        $today = gmdate('Y-m-d', $now);
        $last = is_array($state['last_fallback'] ?? null) ? $state['last_fallback'] : null;

        if ($last !== null && ($last['day'] ?? '') === $today) {
            return [
                sprintf(
                    'filter not reaching Jetpack; fallback already tried today (%s); next try tomorrow (UTC)',
                    $last['result'] ?? ''
                ),
                $state,
                false,
            ];
        }

        if (!$this->fallbackAllowed()) {
            $state['last_fallback'] = [
                'time'            => $now,
                'day'             => $today,
                'result'          => self::FALLBACK_DISABLED,
                'jetpack_version' => $version,
                'detail'          => 'fallback write is switched off',
            ];
            $this->logger->warning(sprintf(
                'Jetpack reads dedicated sync on although this plugin filters it (%s). '
                . 'Fallback write is switched off; nothing written.',
                $version
            ));

            return ['filter not reaching Jetpack; fallback write switched off; warning only', $state, true];
        }

        $detail = '';
        try {
            $this->jetpack->disableDedicated();
        } catch (JetpackUnavailableException $e) {
            $detail = $e->getMessage();
        }
        $after = $this->jetpack->isDedicatedEnabled();
        $result = $after === false ? self::FALLBACK_APPLIED : self::FALLBACK_FAILED;
        if ($result === self::FALLBACK_FAILED && $detail === '') {
            $detail = 'Jetpack still reads dedicated sync on after the write';
        }

        $state['last_fallback'] = [
            'time'            => $now,
            'day'             => $today,
            'result'          => $result,
            'jetpack_version' => $version,
            'detail'          => $detail,
        ];
        $state['notice'] = ['time' => $now, 'result' => $result, 'jetpack_version' => $version];

        $this->logger->warning(sprintf(
            'filter not reaching Jetpack (%s); fallback write of dedicated_sync_enabled = 0 %s%s.',
            $version,
            $result,
            $detail === '' ? '' : ': ' . $detail
        ));

        /**
         * Fires after the guard's fallback write.
         *
         * @param string $version Jetpack version seen.
         * @param string $result  "applied" or "failed".
         */
        do_action('jetpack_sync_quiet_fallback_applied', $version, $result);

        return ['filter not reaching Jetpack; fallback ' . $result, $state, true];
    }

    /**
     * Log invalid tunables once per distinct set, not once per request.
     *
     * @param array<string, string> $invalid Key => message.
     */
    public function noteInvalidTunables(array $invalid): void
    {
        if ($invalid === []) {
            return;
        }
        ksort($invalid);
        $signature = md5((string) json_encode($invalid));
        $state = $this->state();
        if ($state['invalid_signature'] === $signature) {
            return;
        }
        foreach ($invalid as $message) {
            $this->logger->warning('ignored setting, Jetpack value used: ' . $message);
        }
        $state['invalid_signature'] = $signature;
        $this->save($state);
    }

    /**
     * Daily event while the sync package is present; none while it is absent.
     */
    public function maybeSchedule(): void
    {
        if (!$this->switch->isRegistered() || !$this->jetpack->isPresent()) {
            $this->unschedule();

            return;
        }
        if (wp_next_scheduled(self::HOOK) === false) {
            wp_schedule_event($this->env->now() + 3600, 'daily', self::HOOK);
        }
    }

    public function unschedule(): void
    {
        if (wp_next_scheduled(self::HOOK) !== false) {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    /**
     * `upgrader_process_complete` handler. The new code is not loaded yet in
     * this request, so the check runs a minute later on WP-Cron.
     */
    public function onUpgrade(): void
    {
        if (wp_next_scheduled(self::UPGRADE_HOOK) === false) {
            wp_schedule_single_event($this->env->now() + 60, self::UPGRADE_HOOK);
        }
    }

    public function runDaily(): void
    {
        $this->run('daily');
    }

    public function runAfterUpgrade(): void
    {
        $this->run('upgrade');
    }

    public function jetpackVersion(): string
    {
        $plugin = $this->env->constant('JETPACK__VERSION');
        $package = $this->jetpack->packageVersion();
        $parts = [];
        if (is_scalar($plugin) && (string) $plugin !== '') {
            $parts[] = 'Jetpack ' . $plugin;
        }
        if ($package !== null) {
            $parts[] = 'sync package ' . $package;
        }

        return $parts === [] ? 'unknown version' : implode(', ', $parts);
    }

    private static function truthy(mixed $value): bool
    {
        if (is_string($value)) {
            return $value !== '' && $value !== '0';
        }

        return (bool) $value;
    }

    private static function describe(mixed $value): string
    {
        if ($value === false || $value === null) {
            return 'missing';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return gettype($value);
    }
}
