<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet;

/**
 * The switch. Short-circuits WordPress option reads for Jetpack's sync
 * settings with `pre_option_{$option}`, so Jetpack reads the value this
 * plugin wants while the stored value stays as it is.
 *
 * Nothing is written. Removing the filters (or the plugin) restores the
 * stored values at once.
 */
class OptionFilter
{
    /** Late, so this plugin's value wins over most other filters on the same option. */
    public const PRIORITY = 1000;

    /** Returned for dedicated_sync_enabled. A string, never false: false means "not short-circuited". */
    public const DEDICATED_VALUE = '0';

    public const SYNC_VIA_CRON_VALUE = '1';

    private bool $registered = false;

    public function __construct(
        private readonly Config $config,
        private readonly Tunables $tunables,
    ) {
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }
        add_filter($this->hook(JetpackSync::DEDICATED), [$this, 'filterDedicated'], self::PRIORITY, 1);
        add_filter($this->hook(JetpackSync::SYNC_VIA_CRON), [$this, 'filterSyncViaCron'], self::PRIORITY, 1);
        foreach ($this->tunableSettings() as $setting) {
            add_filter($this->hook($setting), [$this, 'filterTunable'], self::PRIORITY, 2);
        }
        $this->registered = true;
    }

    public function unregister(): void
    {
        remove_filter($this->hook(JetpackSync::DEDICATED), [$this, 'filterDedicated'], self::PRIORITY);
        remove_filter($this->hook(JetpackSync::SYNC_VIA_CRON), [$this, 'filterSyncViaCron'], self::PRIORITY);
        foreach ($this->tunableSettings() as $setting) {
            remove_filter($this->hook($setting), [$this, 'filterTunable'], self::PRIORITY);
        }
        $this->registered = false;
    }

    public function isRegistered(): bool
    {
        return $this->registered;
    }

    /**
     * Master switch, evaluated on every read so a late filter still counts.
     */
    public function isEnabled(): bool
    {
        /**
         * Filters whether the plugin acts on this request.
         *
         * @param bool $enabled Default true.
         */
        return (bool) apply_filters('jetpack_sync_quiet_enabled', true);
    }

    public function isCronForced(): bool
    {
        /**
         * Filters whether sync_via_cron is forced on.
         *
         * @param bool $force Default from JETPACK_SYNC_QUIET_FORCE_CRON, true when unset.
         */
        return (bool) apply_filters('jetpack_sync_quiet_force_cron', $this->config->forceCron);
    }

    /**
     * @param mixed $pre Value from earlier filters; false means "not short-circuited".
     * @return mixed
     */
    public function filterDedicated(mixed $pre): mixed
    {
        return $this->isEnabled() ? self::DEDICATED_VALUE : $pre;
    }

    /**
     * @param mixed $pre Value from earlier filters.
     * @return mixed
     */
    public function filterSyncViaCron(mixed $pre): mixed
    {
        return ($this->isEnabled() && $this->isCronForced()) ? self::SYNC_VIA_CRON_VALUE : $pre;
    }

    /**
     * @param mixed  $pre    Value from earlier filters.
     * @param string $option Full option name.
     * @return mixed
     */
    public function filterTunable(mixed $pre, string $option = ''): mixed
    {
        if (!$this->isEnabled() || !str_starts_with($option, JetpackSync::OPTION_PREFIX)) {
            return $pre;
        }
        $setting = substr($option, strlen(JetpackSync::OPTION_PREFIX));
        foreach (Tunables::DEFINITIONS as $key => $definition) {
            if ($definition[0] === $setting) {
                $value = $this->tunables->value($key);

                return $value === null ? $pre : (string) $value;
            }
        }

        return $pre;
    }

    /**
     * Read a stored Jetpack setting with this plugin's filter lifted for the
     * duration of the read. Returns false when the option is missing.
     */
    public function storedValue(string $setting): mixed
    {
        $hook = $this->hook($setting);
        $callback = match ($setting) {
            JetpackSync::DEDICATED => [$this, 'filterDedicated'],
            JetpackSync::SYNC_VIA_CRON => [$this, 'filterSyncViaCron'],
            default => [$this, 'filterTunable'],
        };
        $had = has_filter($hook, $callback) !== false;
        if ($had) {
            remove_filter($hook, $callback, self::PRIORITY);
        }
        try {
            return get_option(JetpackSync::optionName($setting));
        } finally {
            if ($had) {
                add_filter($hook, $callback, self::PRIORITY, $callback[1] === 'filterTunable' ? 2 : 1);
            }
        }
    }

    private function hook(string $setting): string
    {
        return 'pre_option_' . JetpackSync::optionName($setting);
    }

    /**
     * @return list<string>
     */
    private function tunableSettings(): array
    {
        $settings = [];
        foreach (Tunables::DEFINITIONS as $definition) {
            if ($definition[0] !== null) {
                $settings[] = $definition[0];
            }
        }

        return $settings;
    }
}
