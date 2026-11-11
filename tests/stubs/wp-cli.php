<?php

/**
 * Minimal WP-CLI stand-in. error() and halt() throw so tests can see exit codes.
 */

declare(strict_types=1);

namespace {

    final class WP_CLI
    {
        /** @var list<string> */
        public static array $out = [];

        /** @var array<string, object> */
        public static array $commands = [];

        public static function reset(): void
        {
            self::$out = [];
            self::$commands = [];
        }

        public static function add_command(string $name, object $command): void
        {
            self::$commands[$name] = $command;
        }

        public static function line(string $message = ''): void
        {
            self::$out[] = $message;
        }

        public static function log(string $message): void
        {
            self::$out[] = $message;
        }

        public static function success(string $message): void
        {
            self::$out[] = 'Success: ' . $message;
        }

        public static function warning(string $message): void
        {
            self::$out[] = 'Warning: ' . $message;
        }

        public static function error(string $message): never
        {
            self::$out[] = 'Error: ' . $message;
            throw new \IndianOpinion\JetpackSyncQuiet\Tests\Stubs\CliExit($message, 1);
        }

        public static function halt(int $code): never
        {
            throw new \IndianOpinion\JetpackSyncQuiet\Tests\Stubs\CliExit('halt', $code);
        }

        /**
         * @param array<string, mixed> $assoc
         */
        public static function confirm(string $question, array $assoc = []): void
        {
            if (empty($assoc['yes'])) {
                throw new \IndianOpinion\JetpackSyncQuiet\Tests\Stubs\CliExit('not confirmed', 1);
            }
        }

        public static function output(): string
        {
            return implode("\n", self::$out);
        }
    }
}

namespace WP_CLI\Utils {

    /**
     * @param list<array<string, string>> $items
     * @param list<string>                $fields
     */
    function format_items(string $format, array $items, array $fields): void
    {
        foreach ($items as $item) {
            $cells = [];
            foreach ($fields as $field) {
                $cells[] = $item[$field] ?? '';
            }
            \WP_CLI::$out[] = implode("\t", $cells);
        }
    }
}

namespace IndianOpinion\JetpackSyncQuiet\Tests\Stubs {

    final class CliExit extends \RuntimeException
    {
    }
}
