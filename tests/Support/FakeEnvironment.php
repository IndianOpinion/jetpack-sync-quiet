<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Tests\Support;

use IndianOpinion\JetpackSyncQuiet\Environment;

final class FakeEnvironment extends Environment
{
    /** @var array<string, mixed> */
    public array $constants = [];

    /** @var array<string, string> */
    public array $ini = ['max_execution_time' => '0'];

    public function __construct(public int $time)
    {
    }

    public function constant(string $name): mixed
    {
        return $this->constants[$name] ?? null;
    }

    public function now(): int
    {
        return $this->time;
    }

    public function iniGet(string $name): string
    {
        return $this->ini[$name] ?? '';
    }
}
