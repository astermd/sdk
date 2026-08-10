<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit;

use AsterMD\Sdk\Config;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaultsToProductionHost(): void
    {
        $config = new Config('client-id', 'secret');

        self::assertSame('client-id', $config->clientId());
        self::assertSame('secret', $config->clientSecret());
        self::assertSame('api.astermd.com', $config->baseHost());
        self::assertSame(10, $config->timeoutSeconds());
    }

    public function testAcceptsCustomHostAndTimeout(): void
    {
        $config = new Config('id', 'sec', 'api.astermd.com', 25);
        self::assertSame('api.astermd.com', $config->baseHost());
        self::assertSame(25, $config->timeoutSeconds());
    }

    public function testRejectsHostWithScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Config('id', 'sec', 'https://api.astermd.com');
    }

    public function testRejectsHostWithPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Config('id', 'sec', 'api.astermd.com/v1');
    }

    public function testRejectsEmptyCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Config('', 'sec');
    }

    public function testAssetUrlUsesTheCdnByDefault(): void
    {
        $config = new Config('id', 'sec');

        self::assertSame(
            'https://cdn.astermd.com/org/channel/products/image.png',
            $config->assetUrl('org/channel/products/image.png'),
        );
    }

    public function testAssetUrlUsesTheAsterMdCdn(): void
    {
        $config = new Config('id', 'sec', 'api.astermd.com');

        self::assertSame('https://cdn.astermd.com/file.png', $config->assetUrl('file.png'));
    }

    public function testAssetUrlIsIndependentOfBaseHost(): void
    {
        $default = new Config('id', 'sec');
        $custom  = new Config('id', 'sec', 'api.astermd.com');

        self::assertSame($default->assetUrl('file.png'), $custom->assetUrl('file.png'));
    }

    public function testAssetUrlStripsLeadingSlashFromPath(): void
    {
        $config = new Config('id', 'sec');
        $withSlash    = $config->assetUrl('/org/image.png');
        $withoutSlash = $config->assetUrl('org/image.png');

        self::assertSame($withoutSlash, $withSlash);
        self::assertStringNotContainsString('.com//', $withSlash);
    }

    public function testAssetUrlFullExample(): void
    {
        $config = new Config('id', 'sec', 'api.astermd.com');
        $path   = '69c3cdd306d9a0bfb5143de5/69c3cdd406d9a0bfb5143de7/products/6a1c29c35f315cee0e41c369-063b25d9-88c8-4a38-bed1-1e199f7fd002.png';

        self::assertSame('https://cdn.astermd.com/' . $path, $config->assetUrl($path));
    }
}
