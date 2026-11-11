<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet;

use IndianOpinion\JetpackSyncQuiet\Exception\InvalidTunableException;

/**
 * Operator tunables: definitions, ranges, storage and precedence.
 *
 * Precedence, highest first: the JETPACK_SYNC_QUIET_SETTINGS constant, the
 * site option, the network option (multisite). The merged array then passes
 * through the `jetpack_sync_quiet_settings` filter, which has the last word.
 * Every value is validated after the filter. An invalid value is dropped,
 * logged once, and Jetpack's own value stays in force.
 */
class Tunables
{
    public const SITE_OPTION = 'jetpack_sync_quiet_tunables';
    public const NETWORK_OPTION = 'jetpack_sync_quiet_network_tunables';

    public const SOURCE_CONSTANT = 'constant';
    public const SOURCE_SITE = 'site';
    public const SOURCE_NETWORK = 'network';
    public const SOURCE_FILTER = 'filter';
    public const SOURCE_JETPACK = 'jetpack default';

    /**
     * Key => [Jetpack setting name or null for cron intervals, Jetpack default, unit].
     *
     * Defaults are Jetpack's own (Automattic\Jetpack\Sync\Defaults, checked
     * against Jetpack 16.3 to 16.5-a.1).
     */
    public const DEFINITIONS = [
        'max_queue_size'       => ['max_queue_size', 5000, 'items'],
        'max_queue_lag'        => ['max_queue_lag', 7200, 'seconds'],
        'upload_max_rows'      => ['upload_max_rows', 500, 'rows'],
        'upload_max_bytes'     => ['upload_max_bytes', 600000, 'bytes'],
        'cron_sync_time_limit' => ['cron_sync_time_limit', 240, 'seconds'],
        'sync_wait_time'       => ['sync_wait_time', 10, 'seconds'],
        'cron_interval'        => [null, 300, 'seconds'],
        'full_sync_interval'   => [null, 300, 'seconds'],
    ];

    /**
     * Resolved tunables per blog id.
     *
     * @var array<int, array{
     *     values: array<string, int>,
     *     sources: array<string, string>,
     *     invalid: array<string, string>
     * }>
     */
    private array $resolved = [];

    /** @var array<string, array{0: int, 1: int}>|null */
    private ?array $ranges = null;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public function isKnown(string $key): bool
    {
        return isset(self::DEFINITIONS[$key]);
    }

    /**
     * The Jetpack setting a key maps to, or null for the cron intervals.
     */
    public function jetpackSetting(string $key): ?string
    {
        return self::DEFINITIONS[$key][0] ?? null;
    }

    public function jetpackDefault(string $key): int
    {
        return self::DEFINITIONS[$key][1] ?? 0;
    }

    public function unit(string $key): string
    {
        return self::DEFINITIONS[$key][2] ?? '';
    }

    /**
     * Allowed range for a key: half to four times Jetpack's default, unless
     * the `jetpack_sync_quiet_ranges` filter changes it.
     *
     * @return array{0: int, 1: int}
     */
    public function range(string $key): array
    {
        $ranges = $this->ranges();
        if (!isset($ranges[$key])) {
            throw new InvalidTunableException(sprintf('Unknown setting "%s".', $key));
        }

        return $ranges[$key];
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public function ranges(): array
    {
        if ($this->ranges !== null) {
            return $this->ranges;
        }

        $defaults = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $defaults[$key] = [intdiv($definition[1], 2), $definition[1] * 4];
        }

        /**
         * Filters the allowed range of each tunable.
         *
         * @param array<string, array{0: int, 1: int}> $defaults Key => [min, max].
         */
        $filtered = apply_filters('jetpack_sync_quiet_ranges', $defaults);
        $ranges = $defaults;
        if (is_array($filtered)) {
            foreach ($defaults as $key => $range) {
                $candidate = $filtered[$key] ?? null;
                if (
                    is_array($candidate)
                    && isset($candidate[0], $candidate[1])
                    && is_int($candidate[0])
                    && is_int($candidate[1])
                    && $candidate[0] >= 0
                    && $candidate[0] <= $candidate[1]
                ) {
                    $ranges[$key] = [$candidate[0], $candidate[1]];
                }
            }
        }

        return $this->ranges = $ranges;
    }

