<?php

/**
 * A small stand-in for the parts of WordPress this plugin calls. It follows
 * core's behaviour where the plugin depends on it: `pre_option_{$option}`
 * short-circuits before the object cache and the database, and
 * update_option() compares against the filtered old value.
 */

declare(strict_types=1);

use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpDieException;
use IndianOpinion\JetpackSyncQuiet\Tests\Stubs\WpState;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('HOUR_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
    define('HOUR_IN_SECONDS', 3600);
    define('DAY_IN_SECONDS', 86400);
}

// Hooks.

function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
{
    WpState::$hooks[$hook][$priority][] = [$callback, $accepted_args];

    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
{
    return add_filter($hook, $callback, $priority, $accepted_args);
}

function remove_filter(string $hook, callable $callback, int $priority = 10): bool
{
    if (!isset(WpState::$hooks[$hook][$priority])) {
        return false;
    }
    foreach (WpState::$hooks[$hook][$priority] as $i => $entry) {
        if ($entry[0] == $callback) {
            unset(WpState::$hooks[$hook][$priority][$i]);
            WpState::$hooks[$hook][$priority] = array_values(WpState::$hooks[$hook][$priority]);

            return true;
        }
    }

    return false;
}

function remove_action(string $hook, callable $callback, int $priority = 10): bool
{
    return remove_filter($hook, $callback, $priority);
}

function has_filter(string $hook, callable|false $callback = false): bool|int
{
    if (!isset(WpState::$hooks[$hook])) {
        return false;
    }
    foreach (WpState::$hooks[$hook] as $priority => $entries) {
        foreach ($entries as $entry) {
            if ($callback === false) {
                return true;
            }
            if ($entry[0] == $callback) {
                return $priority;
            }
        }
    }

    return false;
}

function has_action(string $hook, callable|false $callback = false): bool|int
{
    return has_filter($hook, $callback);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    if (!isset(WpState::$hooks[$hook])) {
        return $value;
    }
    $byPriority = WpState::$hooks[$hook];
    ksort($byPriority);
    foreach ($byPriority as $entries) {
        foreach ($entries as [$callback, $accepted]) {
            $value = $callback(...array_slice(array_merge([$value], $args), 0, max(1, $accepted)));
        }
    }

    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    WpState::$didAction[$hook] = (WpState::$didAction[$hook] ?? 0) + 1;
    if (!isset(WpState::$hooks[$hook])) {
        return;
    }
    $byPriority = WpState::$hooks[$hook];
    ksort($byPriority);
    foreach ($byPriority as $entries) {
        foreach ($entries as [$callback, $accepted]) {
            $callback(...array_slice($args, 0, $accepted));
        }
    }
}

function did_action(string $hook): int
{
    return WpState::$didAction[$hook] ?? 0;
}

// Options.

function get_option(string $option, mixed $default_value = false): mixed
{
    $pre = apply_filters("pre_option_{$option}", false, $option, $default_value);
    $pre = apply_filters('pre_option', $pre, $option, $default_value);
    if ($pre !== false) {
        return $pre;
    }
    $blog = WpState::$blogId;
    if (array_key_exists($option, WpState::$objectCache[$blog] ?? [])) {
        return WpState::$objectCache[$blog][$option];
    }
    if (array_key_exists($option, WpState::$options[$blog] ?? [])) {
        return WpState::$options[$blog][$option];
    }

    return $default_value;
}

function add_option(string $option, mixed $value = '', string $deprecated = '', mixed $autoload = null): bool
{
    $blog = WpState::$blogId;
    if (array_key_exists($option, WpState::$options[$blog] ?? [])) {
        return false;
    }
    WpState::$options[$blog][$option] = $value;
    unset(WpState::$objectCache[$blog][$option]);
    WpState::$writes[] = ['blog' => $blog, 'op' => 'add', 'option' => $option, 'value' => $value];
    do_action("add_option_{$option}", $option, $value);
    do_action('added_option', $option, $value);

    return true;
}

function update_option(string $option, mixed $value, mixed $autoload = null): bool
{
    $old = get_option($option);
    if ($value === $old || maybe_serialize($value) === maybe_serialize($old)) {
        return false;
    }
    if ($old === false) {
        return add_option($option, $value, '', $autoload);
    }
    $blog = WpState::$blogId;
    // Core runs an UPDATE; no row (or an identical row) means zero rows affected and false.
    if (!array_key_exists($option, WpState::$options[$blog] ?? [])) {
        return false;
    }
    if (maybe_serialize(WpState::$options[$blog][$option]) === maybe_serialize($value)) {
        return false;
    }
    WpState::$options[$blog][$option] = $value;
    unset(WpState::$objectCache[$blog][$option]);
    WpState::$writes[] = ['blog' => $blog, 'op' => 'update', 'option' => $option, 'value' => $value];
    do_action("update_option_{$option}", $old, $value, $option);
    do_action('updated_option', $option, $old, $value);

    return true;
}

function delete_option(string $option): bool
{
    $blog = WpState::$blogId;
    if (!array_key_exists($option, WpState::$options[$blog] ?? [])) {
        return false;
    }
    unset(WpState::$options[$blog][$option], WpState::$objectCache[$blog][$option]);
    WpState::$writes[] = ['blog' => $blog, 'op' => 'delete', 'option' => $option, 'value' => null];

    return true;
}

function maybe_serialize(mixed $data): mixed
{
    return (is_array($data) || is_object($data)) ? serialize($data) : $data;
}

function get_site_option(string $option, mixed $default_value = false): mixed
{
    $pre = apply_filters("pre_site_option_{$option}", false, $option);
    if ($pre !== false) {
        return $pre;
    }
    if (!WpState::$multisite) {
        return get_option($option, $default_value);
    }

    return array_key_exists($option, WpState::$siteOptions) ? WpState::$siteOptions[$option] : $default_value;
}

function update_site_option(string $option, mixed $value): bool
{
    if (!WpState::$multisite) {
        return update_option($option, $value);
    }
    WpState::$siteOptions[$option] = $value;
    WpState::$writes[] = ['blog' => 0, 'op' => 'update', 'option' => $option, 'value' => $value];

    return true;
}

function delete_site_option(string $option): bool
{
    if (!WpState::$multisite) {
        return delete_option($option);
    }
    $had = array_key_exists($option, WpState::$siteOptions);
    unset(WpState::$siteOptions[$option]);
    WpState::$writes[] = ['blog' => 0, 'op' => 'delete', 'option' => $option, 'value' => null];

    return $had;
}

// Multisite.

function is_multisite(): bool
{
    return WpState::$multisite;
}

function get_current_blog_id(): int
{
    return WpState::$blogId;
}

function switch_to_blog(int $id): bool
{
    WpState::$blogStack[] = WpState::$blogId;
    WpState::$blogId = $id;

    return true;
}

function restore_current_blog(): bool
{
    if (WpState::$blogStack === []) {
        return false;
    }
    WpState::$blogId = array_pop(WpState::$blogStack);

    return true;
}

/**
 * @param array<string, mixed> $args
 * @return list<int>
 */
function get_sites(array $args = []): array
{
    $number = isset($args['number']) ? (int) $args['number'] : 100;

    return array_slice(WpState::$sites, 0, $number);
}

function is_main_site(): bool
{
    return WpState::$blogId === 1;
}

// Cron.

function wp_get_schedules(): array
{
    $core = [
        'hourly'     => ['interval' => 3600, 'display' => 'Once Hourly'],
        'twicedaily' => ['interval' => 43200, 'display' => 'Twice Daily'],
        'daily'      => ['interval' => 86400, 'display' => 'Once Daily'],
        'weekly'     => ['interval' => 604800, 'display' => 'Once Weekly'],
    ];

    return array_merge(apply_filters('cron_schedules', []), $core);
}

function _get_cron_array(): array
{
    $cron = WpState::$cron[WpState::$blogId] ?? [];
    ksort($cron);

    return $cron;
}

function wp_next_scheduled(string $hook, array $args = []): int|false
{
    foreach (_get_cron_array() as $timestamp => $hooks) {
        if (isset($hooks[$hook])) {
            return (int) $timestamp;
        }
    }

    return false;
}

function wp_get_schedule(string $hook, array $args = []): string|false
{
    foreach (_get_cron_array() as $hooks) {
        if (isset($hooks[$hook])) {
            return $hooks[$hook]['schedule'];
        }
    }

    return false;
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = [], bool $wp_error = false): bool
{
    $schedules = wp_get_schedules();
    if (!isset($schedules[$recurrence])) {
        return false;
    }
    WpState::$cron[WpState::$blogId][$timestamp][$hook] = ['schedule' => $recurrence, 'interval' => $schedules[$recurrence]['interval']];

    return true;
}

function wp_schedule_single_event(int $timestamp, string $hook, array $args = [], bool $wp_error = false): bool
{
    WpState::$cron[WpState::$blogId][$timestamp][$hook] = ['schedule' => false];

    return true;
}

function wp_clear_scheduled_hook(string $hook, array $args = [], bool $wp_error = false): int|false
{
    $count = 0;
    foreach (WpState::$cron[WpState::$blogId] ?? [] as $timestamp => $hooks) {
        if (isset($hooks[$hook])) {
            unset(WpState::$cron[WpState::$blogId][$timestamp][$hook]);
            if (WpState::$cron[WpState::$blogId][$timestamp] === []) {
                unset(WpState::$cron[WpState::$blogId][$timestamp]);
            }
            $count++;
        }
    }

    return $count;
}

// Users, admin, escaping.

function current_user_can(string $capability, mixed ...$args): bool
{
    return WpState::$caps[$capability] ?? false;
}

function is_admin(): bool
{
    return WpState::$isAdmin;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string
{
    return htmlspecialchars((string) preg_replace('/[^A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]/', '', $url), ENT_QUOTES, 'UTF-8');
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function sanitize_text_field(string $text): string
{
    return trim((string) preg_replace('/[\r\n\t ]+/', ' ', strip_tags($text)));
}

function wp_unslash(mixed $value): mixed
{
    return is_string($value) ? stripslashes($value) : $value;
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . ltrim($path, '/');
}

function network_admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/network/' . ltrim($path, '/');
}

function add_query_arg(array $args, string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}

function wp_safe_redirect(string $location, int $status = 302): bool
{
    WpState::$redirects[] = $location;

    return true;
}

function wp_create_nonce(string $action = ''): string
{
    return 'nonce-' . $action;
}

function wp_verify_nonce(string $nonce, string $action = ''): int|false
{
    return $nonce === wp_create_nonce($action) ? 1 : false;
}

function check_admin_referer(string $action = '', string $query_arg = '_wpnonce'): int
{
    $nonce = isset($_REQUEST[$query_arg]) && is_string($_REQUEST[$query_arg]) ? $_REQUEST[$query_arg] : '';
    $result = wp_verify_nonce($nonce, $action);
    if ($result === false) {
        wp_die('The link you followed has expired.', '', ['response' => 403]);
    }

    return $result;
}

function wp_nonce_field(string $action = '', string $name = '_wpnonce', bool $referer = true, bool $display = true): string
{
    $field = '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr(wp_create_nonce($action)) . '">';
    if ($display) {
        echo $field;
    }

    return $field;
}

/**
 * @param array<string, mixed> $args
 */
function wp_die(string $message = '', string $title = '', array $args = []): never
{
    throw new WpDieException($message);
}

function add_management_page(string $page_title, string $menu_title, string $capability, string $menu_slug, ?callable $callback = null): string
{
    WpState::$didAction['menu:tools:' . $menu_slug . ':' . $capability] = 1;

    return 'tools_page_' . $menu_slug;
}

function add_submenu_page(string $parent, string $page_title, string $menu_title, string $capability, string $menu_slug, ?callable $callback = null): string
{
    WpState::$didAction['menu:' . $parent . ':' . $menu_slug . ':' . $capability] = 1;

    return $parent . '_page_' . $menu_slug;
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}

// HTTP (recorded, never sent).

/**
 * @param array<string, mixed> $args
 * @return array<string, mixed>
 */
function wp_remote_get(string $url, array $args = []): array
{
    WpState::$httpRequests[] = ['url' => $url, 'args' => $args];

    return ['body' => '', 'response' => ['code' => 200]];
}

/**
 * @param array<string, mixed> $args
 * @return array<string, mixed>
 */
function wp_remote_post(string $url, array $args = []): array
{
    WpState::$httpRequests[] = ['url' => $url, 'args' => $args];

    return ['body' => '', 'response' => ['code' => 200]];
}

function rest_url(string $path = ''): string
{
    return 'https://example.test/wp-json/' . ltrim($path, '/');
}

function __return_false(): bool
{
    return false;
}

function __return_true(): bool
{
    return true;
}
