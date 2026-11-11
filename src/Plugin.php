<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet;

use IndianOpinion\JetpackSyncQuiet\Admin\AdminPage;
use IndianOpinion\JetpackSyncQuiet\Cli\Command;

/**
 * Wires everything to WordPress. Built once per request at plugin file load.
 *
 * The switch filters are added at file load, before `plugins_loaded`,
 * because Jetpack's sync package first reads its settings on
 * `plugins_loaded` at priority 90 (Jetpack 16.3 to 16.5-a.1).
 */
final class Plugin
{
    public const VERSION = '0.1.0';
    public const SLUG = 'jetpack-sync-quiet';

    /** Package detection runs after every other `plugins_loaded` callback that matters. */
    public const DETECT_PRIORITY = 1000;

    private static ?Plugin $instance = null;

    private readonly Tunables $tunables;
    private readonly OptionFilter $switch;
    private readonly CronSchedules $cronSchedules;
    private readonly Guard $guard;

    public function __construct(
        private readonly string $file,
        private readonly Config $config,
        private readonly Environment $env,
        private readonly JetpackSync $jetpack,
        Logger $logger,
    ) {
        $this->tunables = new Tunables($config);
        $this->switch = new OptionFilter($config, $this->tunables);
        $this->cronSchedules = new CronSchedules($this->tunables, $this->switch);
        $this->guard = new Guard($jetpack, $this->switch, $config, $logger, $env);
    }

    /**
     * Build the plugin and hook it into WordPress.
     */
    public static function boot(
        string $file,
        ?Environment $env = null,
        ?JetpackSync $jetpack = null,
        ?Logger $logger = null,
    ): self {
        $env ??= new Environment();
        $plugin = new self(
            $file,
            Config::fromEnvironment($env),
            $env,
            $jetpack ?? new JetpackSync(),
            $logger ?? new Logger(),
        );
        self::$instance = $plugin;
        $plugin->register();

        return $plugin;
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public function register(): void
    {
        if (!$this->config->disabled) {
            $this->switch->register();
            $this->cronSchedules->register();

            $option = JetpackSync::optionName(JetpackSync::DEDICATED);
            add_action('plugins_loaded', [$this, 'detectPackage'], self::DETECT_PRIORITY);
            add_action('init', [$this, 'onInit'], 20);
            add_action('wp_loaded', [$this->guard, 'checkAfterInit']);
            add_action('update_option_' . $option, [$this->guard, 'onStoredUpdated'], 10, 2);
            add_action('add_option_' . $option, [$this->guard, 'onStoredAdded'], 10, 2);
            add_action(Guard::HOOK, [$this->guard, 'runDaily']);
            add_action(Guard::UPGRADE_HOOK, [$this->guard, 'runAfterUpgrade']);
            add_action('upgrader_process_complete', [$this->guard, 'onUpgrade'], 10, 0);
        }

        if (is_admin()) {
            (new AdminPage($this))->register();
        }

        if ($this->env->isCli() && class_exists('WP_CLI')) {
            \WP_CLI::add_command('jetpack-sync-quiet', new Command($this));
        }
    }

    /**
     * Late on `plugins_loaded`: if no plugin has loaded the sync package,
     * remove every filter and the guard event. Detection is by the sync
     * settings class, not the Jetpack plugin, because other plugins bundle it.
     */
    public function detectPackage(): void
    {
        if ($this->jetpack->isPresent()) {
            return;
        }
        $this->switch->unregister();
        $this->cronSchedules->unregister();
        $this->guard->unschedule();
    }

    public function onInit(): void
    {
        $this->guard->maybeSchedule();
        if ($this->switch->isRegistered()) {
            $this->guard->noteInvalidTunables($this->tunables->resolve()['invalid']);
        }
    }

    public function stateDescription(): string
    {
        if ($this->config->disabled) {
            return 'no-op: disabled by JETPACK_SYNC_QUIET_DISABLE';
        }
        if (!$this->jetpack->isPresent()) {
            return 'no-op: Jetpack Sync not found';
        }
        if (!$this->switch->isRegistered()) {
            return 'no-op: filters not registered';
        }
        if (!$this->switch->isEnabled()) {
            return 'no-op: switched off by the jetpack_sync_quiet_enabled filter';
        }
        $inactive = $this->guard->inactiveReason();

        return $inactive === null ? 'active' : sprintf('active (%s; the filter is harmless)', $inactive);
    }

    public function installType(): string
    {
        $muDir = $this->env->constant('WPMU_PLUGIN_DIR');
        if (is_string($muDir) && $muDir !== '' && self::isInside($this->file, $muDir)) {
            return 'must-use';
        }
        if (!is_multisite()) {
            return 'normal';
        }
        $network = get_site_option('active_sitewide_plugins', []);
        if (is_array($network) && isset($network[plugin_basename($this->file)])) {
            return 'network-activated';
        }

        return 'this site only';
    }

    private static function isInside(string $file, string $dir): bool
    {
        $file = str_replace('\\', '/', (string) (realpath($file) ?: $file));
        $dir = rtrim(str_replace('\\', '/', (string) (realpath($dir) ?: $dir)), '/') . '/';

        return str_starts_with($file, $dir);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function environment(): Environment
    {
        return $this->env;
    }

    public function jetpack(): JetpackSync
    {
        return $this->jetpack;
    }

    public function tunables(): Tunables
    {
        return $this->tunables;
    }

    public function optionFilter(): OptionFilter
    {
        return $this->switch;
    }

    public function cronSchedules(): CronSchedules
    {
        return $this->cronSchedules;
    }

    public function guard(): Guard
    {
        return $this->guard;
    }

    public function status(): Status
    {
        return new Status($this);
    }
}
