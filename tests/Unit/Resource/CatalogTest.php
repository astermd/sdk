<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Resource;

use AsterMD\Sdk\Http\Transport;
use AsterMD\Sdk\Http\UrlBuilder;
use AsterMD\Sdk\Resource\Categories;
use AsterMD\Sdk\Resource\LabTests;
use AsterMD\Sdk\Resource\Medications;
use AsterMD\Sdk\Resource\Products;
use AsterMD\Sdk\Resource\Shippings;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    private MockHttpClient $http;
    private Transport $transport;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->http = new MockHttpClient();
        $this->transport = new Transport(
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            urlBuilder: new UrlBuilder('api.astermd.com'),
            tokenProvider: static fn (): string => 'jwt',
            onUnauthorized: static fn (): bool => false,
        );
    }

    public function testProductsListAndView(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":[],"meta":{}}');
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $r = new Products($this->transport);
        $r->list(['page' => 1]);
        $r->view('p1');

        self::assertSame(
            'https://api.astermd.com/v1/sales/products/list?page=1',
            (string) $this->http->requests[0]->getUri(),
        );
        self::assertSame(
            'https://api.astermd.com/v1/sales/products/view/p1',
            (string) $this->http->requests[1]->getUri(),
        );
    }

    public function testCategoriesListAndView(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":[],"meta":{}}');
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        new Categories($this->transport)->list();
        new Categories($this->transport)->view('c1');
        self::assertSame('https://api.astermd.com/v1/sales/categories/list', (string) $this->http->requests[0]->getUri());
        self::assertSame('https://api.astermd.com/v1/sales/categories/view/c1', (string) $this->http->requests[1]->getUri());
    }

    public function testLabTestsListAndView(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":[],"meta":{}}');
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        new LabTests($this->transport)->list();
        new LabTests($this->transport)->view('l1');
        self::assertSame('https://api.astermd.com/v1/sales/lab-tests/list', (string) $this->http->requests[0]->getUri());
        self::assertSame('https://api.astermd.com/v1/sales/lab-tests/view/l1', (string) $this->http->requests[1]->getUri());
    }

    public function testMedicationsListOnly(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":[],"meta":{}}');
        new Medications($this->transport)->list();
        self::assertSame('https://api.astermd.com/v1/sales/medications/list', (string) $this->http->requests[0]->getUri());
    }

    public function testShippingsListAndView(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":[],"meta":{}}');
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        new Shippings($this->transport)->list();
        new Shippings($this->transport)->view('s1');
        self::assertSame('https://api.astermd.com/v1/sales/shippings/list', (string) $this->http->requests[0]->getUri());
        self::assertSame('https://api.astermd.com/v1/sales/shippings/view/s1', (string) $this->http->requests[1]->getUri());
    }
}
