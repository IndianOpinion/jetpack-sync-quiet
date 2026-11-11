<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Tests\Support;

use IndianOpinion\JetpackSyncQuiet\Logger;

final class FakeLogger extends Logger
{
    /** @var list<string> */
    public array $lines = [];

    protected function write(string $message): void
    {
        $this->lines[] = self::PREFIX . $message;
    }
}
