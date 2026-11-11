<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet;

/**
 * Thin wrapper over PHP globals the plugin reads: constants, the clock and
 * php.ini. Tests replace it so that they can vary values PHP cannot undefine.
 */
class Environment
{
    /**
     * Value of a constant, or null when it is not defined.
     */
    public function constant(string $name): mixed
    {
        return defined($name) ? constant($name) : null;
    }

    public function now(): int
    {
        return time();
    }

    public function iniGet(string $name): string
    {
        $value = ini_get($name);

        return $value === false ? '' : $value;
    }

    public function isCli(): bool
    {
        return (bool) $this->constant('WP_CLI');
    }
}
