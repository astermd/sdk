<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Http;

use AsterMD\Sdk\Http\CurlFormatter;
use AsterMD\Sdk\Http\LogRedactor;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class CurlFormatterTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function testGetRequestWithTwoHeaders(): void
    {
        $request = $this->factory->createRequest('GET', 'https://api.example.com/v1/foo')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer tok123');

        $output = CurlFormatter::format($request);

        self::assertStringContainsString("curl --location --request GET 'https://api.example.com/v1/foo'", $output);
        self::assertStringContainsString("--header 'Accept: application/json'", $output);
        self::assertStringContainsString("--header 'Authorization: Bearer tok123'", $output);
        // No trailing backslash on last line
        $lines = explode("\n", $output);
        self::assertStringEndsNotWith('\\', trim(end($lines)));
    }

    public function testPostRequestWithJsonBodyIncludesDataLine(): void
    {
        $body = '{"foo":"bar"}';
        $stream = $this->factory->createStream($body);
        $request = $this->factory->createRequest('POST', 'https://api.example.com/v1/sessions/create')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        $output = CurlFormatter::format($request);

        self::assertStringContainsString("--data '{\"foo\":\"bar\"}'", $output);
        self::assertStringContainsString('--request POST', $output);
        // Last line must not have trailing backslash
        $lines = explode("\n", $output);
        self::assertStringEndsNotWith('\\', trim(end($lines)));
    }

    public function testBodyWithSingleQuoteIsEscaped(): void
    {
        $body = "it's here";
        $stream = $this->factory->createStream($body);
        $request = $this->factory->createRequest('POST', 'https://api.example.com/v1/test')
            ->withBody($stream);

        $output = CurlFormatter::format($request);

        // Single quote must be escaped as '\''
        self::assertStringContainsString("it'\\''s here", $output);
    }

    public function testEmptyBodyProducesNoDataLine(): void
    {
        $request = $this->factory->createRequest('GET', 'https://api.example.com/v1/test')
            ->withHeader('Accept', 'application/json');

        $output = CurlFormatter::format($request);

        self::assertStringNotContainsString('--data', $output);
    }

    public function testNoTrailingBackslashOnFinalLine(): void
    {
        $request = $this->factory->createRequest('POST', 'https://api.example.com/v1/test')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('{}'));

        $output = CurlFormatter::format($request);
        $lines = explode("\n", $output);
        $lastLine = end($lines);

        self::assertStringEndsNotWith('\\', rtrim($lastLine));
    }

    public function testContinuationLinesHaveTrailingBackslash(): void
    {
        $request = $this->factory->createRequest('POST', 'https://api.example.com/v1/test')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->factory->createStream('{}'));

        $output = CurlFormatter::format($request);
        $lines = explode("\n", $output);

        // First line and header lines should end with backslash
        self::assertStringEndsWith('\\', $lines[0]);
        // Intermediate header lines should end with backslash
        foreach (array_slice($lines, 1, count($lines) - 2) as $line) {
            self::assertStringEndsWith('\\', $line);
        }
    }

    public function testWithoutARedactorTheTokenIsRenderedVerbatim(): void
    {
        $request = $this->factory->createRequest('GET', 'https://api.example.com/v1/test')
            ->withHeader('Authorization', 'Bearer tok123');

        self::assertStringContainsString('Bearer tok123', CurlFormatter::format($request));
    }

    public function testRedactorMasksTheAuthorizationHeader(): void
    {
        $request = $this->factory->createRequest('GET', 'https://api.example.com/v1/test')
            ->withHeader('Authorization', 'Bearer tok123')
            ->withHeader('Content-Type', 'application/json');

        $output = CurlFormatter::format($request, new LogRedactor());

        self::assertStringNotContainsString('tok123', $output);
        self::assertStringContainsString("--header 'Authorization: Bearer [REDACTED]'", $output);
        self::assertStringContainsString("--header 'Content-Type: application/json'", $output);
    }

    public function testRedactorMasksClientSecretInTheRequestBody(): void
    {
        $request = $this->factory->createRequest('POST', 'https://api.example.com/v1/auth/token')
            ->withBody($this->factory->createStream('{"client_id":"acme","client_secret":"s3cr3t"}'));

        $output = CurlFormatter::format($request, new LogRedactor());

        self::assertStringNotContainsString('s3cr3t', $output);
        self::assertStringContainsString('"client_id":"acme"', $output);
    }

    public function testRedactorDropsPatientRequestBodies(): void
    {
        $request = $this->factory->createRequest('POST', 'https://api.example.com/v1/sales/patients/create')
            ->withBody($this->factory->createStream('{"first_name":"Jane"}'));

        $output = CurlFormatter::format($request, new LogRedactor());

        self::assertStringNotContainsString('Jane', $output);
        self::assertStringContainsString(LogRedactor::PHI_PLACEHOLDER, $output);
    }
}
