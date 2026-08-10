<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Resource;

use AsterMD\Sdk\Enum\Event;
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
}
