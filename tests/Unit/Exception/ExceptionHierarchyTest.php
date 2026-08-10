<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Exception;

use AsterMD\Sdk\Exception\ApiException;
use AsterMD\Sdk\Exception\AsterMDException;
use AsterMD\Sdk\Exception\AuthenticationException;
use AsterMD\Sdk\Exception\NotFoundException;
use AsterMD\Sdk\Exception\RateLimitException;
use AsterMD\Sdk\Exception\TransportException;
use AsterMD\Sdk\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class ExceptionHierarchyTest extends TestCase
{
    public function testAllExceptionsExtendBase(): void
    {
        self::assertInstanceOf(AsterMDException::class, new TransportException('x'));
        self::assertInstanceOf(AsterMDException::class, new ApiException('x', 500, []));
        self::assertInstanceOf(ApiException::class, new AuthenticationException('x', 401, []));
        self::assertInstanceOf(ApiException::class, new NotFoundException('x', 404, []));
        self::assertInstanceOf(ApiException::class, new ValidationException('x', 422, [], []));
        self::assertInstanceOf(ApiException::class, new RateLimitException('x', 429, [], null));
    }

    public function testApiExceptionCarriesStatusAndEnvelope(): void
    {
        $e = new ApiException('boom', 503, ['success' => false, 'message' => 'boom']);
        self::assertSame(503, $e->statusCode());
        self::assertSame(['success' => false, 'message' => 'boom'], $e->envelope());
    }

    public function testValidationExceptionExposesFieldErrors(): void
    {
        $errors = ['email' => ['must be valid']];
        $e = new ValidationException('invalid', 422, ['errors' => $errors], $errors);
        self::assertSame($errors, $e->fieldErrors());
    }

    public function testRateLimitExceptionExposesRetryAfter(): void
    {
        $e = new RateLimitException('slow down', 429, [], 30);
        self::assertSame(30, $e->retryAfter());
    }
}