    /**
     * Validate one value. Accepts integers and strings of digits only.
     *
     * @throws InvalidTunableException
     */
    public function validate(string $key, mixed $value): int
    {
        if (!$this->isKnown($key)) {
            throw new InvalidTunableException(sprintf(
                'Unknown setting "%s". Known settings: %s.',
                $key,
                implode(', ', $this->keys())
            ));
        }

        [$min, $max] = $this->range($key);
        $message = sprintf(
            '%s must be a whole number from %d to %d %s (Jetpack default %d).',
            $key,
            $min,
            $max,
            $this->unit($key),
            $this->jetpackDefault($key)
        );

        if (is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value) === 1) {
            $value = (int) trim($value);
        }
        if (!is_int($value)) {
            throw new InvalidTunableException($message);
        }
        if ($value < $min || $value > $max) {
            throw new InvalidTunableException($message);
        }

        return $value;
    }

    /**
     * Effective tunables for the current site, memoised per site.
     *
     * @return array{values: array<string, int>, sources: array<string, string>, invalid: array<string, string>}
     */
    public function resolve(): array
    {
        $blogId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        if (isset($this->resolved[$blogId])) {
            return $this->resolved[$blogId];
        }

        $merged = [];
        $sources = [];
        $layers = [
            self::SOURCE_NETWORK  => $this->networkValues(),
            self::SOURCE_SITE     => $this->siteValues(),
            self::SOURCE_CONSTANT => $this->config->settings,
        ];
        foreach ($layers as $source => $values) {
            foreach ($values as $key => $value) {
                $merged[$key] = $value;
                $sources[$key] = $source;
            }
        }

        /**
         * Filters the merged tunables before validation. Has the last word.
         *
         * @param array<string, mixed> $merged Key => value.
         */
        $filtered = apply_filters('jetpack_sync_quiet_settings', $merged);
        if (!is_array($filtered)) {
            $filtered = $merged;
        }

        $values = [];
        $invalid = [];
        $finalSources = [];
        foreach ($filtered as $key => $value) {
            $key = (string) $key;
            $source = (!array_key_exists($key, $merged) || $merged[$key] !== $value)
                ? self::SOURCE_FILTER
                : $sources[$key];
            try {
                $values[$key] = $this->validate($key, $value);
                $finalSources[$key] = $source;
            } catch (InvalidTunableException $e) {
                $invalid[$key] = sprintf('%s (from %s)', $e->getMessage(), $source);
            }
        }

        return $this->resolved[$blogId] = [
            'values'  => $values,
            'sources' => $finalSources,
            'invalid' => $invalid,
        ];
    }

    /**
     * The effective value of one key, or null to leave Jetpack's value alone.
     */
    public function value(string $key): ?int
    {
        return $this->resolve()['values'][$key] ?? null;
    }

    /**
     * Forget memoised values, for example after a write or a site switch.
     */
    public function reset(): void
    {
        $this->resolved = [];
        $this->ranges = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function siteValues(): array
    {
        $stored = get_option(self::SITE_OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function networkValues(): array
    {
        if (!is_multisite()) {
            return [];
        }
        $stored = get_site_option(self::NETWORK_OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * Validate and store one value at site or network level.
     *
     * @throws InvalidTunableException
     */
    public function set(string $key, mixed $value, bool $network = false): int
    {
        $valid = $this->validate($key, $value);
        if ($network) {
            $this->requireMultisite();
            $values = $this->networkValues();
            $values[$key] = $valid;
            update_site_option(self::NETWORK_OPTION, $values);
        } else {
            $values = $this->siteValues();
            $values[$key] = $valid;
            update_option(self::SITE_OPTION, $values, true);
        }
        $this->reset();

        return $valid;
    }

    /**
     * Remove one stored value at site or network level. Returns whether it was set.
     *
     * @throws InvalidTunableException
     */
    public function unset(string $key, bool $network = false): bool
    {
        if (!$this->isKnown($key)) {
            throw new InvalidTunableException(sprintf('Unknown setting "%s".', $key));
        }
        if ($network) {
            $this->requireMultisite();
            $values = $this->networkValues();
        } else {
            $values = $this->siteValues();
        }
        if (!array_key_exists($key, $values)) {
            return false;
        }
        unset($values[$key]);

        if ($network) {
            if ($values === []) {
                delete_site_option(self::NETWORK_OPTION);
            } else {
                update_site_option(self::NETWORK_OPTION, $values);
            }
        } elseif ($values === []) {
            delete_option(self::SITE_OPTION);
        } else {
            update_option(self::SITE_OPTION, $values, true);
        }
        $this->reset();

        return true;
    }

    private function requireMultisite(): void
    {
        if (!is_multisite()) {
            throw new InvalidTunableException('Network settings need a multisite install.');
        }
    }
}
