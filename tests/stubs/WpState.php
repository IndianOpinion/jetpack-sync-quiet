<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Tests\Stubs;

/**
 * In-memory state behind the WordPress function stubs. Reset before each test.
 */
final class WpState
{
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $hooks = [];

    /** @var array<int, array<string, mixed>> Blog id => option => value. */
    public static array $options = [];

    /** @var array<int, array<string, mixed>> Object cache per blog, consulted after pre_option. */
    public static array $objectCache = [];

    /** @var array<string, mixed> */
    public static array $siteOptions = [];

    /** @var list<array{blog: int, op: string, option: string, value: mixed}> */
    public static array $writes = [];

    /** @var array<int, array<int, array<string, array{schedule: string|false, interval?: int}>>> Blog => timestamp => hook => event. */
    public static array $cron = [];

    public static bool $multisite = false;
    public static int $blogId = 1;

    /** @var list<int> */
    public static array $blogStack = [];

    /** @var list<int> */
    public static array $sites = [1];

    /** @var array<string, bool> */
    public static array $caps = [];

    public static bool $isAdmin = false;

    /** @var list<array{url: string, args: array<string, mixed>}> */
    public static array $httpRequests = [];

    /** @var list<string> */
    public static array $redirects = [];

    /** @var array<string, int> */
    public static array $didAction = [];

    public static function reset(): void
    {
        self::$hooks = [];
        self::$options = [];
        self::$objectCache = [];
        self::$siteOptions = [];
        self::$writes = [];
        self::$cron = [];
        self::$multisite = false;
        self::$blogId = 1;
        self::$blogStack = [];
        self::$sites = [1];
        self::$caps = [];
        self::$isAdmin = false;
        self::$httpRequests = [];
        self::$redirects = [];
        self::$didAction = [];
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
    }

    /**
     * Writes to one option, for assertions.
     *
     * @return list<array{blog: int, op: string, option: string, value: mixed}>
     */
    public static function writesTo(string $option): array
    {
        return array_values(array_filter(self::$writes, static fn (array $w): bool => $w['option'] === $option));
    }
}

/**
 * Thrown by wp_die().
 */
final class WpDieException extends \RuntimeException
{
}
