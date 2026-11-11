<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet;

/**
 * Settings from wp-config.php constants, read once at load.
 */
final class Config
{
    /**
     * @param array<string, mixed> $settings Raw tunables from JETPACK_SYNC_QUIET_SETTINGS.
     */
    public function __construct(
        public readonly bool $disabled,
        public readonly bool $forceCron,
        public readonly bool $guardFallback,
        public readonly array $settings,
    ) {
    }

    public static function fromEnvironment(Environment $env): self
    {
        $settings = $env->constant('JETPACK_SYNC_QUIET_SETTINGS');

        return new self(
            self::flag($env->constant('JETPACK_SYNC_QUIET_DISABLE'), false),
            self::flag($env->constant('JETPACK_SYNC_QUIET_FORCE_CRON'), true),
            self::flag($env->constant('JETPACK_SYNC_QUIET_GUARD_FALLBACK'), true),
            is_array($settings) ? self::stringKeys($settings) : [],
        );
    }

    private static function flag(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private static function stringKeys(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
