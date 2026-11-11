<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet;

use IndianOpinion\JetpackSyncQuiet\Exception\JetpackUnavailableException;
use Throwable;

/**
 * Every call this plugin makes into the Jetpack Sync package goes through
 * here. Each method checks that the class and method exist first, and
 * returns null (unknown) when they do not, so a change inside Jetpack shows
 * up as "unknown" in status rather than a fatal error.
 *
 * Checked against Jetpack 16.3 (sync 5.4.0), 16.4 (sync 5.4.4) and trunk
 * 16.5-a.1 (sync 5.5.1). See docs/ARCHITECTURE.md.
 */
class JetpackSync
{
    public const OPTION_PREFIX = 'jetpack_sync_settings_';
    public const DEDICATED = 'dedicated_sync_enabled';
    public const SYNC_VIA_CRON = 'sync_via_cron';
    public const CRON_HOOK = 'jetpack_sync_cron';
    public const FULL_CRON_HOOK = 'jetpack_sync_full_cron';
    public const DEFAULT_SCHEDULE = 'jetpack_sync_interval';
    public const DEFAULT_INTERVAL = 300;

    /**
     * @param string $settingsClass   Sync settings class.
     * @param string $actionsClass    Sync actions class (status).
     * @param string $listenerClass   Sync listener class (queue).
     * @param string $versionClass    Sync package version class.
     * @param string $connectionClass Connection manager class.
     */
    public function __construct(
        private readonly string $settingsClass = 'Automattic\\Jetpack\\Sync\\Settings',
        private readonly string $actionsClass = 'Automattic\\Jetpack\\Sync\\Actions',
        private readonly string $listenerClass = 'Automattic\\Jetpack\\Sync\\Listener',
        private readonly string $versionClass = 'Automattic\\Jetpack\\Sync\\Package_Version',
        private readonly string $connectionClass = 'Automattic\\Jetpack\\Connection\\Manager',
    ) {
    }

    /**
     * The WordPress option that stores a Jetpack sync setting.
     */
    public static function optionName(string $setting): string
    {
        return self::OPTION_PREFIX . $setting;
    }

    /**
     * Whether the Jetpack Sync package is loaded (by Jetpack or any plugin that bundles it).
     */
    public function isPresent(): bool
    {
        return class_exists($this->settingsClass);
    }

    /**
     * Whether this Jetpack version still has the dedicated sync setting and its reader.
     */
    public function hasDedicatedSetting(): bool
    {
        if (!$this->isPresent() || !method_exists($this->settingsClass, 'is_dedicated_sync_enabled')) {
            return false;
        }
        $vars = get_class_vars($this->settingsClass);
        if (isset($vars['valid_settings']) && is_array($vars['valid_settings'])) {
            return isset($vars['valid_settings'][self::DEDICATED]);
        }

        return true;
    }

    /**
     * What Jetpack's own reader says about dedicated sync. Null when unknown.
     */
    public function isDedicatedEnabled(): ?bool
    {
        if (!$this->hasDedicatedSetting()) {
            return null;
        }

        return $this->call(fn () => (bool) $this->invoke($this->settingsClass, 'is_dedicated_sync_enabled'));
    }

    /**
     * A sync setting as Jetpack reads it. Null when unknown.
     */
    public function setting(string $name): mixed
    {
        if (!$this->isPresent() || !method_exists($this->settingsClass, 'get_setting')) {
            return null;
        }

        return $this->call(fn () => $this->invoke($this->settingsClass, 'get_setting', $name));
    }

    /**
     * Write dedicated_sync_enabled = 0 through Jetpack's settings API.
     *
     * @throws JetpackUnavailableException When the API is missing or throws.
     */
    public function disableDedicated(): void
    {
        if (!$this->isPresent() || !method_exists($this->settingsClass, 'update_settings')) {
            throw new JetpackUnavailableException('Jetpack Sync settings update method not found.');
        }
        try {
            $this->invoke($this->settingsClass, 'update_settings', [self::DEDICATED => 0]);
        } catch (Throwable $e) {
            throw new JetpackUnavailableException('Jetpack Sync settings update failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * False when sync is switched off by Jetpack's `disable` or `network_disable` setting.
     */
    public function isSyncEnabled(): ?bool
    {
        if (!$this->isPresent() || !method_exists($this->settingsClass, 'is_sync_enabled')) {
            return null;
        }

        return $this->call(fn () => (bool) $this->invoke($this->settingsClass, 'is_sync_enabled'));
    }

    /**
     * Whether the site is connected to WordPress.com. Null when unknown.
     */
    public function isConnected(): ?bool
    {
        if (!class_exists($this->connectionClass) || !method_exists($this->connectionClass, 'is_connected')) {
            return null;
        }

        return $this->call(function () {
            $manager = new $this->connectionClass();

            return (bool) $manager->is_connected();
        });
    }

    /**
     * The sync package version, when the package says.
     */
    public function packageVersion(): ?string
    {
        $constant = $this->versionClass . '::PACKAGE_VERSION';
        if (class_exists($this->versionClass) && defined($constant)) {
            $value = constant($constant);

            return is_scalar($value) ? (string) $value : null;
        }

        return null;
    }

    /**
     * Jetpack's public sync status, including debug details. Null when unavailable.
     *
     * @return array<string, mixed>|null
     */
    public function syncStatus(): ?array
    {
        if (!class_exists($this->actionsClass) || !method_exists($this->actionsClass, 'get_sync_status')) {
            return null;
        }
        $status = $this->call(fn () => $this->invoke($this->actionsClass, 'get_sync_status', 'debug_details'));

        return is_array($status) ? $status : null;
    }

    /**
     * Queue size and lag read straight from the sync queue, for when the
     * status method is missing.
     *
     * @return array{size: int, lag: float}|null
     */
    public function queueStats(): ?array
    {
        if (!class_exists($this->listenerClass) || !method_exists($this->listenerClass, 'get_instance')) {
            return null;
        }

        $stats = $this->call(function () {
            $listener = $this->invoke($this->listenerClass, 'get_instance');
            if (!is_object($listener) || !method_exists($listener, 'get_sync_queue')) {
                return null;
            }
            $queue = $listener->get_sync_queue();
            if (!is_object($queue) || !method_exists($queue, 'size') || !method_exists($queue, 'lag')) {
                return null;
            }

            return ['size' => (int) $queue->size(), 'lag' => (float) $queue->lag()];
        });

        return is_array($stats) ? $stats : null;
    }

    /**
     * Call a static Jetpack method after checking it can be called.
     *
     * @throws JetpackUnavailableException When the method is not callable.
     */
    private function invoke(string $class, string $method, mixed ...$args): mixed
    {
        $callable = [$class, $method];
        if (!is_callable($callable)) {
            throw new JetpackUnavailableException(sprintf('%s::%s is not available.', $class, $method));
        }

        return $callable(...$args);
    }

    /**
     * Run a Jetpack call; any error becomes null (unknown), never a fatal.
     *
     * @template T
     * @param callable(): T $callback
     * @return T|null
     */
    private function call(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
