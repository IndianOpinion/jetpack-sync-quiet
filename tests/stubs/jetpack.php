<?php

/**
 * A small model of the Jetpack Sync package surfaces this plugin touches,
 * written from the public source of Automattic/jetpack (sync package 5.4
 * to 5.5). It reproduces the behaviour the plugin depends on:
 *
 * - Settings::get_setting() reads get_option('jetpack_sync_settings_' . name)
 *   and writes the default back (autoloaded) when the read returns false.
 * - Settings::update_settings() writes with update_option(), and on enabling
 *   dedicated sync runs a blocking validation request.
 * - Actions::init() runs on plugins_loaded at priority 90 and reads settings.
 * - The shutdown sender sends in-process for cron, POST, admin, manage_options
 *   and CLI; with dedicated sync on it spawns a loopback instead.
 *
 * Nothing here is Jetpack code. Test switches are public static properties.
 */

declare(strict_types=1);

namespace Automattic\Jetpack\Sync {

    final class Package_Version
    {
        public const PACKAGE_VERSION = '5.5.1';
    }

    class Defaults
    {
        /** @var array<string, mixed> */
        public static array $defaults = [
            'dedicated_sync_enabled' => 0,
            'sync_via_cron'          => 1,
            'max_queue_size'         => 5000,
            'max_queue_lag'          => 7200,
            'upload_max_rows'        => 500,
            'upload_max_bytes'       => 600000,
            'cron_sync_time_limit'   => 240,
            'sync_wait_time'         => 10,
            'disable'                => 0,
            'network_disable'        => 0,
        ];

        public static function get_default_setting(string $setting): mixed
        {
            return self::$defaults[$setting] ?? false;
        }
    }

    class Settings
    {
        public const SETTINGS_OPTION_PREFIX = 'jetpack_sync_settings_';

        /** Test switch: simulate Jetpack renaming its option prefix. */
        public static string $prefix = 'jetpack_sync_settings_';

        /** Test switch: make update_settings() refuse every write. */
        public static bool $refuseUpdates = false;

        /** @var array<string, bool> */
        public static array $valid_settings = [
            'dedicated_sync_enabled' => true,
            'sync_via_cron'          => true,
            'max_queue_size'         => true,
            'max_queue_lag'          => true,
            'upload_max_rows'        => true,
            'upload_max_bytes'       => true,
            'cron_sync_time_limit'   => true,
            'sync_wait_time'         => true,
            'disable'                => true,
            'network_disable'        => true,
        ];

        /** @var int Number of reads, for load-order tests. */
        public static int $reads = 0;

        public static function reset(): void
        {
            self::$prefix = self::SETTINGS_OPTION_PREFIX;
            self::$refuseUpdates = false;
            self::$reads = 0;
        }

        public static function get_setting(string $setting): mixed
        {
            if (!isset(self::$valid_settings[$setting])) {
                return false;
            }
            self::$reads++;
            $value = get_option(self::$prefix . $setting);
            if ($value === false) {
                $value = Defaults::get_default_setting($setting);
                update_option(self::$prefix . $setting, $value, true);
            }
            if (is_numeric($value)) {
                $value = (int) $value;
            }

            return $value;
        }

        /**
         * @param array<string, mixed> $new_settings
         */
        public static function update_settings(array $new_settings): void
        {
            if (self::$refuseUpdates) {
                return;
            }
            foreach (array_intersect_key($new_settings, self::$valid_settings) as $setting => $value) {
                $updated = update_option(self::$prefix . $setting, $value, true);
                if ($setting === 'dedicated_sync_enabled' && $updated && (bool) $value) {
                    if (!Dedicated_Sender::can_spawn_dedicated_sync_request()) {
                        update_option(self::$prefix . $setting, 0, true);
                    }
                }
            }
        }

        public static function is_dedicated_sync_enabled(): bool
        {
            return (bool) static::get_setting('dedicated_sync_enabled');
        }

        public static function is_sync_enabled(): bool
        {
            return !static::get_setting('disable') && !static::get_setting('network_disable');
        }

        public static function is_doing_cron(): bool
        {
            return Actions::$doingCron;
        }
    }

    /**
     * Settings class from an imagined Jetpack without dedicated sync.
     */
    class Settings_Without_Dedicated
    {
        /** @var array<string, bool> */
        public static array $valid_settings = ['sync_via_cron' => true, 'disable' => true];

        public static function get_setting(string $setting): mixed
        {
            return Settings::get_setting($setting);
        }

        public static function is_sync_enabled(): bool
        {
            return true;
        }

        /**
         * @param array<string, mixed> $new_settings
         */
        public static function update_settings(array $new_settings): void
        {
            throw new \LogicException('must not be called');
        }
    }

    class Queue
    {
        public function __construct(public string $id, public int $items = 0, public float $lagSeconds = 0.0)
        {
        }

        public function size(): int
        {
            return $this->items;
        }

        public function lag(): float
        {
            return $this->lagSeconds;
        }

        public function is_locked(): bool
        {
            return false;
        }
    }

    class Listener
    {
        private static ?Listener $instance = null;
        public Queue $queue;

        public function __construct()
        {
            $this->queue = new Queue('sync');
        }

