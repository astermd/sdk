<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Auth;

use AsterMD\Sdk\Auth\InMemoryTokenStore;
use AsterMD\Sdk\Auth\Token;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class InMemoryTokenStoreTest extends TestCase
{
    public function testStoresAndRetrievesToken(): void
    {
        $store = new InMemoryTokenStore();
        self::assertNull($store->get());

        $token = new Token('jwt', new DateTimeImmutable('+1 hour'));
        $store->put($token);

        self::assertSame($token, $store->get());

        $store->clear();
        self::assertNull($store->get());
    }
}
