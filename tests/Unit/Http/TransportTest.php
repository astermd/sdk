<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Http;

use AsterMD\Sdk\Exception\ApiException;
use AsterMD\Sdk\Exception\NotFoundException;
use AsterMD\Sdk\Exception\RateLimitException;
use AsterMD\Sdk\Exception\ValidationException;
use AsterMD\Sdk\Http\FileUpload;
use AsterMD\Sdk\Http\Transport;
use AsterMD\Sdk\Http\UrlBuilder;
use AsterMD\Sdk\Response;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private MockHttpClient $http;
    private Transport $transport;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $factory = new Psr17Factory();
        $this->transport = new Transport(
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            urlBuilder: new UrlBuilder('api.astermd.com'),
            tokenProvider: static fn (): string => 'jwt-fixture',
            onUnauthorized: static fn (): bool => false, // no retry in these tests
        );
    }

    public function testGetReturnsDecodedResponse(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"id":"abc"},"meta":{"page":1}}');

        $response = $this->transport->send('sales', 'GET', '/products/view/{id}', pathParams: ['id' => 'abc']);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->statusCode());
        self::assertSame(['id' => 'abc'], $response->data());
        self::assertSame(['page' => 1], $response->meta());

        $req = $this->http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/sales/products/view/abc',
            (string) $req->getUri(),
        );
        self::assertSame('Bearer jwt-fixture', $req->getHeaderLine('Authorization'));
        self::assertSame('application/json', $req->getHeaderLine('Accept'));
    }

    public function testPostSerializesJsonBody(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"created","data":{},"meta":{}}');

        $this->transport->send('sales', 'POST', '/patients/create', body: ['first_name' => 'Jane']);

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        self::assertSame('{"first_name":"Jane"}', (string) $req->getBody());
    }

    public function testEmptyArrayBodyIsEncodedAsJsonObject(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        // Sending `[]` would naively encode to the JSON array literal `[]`, which the
        // AsterMD API rejects (object-shaped bodies are required). Transport must coerce.
        $this->transport->send('sales', 'POST', '/sessions/create', body: []);

        $req = $this->http->lastRequest();
        self::assertSame('{}', (string) $req->getBody());
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
    }

    public function testForwardsExtraHeaders(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->transport->send(
            'sales',
            'POST',
            '/patients/health-information',
            body: ['x' => 1],
            headers: ['x-phi-verification-token' => 'phi-token-xyz'],
        );

        self::assertSame('phi-token-xyz', $this->http->lastRequest()->getHeaderLine('x-phi-verification-token'));
    }

    public function testFileUploadIsSentAsMultipartFormData(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->transport->send(
            'sales',
            'POST',
            '/intake-submissions/upload-file/{session_id}',
            pathParams: ['session_id' => 'sess-uuid'],
            file: FileUpload::fromContents('jpeg-bytes', 'id-front.jpg'),
        );

        $req = $this->http->lastRequest();
        $contentType = $req->getHeaderLine('Content-Type');
        self::assertMatchesRegularExpression('#^multipart/form-data; boundary=[A-Za-z0-9]+$#', $contentType);

        $boundary = substr($contentType, strlen('multipart/form-data; boundary='));

        self::assertSame(
            "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"id-front.jpg\"\r\n"
            . "Content-Type: image/jpeg\r\n"
            . "\r\n"
            . "jpeg-bytes\r\n"
            . "--{$boundary}--\r\n",
            (string) $req->getBody(),
        );
    }

    public function testEachMultipartRequestUsesItsOwnBoundary(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $file = FileUpload::fromContents('bytes', 'part-1');
        $this->transport->send('sales', 'POST', '/x', file: $file);
        $this->transport->send('sales', 'POST', '/x', file: $file);

        self::assertNotSame(
            $this->http->requests[0]->getHeaderLine('Content-Type'),
            $this->http->requests[1]->getHeaderLine('Content-Type'),
        );
    }

    public function testSendRejectsBodyAndFileTogether(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->transport->send(
            'sales',
            'POST',
            '/x',
            body: ['a' => 'b'],
            file: FileUpload::fromContents('bytes', 'x.txt'),
        );
    }

    public function testMaps404ToNotFoundException(): void
    {
        $this->http->enqueue(404, '{"success":false,"message":"not found","data":{},"meta":{}}');

        $this->expectException(NotFoundException::class);
        $this->transport->send('sales', 'GET', '/patients/view/{id}', pathParams: ['id' => 'x']);
    }

    public function testMaps422ToValidationExceptionWithFieldErrors(): void
    {
        $body = '{"success":false,"message":"invalid","errors":{"email":["must be valid"]},"data":{},"meta":{}}';
        $this->http->enqueue(422, $body);

        try {
            $this->transport->send('sales', 'POST', '/patients/create', body: []);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['email' => ['must be valid']], $e->fieldErrors());
            self::assertSame(422, $e->statusCode());
        }
    }

    public function testMaps429ToRateLimitWithRetryAfter(): void
    {
        $this->http->enqueue(429, '{"success":false,"message":"slow"}');
        // queued response will have no Retry-After header in this simple harness.

        try {
            $this->transport->send('sales', 'GET', '/products/list');
            self::fail('expected RateLimitException');
        } catch (RateLimitException $e) {
            self::assertSame(429, $e->statusCode());
            self::assertNull($e->retryAfter());
        }
    }

    public function testMaps500ToGenericApiException(): void
    {
        $this->http->enqueue(500, '{"success":false,"message":"boom"}');

        $this->expectException(ApiException::class);
        $this->transport->send('sales', 'GET', '/products/list');
    }
}
