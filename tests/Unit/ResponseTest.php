<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit;

use AsterMD\Sdk\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testExposesStatusDataMetaAndRaw(): void
    {
        $raw = '{"success":true,"message":"ok","data":{"id":"abc"},"meta":{"page":1}}';
        $response = new Response(
            statusCode: 200,
            data: ['id' => 'abc'],
            meta: ['page' => 1],
            message: 'ok',
            raw: $raw,
        );

        self::assertSame(200, $response->statusCode());
        self::assertSame(['id' => 'abc'], $response->data());
        self::assertSame(['page' => 1], $response->meta());
        self::assertSame('ok', $response->message());
        self::assertSame($raw, $response->raw());
    }
}
