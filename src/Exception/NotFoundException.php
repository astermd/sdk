<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Exception;

/**
 * Thrown when the API returns a 404 Not Found response.
 *
 * Indicates that the requested resource (a patient record, session, treatment,
 * product, etc.) does not exist on the server. Inspect `envelope()` for any
 * additional context provided by the server. All methods on resource classes
 * that accept an ID or other identifier may throw this exception when the
 * referenced entity cannot be found.
 */
final class NotFoundException extends ApiException
{
}
