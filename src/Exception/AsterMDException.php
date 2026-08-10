<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Exception;

use RuntimeException;

/**
 * Root base class for every exception thrown by the AsterMD SDK.
 *
 * Catching `AsterMDException` allows consuming code to handle all SDK-originated
 * exceptions in a single catch block. For more specific handling, catch the
 * concrete subclasses ({@see ApiException}, {@see TransportException}, etc.)
 * individually. No SDK code throws `AsterMDException` directly — all thrown
 * exceptions are one of the concrete leaf classes.
 */
abstract class AsterMDException extends RuntimeException
{
}
