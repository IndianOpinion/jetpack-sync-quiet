<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet;

/**
 * Writes one line per event to the PHP error log with a fixed prefix.
 * Callers decide when an event is new; the logger never deduplicates.
 */
class Logger
{
    public const PREFIX = 'jetpack-sync-quiet: ';

    public function warning(string $message): void
    {
        $this->write($message);
    }

    protected function write(string $message): void
    {
        error_log(self::PREFIX . $message);
    }
}
