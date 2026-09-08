<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Http;

use AsterMD\Sdk\Http\FileUpload;
use PHPUnit\Framework\TestCase;

final class FileUploadTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/astermd-fileupload-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testFromPathReadsContentsAndDerivesNameAndMimeType(): void
    {
        $path = $this->dir . '/id-front.jpg';
        file_put_contents($path, 'jpeg-bytes');

        $upload = FileUpload::fromPath($path);

        self::assertSame('jpeg-bytes', $upload->contents());
        self::assertSame('id-front.jpg', $upload->fileName());
        self::assertSame('image/jpeg', $upload->mimeType());
        self::assertSame(10, $upload->size());
    }

    public function testFromPathHonoursExplicitNameAndMimeType(): void
    {
        $path = $this->dir . '/tmpupload';
        file_put_contents($path, 'bytes');

        $upload = FileUpload::fromPath($path, 'consult-video.mp4', 'video/webm');

        self::assertSame('consult-video.mp4', $upload->fileName());
        self::assertSame('video/webm', $upload->mimeType());
    }

    public function testFromPathRejectsUnreadableFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FileUpload::fromPath($this->dir . '/does-not-exist.pdf');
    }

    public function testFromContentsKeepsBytesAndDerivesMimeTypeFromFileName(): void
    {
        $upload = FileUpload::fromContents('%PDF-1.7', 'consent.pdf');

        self::assertSame('%PDF-1.7', $upload->contents());
        self::assertSame('consent.pdf', $upload->fileName());
        self::assertSame('application/pdf', $upload->mimeType());
    }

    public function testFromContentsFallsBackToOctetStreamForUnknownExtension(): void
    {
        $upload = FileUpload::fromContents('chunk-bytes', 'part-1');

        self::assertSame('application/octet-stream', $upload->mimeType());
    }

    public function testFromContentsRejectsEmptyFileName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FileUpload::fromContents('bytes', '   ');
    }

    public function testFileNameIsStrippedOfDirectoriesAndHeaderInjectionCharacters(): void
    {
        $upload = FileUpload::fromContents('bytes', "../../etc/pas\"sw\r\nd.txt");

        self::assertSame('passwd.txt', $upload->fileName());
    }

    public function testMimeTypeDerivationIsCaseInsensitive(): void
    {
        self::assertSame('image/png', FileUpload::fromContents('b', 'SCAN.PNG')->mimeType());
    }
}
