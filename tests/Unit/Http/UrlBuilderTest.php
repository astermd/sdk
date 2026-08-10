<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Http;

use AsterMD\Sdk\Http\UrlBuilder;
use PHPUnit\Framework\TestCase;

final class UrlBuilderTest extends TestCase
{
    public function testBuildsProductionUrl(): void
    {
        $b = new UrlBuilder('api.astermd.com');
        self::assertSame(
            'https://api.astermd.com/v1/auth/api-credentials/token',
            $b->build('auth', '/api-credentials/token'),
        );
    }

    public function testBuildsOverrideHostUrl(): void
    {
        $b = new UrlBuilder('api.astermd.com');
        self::assertSame(
            'https://api.astermd.com/v1/sales/sessions/create',
            $b->build('sales', '/sessions/create'),
        );
    }

    public function testSubstitutesPathParams(): void
    {
        $b = new UrlBuilder('api.astermd.com');
        $url = $b->build('sales', '/patients/view/{id}', ['id' => 'abc123']);
        self::assertSame('https://api.astermd.com/v1/sales/patients/view/abc123', $url);
    }

    public function testAppendsQuery(): void
    {
        $b = new UrlBuilder('api.astermd.com');
        $url = $b->build('sales', '/products/list', [], ['page' => 2, 'limit' => 20]);
        self::assertSame('https://api.astermd.com/v1/sales/products/list?page=2&limit=20', $url);
    }
}
