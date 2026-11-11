<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet;

/**
 * Optional cron intervals for Jetpack's incremental and full sync events.
 *
 * Jetpack asks for a schedule name through `jetpack_sync_incremental_sync_interval`
 * and `jetpack_sync_full_sync_interval`, and falls back to its own five-minute
 * schedule when the name is not registered. This class registers a schedule
 * of the operator's length under its own name and returns that name, only
 * when the operator set an interval.
 */
class CronSchedules
{
    public const INCREMENTAL = 'jetpack_sync_quiet_incremental';
    public const FULL = 'jetpack_sync_quiet_full';

    private const MAP = [
        self::INCREMENTAL => 'cron_interval',
        self::FULL        => 'full_sync_interval',
    ];

    public function __construct(
        private readonly Tunables $tunables,
        private readonly OptionFilter $switch,
    ) {
    }

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'addSchedules']);
        add_filter('jetpack_sync_incremental_sync_interval', [$this, 'incrementalSchedule'], OptionFilter::PRIORITY);
        add_filter('jetpack_sync_full_sync_interval', [$this, 'fullSchedule'], OptionFilter::PRIORITY);
    }

    public function unregister(): void
    {
        remove_filter('cron_schedules', [$this, 'addSchedules']);
        remove_filter('jetpack_sync_incremental_sync_interval', [$this, 'incrementalSchedule'], OptionFilter::PRIORITY);
        remove_filter('jetpack_sync_full_sync_interval', [$this, 'fullSchedule'], OptionFilter::PRIORITY);
    }

    /**
     * @param mixed $schedules Schedules from core and other plugins.
     * @return mixed
     */
    public function addSchedules(mixed $schedules): mixed
    {
        if (!is_array($schedules)) {
            return $schedules;
        }
        foreach (self::MAP as $name => $key) {
            $seconds = $this->tunables->value($key);
            if ($seconds !== null) {
                $schedules[$name] = [
                    'interval' => $seconds,
                    'display'  => sprintf('Every %d seconds (Jetpack Sync Quiet)', $seconds),
                ];
            }
        }

        return $schedules;
    }

    /**
     * @param mixed $name Schedule name Jetpack would use.
     * @return mixed
     */
    public function incrementalSchedule(mixed $name): mixed
    {
        return $this->schedule(self::INCREMENTAL, $name);
    }

    /**
     * @param mixed $name Schedule name Jetpack would use.
     * @return mixed
     */
    public function fullSchedule(mixed $name): mixed
    {
        return $this->schedule(self::FULL, $name);
    }

    private function schedule(string $ours, mixed $name): mixed
    {
        if (!$this->switch->isEnabled() || $this->tunables->value(self::MAP[$ours]) === null) {
            return $name;
        }
        $schedules = wp_get_schedules();

        return isset($schedules[$ours]) ? $ours : $name;
    }
}
