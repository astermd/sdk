<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Http;

use AsterMD\Sdk\Exception\TransportException;
use AsterMD\Sdk\Http\NativeCurlClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

final class NativeCurlClientTest extends TestCase
{
    public function testImplementsPsr18(): void
    {
        self::assertInstanceOf(ClientInterface::class, new NativeCurlClient(5));
    }

    public function testThrowsTransportExceptionOnUnreachableHost(): void
    {
        $client = new NativeCurlClient(timeoutSeconds: 2);
        $factory = new Psr17Factory();
        $request = $factory->createRequest('GET', 'https://this-host-does-not-exist.invalid./');

        $this->expectException(TransportException::class);
        $client->sendRequest($request);
    }
}
