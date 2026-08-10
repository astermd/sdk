<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Http;

use AsterMD\Sdk\Http\LogRedactor;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class LogRedactorTest extends TestCase
{
    private LogRedactor $redactor;

    protected function setUp(): void
    {
        $this->redactor = new LogRedactor();
    }

    public function testRedactsBearerTokenButKeepsScheme(): void
    {
        $out = $this->redactor->headerValue('Authorization', 'Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig');

        self::assertSame('Bearer [REDACTED]', $out);
        self::assertStringNotContainsString('eyJhbGci', $out);
    }

    public function testRedactsAuthorizationHeaderCaseInsensitively(): void
    {
        self::assertSame('Bearer [REDACTED]', $this->redactor->headerValue('authorization', 'Bearer abc'));
        self::assertSame('Bearer [REDACTED]', $this->redactor->headerValue('AUTHORIZATION', 'Bearer abc'));
    }

    public function testRedactsSchemelessAuthorizationValueEntirely(): void
    {
        self::assertSame('[REDACTED]', $this->redactor->headerValue('Authorization', 'rawtokenvalue'));
    }

    public function testRedactsPhiVerificationToken(): void
    {
        self::assertSame('[REDACTED]', $this->redactor->headerValue('x-phi-verification-token', 'phi-tok-1'));
    }

    public function testLeavesOrdinaryHeadersUntouched(): void
    {
        self::assertSame('application/json', $this->redactor->headerValue('Content-Type', 'application/json'));
        self::assertSame('Mozilla/5.0', $this->redactor->headerValue('User-Agent', 'Mozilla/5.0'));
    }

    public function testRedactsClientSecretInRequestBody(): void
    {
        $body = '{"client_id":"acme","client_secret":"s3cr3t-value"}';

        $out = $this->redactor->body($body, '/v1/auth/token');

        self::assertStringNotContainsString('s3cr3t-value', $out);
        self::assertStringContainsString('"client_secret":"[REDACTED]"', $out);
        // Non-sensitive fields survive so the entry stays useful.
        self::assertStringContainsString('"client_id":"acme"', $out);
    }

    public function testRedactsAccessAndRefreshTokensInResponseBody(): void
    {
        $body = '{"access_token":"eyJhbGciOi.aaa","refresh_token":"rrr","expires_in":3600}';

        $out = $this->redactor->body($body, '/v1/auth/token');

        self::assertStringNotContainsString('eyJhbGciOi.aaa', $out);
        self::assertStringNotContainsString('"rrr"', $out);
        self::assertStringContainsString('"expires_in":3600', $out);
    }

    public function testRedactsFieldsRegardlessOfJsonSpacing(): void
    {
        $body = '{ "access_token" : "abc123" }';

        self::assertStringNotContainsString('abc123', $this->redactor->body($body, '/v1/auth/token'));
    }

    public function testRedactionSurvivesEscapedQuotesInValue(): void
    {
        $body = '{"client_secret":"has\"escaped","keep":"me"}';

        $out = $this->redactor->body($body, '/v1/auth/token');

        self::assertStringNotContainsString('has\"escaped', $out);
        self::assertStringContainsString('"keep":"me"', $out);
    }

    public function testDropsBodyEntirelyOnPatientEndpoints(): void
    {
        $body = '{"first_name":"Jane","last_name":"Doe","dob":"1990-01-15"}';

        $out = $this->redactor->body($body, '/v1/sales/patients/create');

        self::assertSame(LogRedactor::PHI_PLACEHOLDER, $out);
        self::assertStringNotContainsString('Jane', $out);
    }

    public function testDropsBodyOnPatientHealthInformationEndpoint(): void
    {
        $out = $this->redactor->body('{"phi":"sensitive"}', '/v1/sales/patients/health-information');

        self::assertSame(LogRedactor::PHI_PLACEHOLDER, $out);
    }

    public function testDropsBodyOnIdentityVerifyEndpoint(): void
    {
        $body = '{"slug":"ssn_verify","firstName":"John","ssn":"000000000","dob":"1990-01-15"}';

        $out = $this->redactor->body($body, '/v1/platform/extensions/identity-verify');

        self::assertSame(LogRedactor::PHI_PLACEHOLDER, $out);
        self::assertStringNotContainsString('000000000', $out);
    }

    public function testNonPatientPathsKeepTheirBody(): void
    {
        $body = '{"session":"abc-123"}';

        self::assertSame($body, $this->redactor->body($body, '/v1/sales/sessions/create'));
    }

    public function testOtherExtensionEndpointsKeepTheirBody(): void
    {
        $body = '{"result":"valid"}';

        self::assertSame($body, $this->redactor->body($body, '/v1/platform/extensions/email-verify'));
    }

    public function testEmptyBodyIsLeftEmpty(): void
    {
        self::assertSame('', $this->redactor->body('', '/v1/sales/patients/create'));
    }

    public function testIsPhiPathDetection(): void
    {
        self::assertTrue($this->redactor->isPhiPath('/v1/sales/patients/view/123'));
        self::assertTrue($this->redactor->isPhiPath('/V1/SALES/PATIENTS/CREATE'));
        self::assertTrue($this->redactor->isPhiPath('/v1/platform/extensions/identity-verify'));
        self::assertTrue($this->redactor->isPhiPath('/V1/PLATFORM/EXTENSIONS/IDENTITY-VERIFY'));
        self::assertFalse($this->redactor->isPhiPath('/v1/sales/opportunities/create'));
        self::assertFalse($this->redactor->isPhiPath('/v1/platform/extensions/email-verify'));
    }

    public function testPathOfExtractsRequestPath(): void
    {
        $request = new Psr17Factory()->createRequest('POST', 'https://api.astermd.com/v1/sales/patients/create?x=1');

        self::assertSame('/v1/sales/patients/create', $this->redactor->pathOf($request));
    }
}
