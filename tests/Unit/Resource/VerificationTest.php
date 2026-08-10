<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Resource;

use AsterMD\Sdk\Enum\IdentityCheck;
use AsterMD\Sdk\Http\Transport;
use AsterMD\Sdk\Http\UrlBuilder;
use AsterMD\Sdk\Resource\Verification;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class VerificationTest extends TestCase
{
    private MockHttpClient $http;
    private Verification $resource;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->http = new MockHttpClient();
        $this->resource = new Verification(new Transport(
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            urlBuilder: new UrlBuilder('api.astermd.com'),
            tokenProvider: static fn (): string => 'jwt',
            onUnauthorized: static fn (): bool => false,
        ));
    }

    public function testAutofillAddress(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":[],"meta":{}}');
        $this->resource->autofillAddress('123 Main St, San');

        $req = $this->http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        // http_build_query form-encodes the query, so spaces arrive as `+`, not `%20`.
        self::assertSame(
            'https://api.astermd.com/v1/platform/extensions/address-autofill?search=123+Main+St%2C+San',
            (string) $req->getUri(),
        );
        self::assertSame('Bearer jwt', $req->getHeaderLine('Authorization'));
    }

    public function testVerifyAddress(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"valid":true},"meta":{}}');
        $this->resource->verifyAddress('123 Main St, San Francisco, CA 94105');

        $req = $this->http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        self::assertSame('/v1/platform/extensions/address-verify', $req->getUri()->getPath());
        self::assertSame('address=123+Main+St%2C+San+Francisco%2C+CA+94105', $req->getUri()->getQuery());
        self::assertSame('Bearer jwt', $req->getHeaderLine('Authorization'));
    }

    public function testVerifyEmail(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"result":"valid"},"meta":{}}');
        $response = $this->resource->verifyEmail('john.doe@example.com');

        $req = $this->http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        self::assertSame('/v1/platform/extensions/email-verify', $req->getUri()->getPath());
        self::assertSame('email=john.doe%40example.com', $req->getUri()->getQuery());
        self::assertSame('valid', $response->data()['result']);
    }

    public function testVerifyIdentityPostsSlugFromEnumAlongsidePayload(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"valid":true},"meta":{}}');
        $this->resource->verifyIdentity(IdentityCheck::SsnVerify, [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'phone' => '+15550001234',
            'ssn' => '000000000',
        ]);

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/platform/extensions/identity-verify',
            (string) $req->getUri(),
        );
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        self::assertSame('Bearer jwt', $req->getHeaderLine('Authorization'));
        self::assertSame([
            'slug' => 'ssn_verify',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'phone' => '+15550001234',
            'ssn' => '000000000',
        ], json_decode((string) $req->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testVerifyIdentitySlugIsNotOverridableByPayload(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->verifyIdentity(IdentityCheck::Crosscheck, [
            'slug' => 'ssn_verify',
            'firstName' => 'John',
            'lastName' => 'Doe',
        ]);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('crosscheck', $body['slug']);
    }

    public function testVerifyIdentityAcceptsNestedAddress(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->verifyIdentity(IdentityCheck::DobVerify, [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'phone' => '+15550001234',
            'dob' => '1990-01-15',
            'address' => [
                'streetAddress' => '123 Main St',
                'city' => 'San Francisco',
                'state' => 'CA',
                'postalCode' => '94105',
                'country' => 'US',
            ],
        ]);

        self::assertSame([
            'slug' => 'dob_verify',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'phone' => '+15550001234',
            'dob' => '1990-01-15',
            'address' => [
                'streetAddress' => '123 Main St',
                'city' => 'San Francisco',
                'state' => 'CA',
                'postalCode' => '94105',
                'country' => 'US',
            ],
        ], json_decode((string) $this->http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }
}
