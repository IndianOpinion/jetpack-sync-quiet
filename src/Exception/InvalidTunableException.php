<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet\Exception;

use InvalidArgumentException;

/**
 * A tunable key is unknown, or its value is not an integer in range.
 */
final class InvalidTunableException extends InvalidArgumentException
{
}
