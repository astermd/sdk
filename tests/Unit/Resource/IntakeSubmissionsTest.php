<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Resource;

use AsterMD\Sdk\Enum\Event;
use AsterMD\Sdk\Exception\ApiException;
use AsterMD\Sdk\Http\FileUpload;
use AsterMD\Sdk\Http\Transport;
use AsterMD\Sdk\Http\UrlBuilder;
use AsterMD\Sdk\Resource\IntakeSubmissions;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class IntakeSubmissionsTest extends TestCase
{
    private MockHttpClient $http;
    private IntakeSubmissions $resource;

    /** @var list<string> */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->http = new MockHttpClient();
        $this->resource = new IntakeSubmissions(new Transport(
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            urlBuilder: new UrlBuilder('api.astermd.com'),
            tokenProvider: static fn (): string => 'jwt',
            onUnauthorized: static fn (): bool => false,
        ));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
            @rmdir(dirname($path));
        }
        $this->tempPaths = [];
    }

    public function testCreateSubmitsExpectedShape(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $data = [
            [
                'id' => 'name-2345',
                'name' => 'name',
                'label' => 'What is your full name?',
                'type' => 'text',
                'value' => [['value' => 'John']],
            ],
            [
                'id' => 'gender-2345',
                'name' => 'gender',
                'label' => 'What is your gender?',
                'type' => 'radio',
                'value' => [['value' => 'male', 'label' => 'Male']],
            ],
        ];

        $this->resource->create(
            session: '69218cdd-0701-441b-9ff2-258146e89843',
            event: Event::PreQualifyingInitiated,
            teleformId: '6a01a937449da4a6bd492a8a',
            data: $data,
        );

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://api.astermd.com/v1/sales/intake-submissions/create', (string) $req->getUri());
        self::assertJsonStringEqualsJsonString(
            json_encode([
                'session' => '69218cdd-0701-441b-9ff2-258146e89843',
                'event' => 'pre_qualifying_initiated',
                'teleform_id' => '6a01a937449da4a6bd492a8a',
                'data' => $data,
            ], JSON_THROW_ON_ERROR),
            (string) $req->getBody(),
        );
    }

    public function testUpdatePutsBySessionIdWithEventInBody(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->update(
            session: '69218cdd-0701-441b-9ff2-258146e89843',
            event: Event::IntakeCompleted,
            teleformId: '6a01a937449da4a6bd492a8a',
            data: [
                [
                    'id' => 'dob-2345',
                    'name' => 'dob',
                    'label' => 'Date of birth',
                    'type' => 'date',
                    'value' => [['value' => '1995-06-24']],
                ],
            ],
        );

        $req = $this->http->lastRequest();
        self::assertSame('PUT', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/sales/intake-submissions/update/69218cdd-0701-441b-9ff2-258146e89843',
            (string) $req->getUri(),
        );
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $req->getBody(), true);
        self::assertSame('intake_completed', $body['event']);
    }

    public function testCreateWithEmptyDataSendsJsonArray(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        // `data` is now a list of field objects, so an empty submission is the
        // JSON array `[]` — not the object `{}` the old object-shaped API required.
        $this->resource->create(
            session: 'sess-uuid',
            event: Event::PreQualifyingInitiated,
            teleformId: 'tf-id',
            data: [],
        );

        $raw = (string) $this->http->lastRequest()->getBody();
        self::assertStringContainsString('"data":[]', $raw);
        self::assertStringNotContainsString('"data":{}', $raw);
    }

    public function testUpdateWithEmptyDataSendsJsonArray(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->update(
            session: 'sess-uuid',
            event: Event::IntakeInProgress,
            teleformId: 'tf-id',
            data: [],
        );

        $raw = (string) $this->http->lastRequest()->getBody();
        self::assertStringContainsString('"data":[]', $raw);
        self::assertStringNotContainsString('"data":{}', $raw);
    }

    public function testCreateIncludesProgressWhenProvided(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->create(
            session: 'sess-uuid',
            event: Event::IntakeInProgress,
            teleformId: 'tf-id',
            data: [],
            progress: ['page' => 3, 'total' => 7],
        );

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->http->lastRequest()->getBody(), true);
        self::assertSame(['page' => 3, 'total' => 7], $body['progress']);
    }

    public function testProgressOmittedWhenNull(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->create(
            session: 'sess-uuid',
            event: Event::IntakeInitiated,
            teleformId: 'tf-id',
            data: [],
        );

        self::assertStringNotContainsString('progress', (string) $this->http->lastRequest()->getBody());
    }

    public function testUploadFilePostsMultipartFormDataToTheSessionPath(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"path":"a/b/c.jpg","name":"c.jpg","mime_type":"image/jpeg"},"meta":{}}');

        $response = $this->resource->uploadFile(
            '550e8400-e29b-41d4-a716-446655440000',
            FileUpload::fromContents('jpeg-bytes', 'id-front.jpg'),
        );

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/sales/intake-submissions/upload-file/550e8400-e29b-41d4-a716-446655440000',
            (string) $req->getUri(),
        );
        self::assertStringStartsWith('multipart/form-data; boundary=', $req->getHeaderLine('Content-Type'));
        self::assertSame('Bearer jwt', $req->getHeaderLine('Authorization'));

        $body = (string) $req->getBody();
        self::assertStringContainsString('Content-Disposition: form-data; name="file"; filename="id-front.jpg"', $body);
        self::assertStringContainsString('Content-Type: image/jpeg', $body);
        self::assertStringContainsString('jpeg-bytes', $body);

        self::assertSame('c.jpg', $response->data()['name']);
    }

    public function testInitiateMultipartUploadPostsFileDescriptorAsJson(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"upload_id":"upload-uuid"},"meta":{}}');

        $response = $this->resource->initiateMultipartUpload(
            '550e8400-e29b-41d4-a716-446655440000',
            'consult-video.mp4',
            'video/mp4',
            157286400,
        );

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/sales/intake-submissions/upload-file-multipart/initiate/550e8400-e29b-41d4-a716-446655440000',
            (string) $req->getUri(),
        );
        self::assertJsonStringEqualsJsonString(
            '{"file_name":"consult-video.mp4","mime_type":"video/mp4","file_size":157286400}',
            (string) $req->getBody(),
        );
        self::assertSame('upload-uuid', $response->data()['upload_id']);
    }

    public function testUploadMultipartPartSendsPartNumberAsQueryParameter(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"part_number":3,"etag":"\"abc\""},"meta":{}}');

        $this->resource->uploadMultipartPart(
            'upload-uuid',
            3,
            FileUpload::fromContents('chunk-bytes', 'consult-video.mp4'),
        );

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/sales/intake-submissions/upload-file-multipart/part/upload-uuid?part_number=3',
            (string) $req->getUri(),
        );
        self::assertStringStartsWith('multipart/form-data; boundary=', $req->getHeaderLine('Content-Type'));
        self::assertStringContainsString('chunk-bytes', (string) $req->getBody());
    }

    public function testUploadMultipartPartRejectsPartNumberOutsideS3Range(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resource->uploadMultipartPart('upload-uuid', 0, FileUpload::fromContents('b', 'v.mp4'));
    }

    public function testFinishMultipartUploadPostsCollectedParts(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"path":"a/b/v.mp4","name":"v.mp4","mime_type":"video/mp4"},"meta":{}}');

        $parts = [
            ['part_number' => 1, 'etag' => '"aaa"'],
            ['part_number' => 2, 'etag' => '"bbb"'],
        ];

        $this->resource->finishMultipartUpload('upload-uuid', $parts);

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/sales/intake-submissions/upload-file-multipart/finish/upload-uuid',
            (string) $req->getUri(),
        );
        self::assertJsonStringEqualsJsonString(
            json_encode(['parts' => $parts], JSON_THROW_ON_ERROR),
            (string) $req->getBody(),
        );
    }

    public function testFinishMultipartUploadRejectsAnEmptyPartsList(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resource->finishMultipartUpload('upload-uuid', []);
    }

    public function testAbortMultipartUploadPostsWithoutABody(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"aborted":true},"meta":{}}');

        $response = $this->resource->abortMultipartUpload('upload-uuid');

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/sales/intake-submissions/upload-file-multipart/abort/upload-uuid',
            (string) $req->getUri(),
        );
        self::assertSame('', (string) $req->getBody());
        self::assertTrue($response->data()['aborted']);
    }

    public function testUploadLargeFileWalksTheWholeMultipartFlow(): void
    {
        $part = IntakeSubmissions::MIN_PART_SIZE;
        $path = $this->tempFile('consult-video.mp4', str_repeat('A', $part) . 'BB');

        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"upload_id":"upload-uuid"},"meta":{}}');
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"part_number":1,"etag":"\"aaa\""},"meta":{}}');
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"part_number":2,"etag":"\"bbb\""},"meta":{}}');
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"path":"a/b/v.mp4","name":"v.mp4","mime_type":"video/mp4"},"meta":{}}');

        $response = $this->resource->uploadLargeFile(
            sessionId: '550e8400-e29b-41d4-a716-446655440000',
            filePath: $path,
            partSize: $part,
        );

        self::assertCount(4, $this->http->requests);

        self::assertJsonStringEqualsJsonString(
            '{"file_name":"consult-video.mp4","mime_type":"video/mp4","file_size":' . ($part + 2) . '}',
            (string) $this->http->requests[0]->getBody(),
        );

        foreach ([1 => [$part, 'A'], 2 => [2, 'B']] as $number => [$length, $first]) {
            $req = $this->http->requests[$number];
            self::assertSame(
                "https://api.astermd.com/v1/sales/intake-submissions/upload-file-multipart/part/upload-uuid?part_number={$number}",
                (string) $req->getUri(),
            );
            $payload = $this->partPayload($req);
            self::assertSame($length, strlen($payload));
            self::assertSame($first, $payload[0]);
        }

        self::assertJsonStringEqualsJsonString(
            json_encode(['parts' => [
                ['part_number' => 1, 'etag' => '"aaa"'],
                ['part_number' => 2, 'etag' => '"bbb"'],
            ]], JSON_THROW_ON_ERROR),
            (string) $this->http->requests[3]->getBody(),
        );

        self::assertSame('a/b/v.mp4', $response->data()['path']);
    }

    public function testUploadLargeFileAbortsWhenAPartFails(): void
    {
        $part = IntakeSubmissions::MIN_PART_SIZE;
        $path = $this->tempFile('consult-video.mp4', str_repeat('A', $part) . 'BB');

        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"upload_id":"upload-uuid"},"meta":{}}');
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"part_number":1,"etag":"\"aaa\""},"meta":{}}');
        $this->http->enqueue(500, '{"success":false,"message":"boom"}');
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"aborted":true},"meta":{}}');

        try {
            $this->resource->uploadLargeFile('sess-uuid', $path, partSize: $part);
            self::fail('Expected the failing part to surface as an ApiException.');
        } catch (ApiException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertCount(4, $this->http->requests);
        self::assertSame(
            'https://api.astermd.com/v1/sales/intake-submissions/upload-file-multipart/abort/upload-uuid',
            (string) $this->http->lastRequest()->getUri(),
        );
    }

    public function testUploadLargeFileUsesExplicitNameAndMimeTypeWhenGiven(): void
    {
        $path = $this->tempFile('tmpupload', 'AAAAA');

        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"upload_id":"upload-uuid"},"meta":{}}');
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"part_number":1,"etag":"\"aaa\""},"meta":{}}');
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->uploadLargeFile(
            sessionId: 'sess-uuid',
            filePath: $path,
            fileName: 'consult.webm',
            mimeType: 'video/webm',
        );

        self::assertJsonStringEqualsJsonString(
            '{"file_name":"consult.webm","mime_type":"video/webm","file_size":5}',
            (string) $this->http->requests[0]->getBody(),
        );
        self::assertSame('AAAAA', $this->partPayload($this->http->requests[1]));
    }

    public function testUploadLargeFileRejectsAnEmptyFile(): void
    {
        $path = $this->tempFile('empty.mp4', '');

        $this->expectException(\InvalidArgumentException::class);

        $this->resource->uploadLargeFile('sess-uuid', $path);
    }

    public function testUploadLargeFileRejectsAnUnreadablePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resource->uploadLargeFile('sess-uuid', sys_get_temp_dir() . '/astermd-nope-' . bin2hex(random_bytes(4)));
    }

    public function testUploadLargeFileRejectsAPartSizeBelowTheS3Minimum(): void
    {
        $path = $this->tempFile('consult-video.mp4', 'AAAAA');

        $this->expectException(\InvalidArgumentException::class);

        $this->resource->uploadLargeFile('sess-uuid', $path, partSize: IntakeSubmissions::MIN_PART_SIZE - 1);
    }

    public function testUploadLargeFileRejectsAPartSizeAboveTheServerLimit(): void
    {
        $path = $this->tempFile('consult-video.mp4', 'AAAAA');

        $this->expectException(\InvalidArgumentException::class);

        $this->resource->uploadLargeFile('sess-uuid', $path, partSize: IntakeSubmissions::MAX_PART_SIZE + 1);
    }

    public function testUploadLargeFileFailsWhenInitiateReturnsNoUploadId(): void
    {
        $path = $this->tempFile('consult-video.mp4', 'AAAAA');

        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->expectException(ApiException::class);

        $this->resource->uploadLargeFile('sess-uuid', $path);
    }

    public function testUploadLargeFileFailsWhenAPartReturnsNoEtag(): void
    {
        $path = $this->tempFile('consult-video.mp4', 'AAAAA');

        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"upload_id":"upload-uuid"},"meta":{}}');
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"part_number":1},"meta":{}}');
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"aborted":true},"meta":{}}');

        try {
            $this->resource->uploadLargeFile('sess-uuid', $path);
            self::fail('Expected the missing etag to surface as an ApiException.');
        } catch (ApiException $e) {
            self::assertStringContainsString('etag', $e->getMessage());
        }

        // The upload must not be left open just because a response was malformed.
        self::assertSame(
            'https://api.astermd.com/v1/sales/intake-submissions/upload-file-multipart/abort/upload-uuid',
            (string) $this->http->lastRequest()->getUri(),
        );
    }

    /**
     * Pulls the file bytes back out of a `multipart/form-data` request body.
     */
    private function partPayload(\Psr\Http\Message\RequestInterface $request): string
    {
        $body = (string) $request->getBody();
        $start = strpos($body, "\r\n\r\n");
        self::assertNotFalse($start, 'Request body is not multipart/form-data.');
        $payload = substr($body, $start + 4);

        return substr($payload, 0, strrpos($payload, "\r\n--") ?: 0);
    }

    private function tempFile(string $name, string $contents): string
    {
        $dir = sys_get_temp_dir() . '/astermd-intake-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700, true);
        $path = $dir . '/' . $name;
        file_put_contents($path, $contents);
        $this->tempPaths[] = $path;

        return $path;
    }
}
