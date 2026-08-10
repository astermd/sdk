<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Resource;

use AsterMD\Sdk\Http\Transport;
use AsterMD\Sdk\Http\UrlBuilder;
use AsterMD\Sdk\Resource\Patients;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class PatientsTest extends TestCase
{
    private MockHttpClient $http;
    private Patients $resource;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->http = new MockHttpClient();
        $this->resource = new Patients(new Transport(
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            urlBuilder: new UrlBuilder('api.astermd.com'),
            tokenProvider: static fn (): string => 'jwt',
            onUnauthorized: static fn (): bool => false,
        ));
    }

    public function testCreatePostsBody(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"id":"p1"},"meta":{}}');
        $this->resource->create(['first_name' => 'Jane', 'email' => 'j@example.com']);

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://api.astermd.com/v1/sales/patients/create', (string) $req->getUri());
    }

    public function testViewGetsById(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->view('p1');
        self::assertSame('https://api.astermd.com/v1/sales/patients/view/p1', (string) $this->http->lastRequest()->getUri());
    }

    public function testUpdatePuts(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->update('p1', ['first_name' => 'Janet']);
        $req = $this->http->lastRequest();
        self::assertSame('PUT', $req->getMethod());
        self::assertSame('https://api.astermd.com/v1/sales/patients/update/p1', (string) $req->getUri());
    }

    public function testStatusPatches(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->status('p1', 'active');
        $req = $this->http->lastRequest();
        self::assertSame('PATCH', $req->getMethod());
        self::assertJsonStringEqualsJsonString('{"status":"active"}', (string) $req->getBody());
    }

    public function testSubmitHealthInformationSendsPhiHeaderWhenProvided(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->submitHealthInformation(['answers' => []], 'phi-tok');

        $req = $this->http->lastRequest();
        self::assertSame('https://api.astermd.com/v1/sales/patients/health-information', (string) $req->getUri());
        self::assertSame('phi-tok', $req->getHeaderLine('x-phi-verification-token'));
    }

    public function testSubmitHealthInformationOmitsPhiHeaderWhenNull(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->submitHealthInformation(['answers' => []]);
        self::assertSame('', $this->http->lastRequest()->getHeaderLine('x-phi-verification-token'));
    }

    public function testVerifyHealthInformationOtp(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->verifyHealthInformationOtp(['otp' => '123456']);
        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/sales/patients/health-information/otp-verification',
            (string) $req->getUri(),
        );
    }
}