        public static function get_instance(): self
        {
            return self::$instance ??= new self();
        }

        public static function reset(): void
        {
            self::$instance = null;
        }

        public function get_sync_queue(): Queue
        {
            return $this->queue;
        }
    }

    class Dedicated_Sender
    {
        public static function spawn_sync(Queue $queue): bool
        {
            if (!Settings::is_dedicated_sync_enabled()) {
                return false;
            }
            if ($queue->size() === 0) {
                return false;
            }
            wp_remote_get(rest_url('jetpack/v4/sync/spawn-sync') . '?time=' . time(), ['blocking' => false, 'timeout' => 0.01]);

            return true;
        }

        public static function can_spawn_dedicated_sync_request(): bool
        {
            wp_remote_get(rest_url('jetpack/v4/sync/spawn-sync'), ['timeout' => 30]);

            return false;
        }

        public static function maybe_change_dedicated_sync_status_from_wpcom_header(string $header): bool
        {
            $enabled = $header === 'on' ? 1 : 0;
            $current = Settings::is_dedicated_sync_enabled();
            if ($current === (bool) $enabled) {
                return $current;
            }
            Settings::update_settings(['dedicated_sync_enabled' => $enabled]);

            return Settings::is_dedicated_sync_enabled();
        }
    }

    class Sender
    {
        /** @var list<string> */
        public static array $sentInProcess = [];

        public function do_sync(): void
        {
            $queue = Listener::get_instance()->get_sync_queue();
            if (!Settings::is_dedicated_sync_enabled() || Settings::is_doing_cron()) {
                self::$sentInProcess[] = 'sync';

                return;
            }
            Dedicated_Sender::spawn_sync($queue);
        }
    }

    class Actions
    {
        public static bool $doingCron = false;
        public static bool $isPost = false;
        public static bool $isCli = false;

        /** @var array<string, mixed>|null Test switch: what get_sync_status() returns. */
        public static ?array $status = null;

        public static ?Sender $sender = null;

        /** What Jetpack's reader returned during init(), for load-order tests. */
        public static ?bool $dedicatedAtInit = null;

        public static function reset(): void
        {
            self::$dedicatedAtInit = null;
            self::$doingCron = false;
            self::$isPost = false;
            self::$isCli = false;
            self::$status = null;
            self::$sender = null;
            Sender::$sentInProcess = [];
        }

        /**
         * Registers like Main::configure() does: init runs on plugins_loaded at 90.
         */
        public static function load(): void
        {
            add_action('plugins_loaded', [self::class, 'init'], 90);
        }

        public static function init(): void
        {
            if (!Settings::is_sync_enabled()) {
                return;
            }
            self::$dedicatedAtInit = Settings::is_dedicated_sync_enabled();
            if (Settings::get_setting('sync_via_cron')) {
                add_action('init', [self::class, 'init_sync_cron_jobs'], 1);
            }
            add_action('init', [self::class, 'add_sender_shutdown'], 90);
        }

        public static function init_sync_cron_jobs(): void
        {
            add_filter('cron_schedules', [self::class, 'jetpack_cron_schedule']);
            $schedule = apply_filters('jetpack_sync_incremental_sync_interval', 'jetpack_sync_interval');
            $schedules = wp_get_schedules();
            if (!isset($schedules[$schedule])) {
                $schedule = 'jetpack_sync_interval';
            }
            if (wp_next_scheduled('jetpack_sync_cron') === false) {
                wp_schedule_event(time(), $schedule, 'jetpack_sync_cron');
            }
        }

        /**
         * @param array<string, mixed> $schedules
         * @return array<string, mixed>
         */
        public static function jetpack_cron_schedule(array $schedules): array
        {
            if (!isset($schedules['jetpack_sync_interval'])) {
                $schedules['jetpack_sync_interval'] = ['interval' => 300, 'display' => 'Every 5 minutes'];
            }

            return $schedules;
        }

        public static function should_initialize_sender(): bool
        {
            if (self::$doingCron) {
                return (bool) Settings::get_setting('sync_via_cron');
            }
            if (Settings::is_dedicated_sync_enabled()) {
                return true;
            }

            return self::$isPost || current_user_can('manage_options') || is_admin() || self::$isCli;
        }

        public static function add_sender_shutdown(): void
        {
            if (apply_filters('jetpack_sync_sender_should_load', self::should_initialize_sender())) {
                self::$sender = new Sender();
                add_action('shutdown', [self::$sender, 'do_sync'], 9998);
            }
        }

        /**
         * @return array<string, mixed>
         */
        public static function get_sync_status(?string $fields = null): array
        {
            if (self::$status !== null) {
                return self::$status;
            }
            $queue = Listener::get_instance()->get_sync_queue();

            return [
                'queue_size'      => $queue->size(),
                'queue_lag'       => $queue->lag(),
                'queue_next_sync' => 0,
                'debug_details'   => ['last_succesful_sync' => ''],
            ];
        }
    }
}

namespace Automattic\Jetpack\Connection {

    class Manager
    {
        public static bool $connected = true;

        public function is_connected(): bool
        {
            return self::$connected;
        }
    }
}
