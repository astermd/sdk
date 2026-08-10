<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Test double for PSR-18 client.
 *
 * Records every request, returns a queued sequence of responses.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $queue = [];

    private readonly Psr17Factory $factory;

    public function __construct()
    {
        $this->factory = new Psr17Factory();
    }

    public function enqueue(int $status, string $body, string $contentType = 'application/json'): void
    {
        $response = $this->factory->createResponse($status)
            ->withHeader('Content-Type', $contentType)
            ->withBody($this->factory->createStream($body));
        $this->queue[] = $response;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $response = array_shift($this->queue);
        if ($response === null) {
            throw new \RuntimeException('MockHttpClient has no queued response.');
        }

        return $response;
    }

    public function lastRequest(): RequestInterface
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \RuntimeException('MockHttpClient has recorded no requests.');
        }

        return $last;
    }
}
