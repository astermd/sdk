<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Http;

/**
 * An immutable file, in memory, ready to be sent as a `multipart/form-data` part.
 *
 * A handful of AsterMD endpoints take bytes rather than JSON — the intake-submission
 * file endpoints on the `sales` service are the current example. Those bytes may
 * originate from very different places: a file the integrator's users just uploaded
 * and that now sits on local disk, or a slice of a large file being fed through the
 * multipart upload flow one chunk at a time. This class is the single shape resource
 * methods accept for both, so {@see Transport} has exactly one thing to encode.
 *
 * Construct it through one of the two named constructors — the constructor itself is
 * private, so an instance always carries a file name and a MIME type:
 *
 * ```php
 * // From a file on disk — name and MIME type are derived from the path.
 * $client->intakeSubmissions()->uploadFile(
 *     $sessionId,
 *     FileUpload::fromPath('/var/uploads/id-front.jpg'),
 * );
 *
 * // From bytes already in memory — one 10 MB slice of a large video.
 * $client->intakeSubmissions()->uploadMultipartPart(
 *     $uploadId,
 *     1,
 *     FileUpload::fromContents($chunk, 'consult-video.mp4'),
 * );
 * ```
 *
 * The whole file is held in memory, so it is deliberately not the right tool for a
 * 200 MB video: use {@see \AsterMD\Sdk\Resource\IntakeSubmissions::uploadLargeFile()},
 * which streams the file from disk and only ever materialises one part at a time.
 *
 * File names are normalised on construction — directory components are dropped and
 * quote and newline characters removed — so a caller-supplied name can never break
 * out of the `Content-Disposition` header it is written into.
 */
final class FileUpload
{
    /**
     * Extension-to-MIME map covering the types the file endpoints accept.
     *
     * Consulted before `mime_content_type()` because content sniffing misreports
     * some of these — a `.docx` is a ZIP container on disk and sniffs as
     * `application/zip`, which the server rejects.
     *
     * @var array<string, string>
     */
    private const MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
    ];

    /** MIME type used when neither the file name nor the file's own bytes identify it. */
    private const FALLBACK_MIME_TYPE = 'application/octet-stream';

    private function __construct(
        private readonly string $contents,
        private readonly string $fileName,
        private readonly string $mimeType,
    ) {
    }

    /**
     * Reads a file from local disk into an upload.
     *
     * The file is read in full and held in memory, so keep this for the sizes the
     * single-shot endpoints allow. When `$fileName` is omitted the path's base name
     * is used, and when `$mimeType` is omitted it is derived from the file
     * extension, falling back to content sniffing and then to
     * `application/octet-stream`.
     *
     * @param string      $path     absolute or relative path to an existing, readable file
     * @param string|null $fileName name to send in the `Content-Disposition` part header;
     *                              defaults to the base name of `$path`
     * @param string|null $mimeType MIME type to send for the part; defaults to a type
     *                              derived from the file extension, then from the bytes
     *
     * @return self an upload carrying the file's bytes, name, and MIME type
     *
     * @throws \InvalidArgumentException if `$path` is not an existing readable file, if it
     *                                  cannot be read, or if the resulting file name is empty
     */
    public static function fromPath(string $path, ?string $fileName = null, ?string $mimeType = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException("File is not readable: {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \InvalidArgumentException("Failed to read file: {$path}");
        }

        $name = self::normaliseFileName($fileName ?? basename($path));

        return new self(
            $contents,
            $name,
            $mimeType ?? self::guessMimeType($name, $path),
        );
    }

    /**
     * Wraps bytes already held in memory into an upload.
     *
     * Use this when the bytes never came from a file on disk — a slice of a larger
     * file being sent through the multipart flow, a payload received over the wire,
     * or content generated at runtime. `$fileName` is required because there is no
     * path to derive one from; when `$mimeType` is omitted it is derived from that
     * name's extension and falls back to `application/octet-stream`.
     *
     * @param string      $contents the raw bytes to send as the part body
     * @param string      $fileName name to send in the `Content-Disposition` part header
     * @param string|null $mimeType MIME type to send for the part; defaults to a type
     *                              derived from `$fileName`
     *
     * @return self an upload carrying the given bytes, name, and MIME type
     *
     * @throws \InvalidArgumentException if `$fileName` normalises to an empty string
     */
    public static function fromContents(string $contents, string $fileName, ?string $mimeType = null): self
    {
        $name = self::normaliseFileName($fileName);

        return new self($contents, $name, $mimeType ?? self::guessMimeType($name));
    }

    /**
     * Returns the raw bytes that will form the body of the multipart part.
     *
     * @return string the file's contents
     */
    public function contents(): string
    {
        return $this->contents;
    }

    /**
     * Returns the normalised file name sent in the part's `Content-Disposition` header.
     *
     * The server uses this to derive the stored object name, inserting a UUID before
     * the extension so repeat uploads of the same name never collide.
     *
     * @return string the file name, stripped of directory components and header-breaking characters
     */
    public function fileName(): string
    {
        return $this->fileName;
    }

    /**
     * Returns the MIME type sent for the part.
     *
     * @return string the MIME type, e.g. `image/jpeg`
     */
    public function mimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * Returns the size of the upload in bytes.
     *
     * Useful for checking a file against an endpoint's documented limit before
     * spending a round trip on it, and for the `file_size` field the multipart
     * initiate call requires.
     *
     * @return int the number of bytes in `contents()`
     */
    public function size(): int
    {
        return strlen($this->contents);
    }

    /**
     * @throws \InvalidArgumentException if nothing usable remains after normalisation
     */
    private static function normaliseFileName(string $fileName): string
    {
        // Drop directory components, then strip the characters that would let a
        // caller-supplied name terminate or forge the Content-Disposition header.
        $name = str_replace(["\r", "\n", '"', '\\'], '', basename(trim($fileName)));
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('File name must not be empty.');
        }

        return $name;
    }

    private static function guessMimeType(string $fileName, ?string $path = null): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (isset(self::MIME_TYPES[$extension])) {
            return self::MIME_TYPES[$extension];
        }

        if ($path !== null && function_exists('mime_content_type')) {
            $sniffed = @mime_content_type($path);
            if (is_string($sniffed) && $sniffed !== '') {
                return $sniffed;
            }
        }

        return self::FALLBACK_MIME_TYPE;
    }
}
