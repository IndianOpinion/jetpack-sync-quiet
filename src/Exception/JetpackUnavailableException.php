<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Exception;

use RuntimeException;

/**
 * A Jetpack Sync class or method the plugin needs is missing.
 */
final class JetpackUnavailableException extends RuntimeException
{
}
