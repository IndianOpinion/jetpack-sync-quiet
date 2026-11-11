<?php

/**
 * Loads every class without Composer. Order matters only for readability.
 *
 * @package IndianOpinion\JetpackSyncQuiet
 */

declare(strict_types=1);

require_once __DIR__ . '/Exception/InvalidTunableException.php';
require_once __DIR__ . '/Exception/JetpackUnavailableException.php';
require_once __DIR__ . '/Environment.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Format.php';
require_once __DIR__ . '/Tunables.php';
require_once __DIR__ . '/JetpackSync.php';
require_once __DIR__ . '/OptionFilter.php';
require_once __DIR__ . '/CronSchedules.php';
require_once __DIR__ . '/Guard.php';
require_once __DIR__ . '/Status.php';
require_once __DIR__ . '/Installer.php';
require_once __DIR__ . '/Admin/AdminPage.php';
require_once __DIR__ . '/Cli/Command.php';
require_once __DIR__ . '/Plugin.php';
