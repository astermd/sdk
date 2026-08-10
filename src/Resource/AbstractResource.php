<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Http\Transport;

/**
 * Base class for every SDK resource; holds the shared {@see Transport} collaborator.
 *
 * Resource classes extend this class and call `$this->transport->send()` to dispatch
 * all outbound requests. Resources never construct PSR-7 objects, touch HTTP headers,
 * or decode JSON directly — all of that is the sole responsibility of Transport.
 */
abstract class AbstractResource
{
    /**
     * @param Transport $transport the shared HTTP transport used for all outbound API calls
     */
    public function __construct(protected readonly Transport $transport)
    {
    }
}
