<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Enum\Event;
use AsterMD\Sdk\Exception\ApiException;
use AsterMD\Sdk\Http\FileUpload;
use AsterMD\Sdk\Response;

/**
 * Records and retrieves pre-qualifying and intake form submissions on the `sales` service.
 *
 * An intake submission captures the answers a prospect provides while filling in a
 * questionnaire or intake form. Each submission is linked to a session UUID and a
 * teleform ID, and carries an {@see Event} value that advances the
 * server-side journey state machine. Both the pre-qualifying flow and the intake
 * flow share the same wire shape; only the `Event` value distinguishes them.
 *
 * Multi-page forms may pass an optional `$progress` (`{page, total}`) on `create()`
 * and `update()`. When present on an in-progress event, the server records it as the
 * event's `meta`, which drives Save-&-Resume and drop-off analytics.
 *
 * The stored submission's `data` (the raw field answers) and the server-derived
 * `contact` block (email / name / phone extracted from `data`) are PHI. Never log
 * them; see the SDK's privacy rules.
 *
 * Typical order-flow sequence:
 *  1. `create()` with `Event::PreQualifyingInitiated` — prospect starts the pre-qualify form.
 *  2. `update()` with `Event::PreQualifyingInProgress` — prospect advances through steps.
 *  3. `update()` with `Event::PreQualifyingCompleted` — prospect completes pre-qualify.
 *  4. Repeat steps 1–3 using the `Intake*` events for the full intake questionnaire.
 *
 * Answers to `file`-type fields are uploaded separately, before the submission that
 * references them is created or updated. Small files go through {@see self::uploadFile()}
 * in one request; large ones go through the multipart flow, most easily via
 * {@see self::uploadLargeFile()}. Either way the returned `{path, name, mime_type}`
 * object is what you attach to the field's `value` entry.
 */
final class IntakeSubmissions extends AbstractResource
{
    /**
     * Retrieves a previously submitted intake submission for a given session and teleform.
     *
     * Use this to pre-populate a form when a prospect returns mid-flow or to audit
     * what was submitted. The `$id` parameter is the session UUID (used as a path
     * parameter) and `$teleformId` is the teleform ID sent as a query parameter.
     * Inspect `$response->data()` for the stored submission, whose `data` key holds
     * the list of submitted form fields.
     *
     * The returned `data` and `contact` fields are PHI — do not log the response body.
     *
     * @param string $id         the session UUID whose intake submission to retrieve
     * @param string $teleformId the ID of the teleform definition the data belongs to
     *
     * @return Response the stored submission envelope; `data()` contains the record including its `data` list of fields, derived `status`, `progress`, and `contact`
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no submission exists for this session / teleform combination
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function view(string $id, string $teleformId): Response
    {
        return $this->transport->send(
            'sales',
            'GET',
            '/intake-submissions/view/{id}',
            pathParams: ['id' => $id],
            query: ['teleform_id' => $teleformId],
        );
    }

    /**
     * Records the initial submission of a pre-qualifying or intake form for a session.
     *
     * Call this when the prospect submits the first step (or the entire form in one
     * go). Use `Event::PreQualifyingInitiated` for the pre-qualify questionnaire or
     * `Event::IntakeInitiated` for the intake form. `$data` is a list of field
     * objects (see the `@param` below); an empty array is sent as `[]`. Pass
     * `$progress` for multi-page forms to enable Save-&-Resume and drop-off tracking;
     * omit it for single-page forms. Inspect `$response->data()` for the created
     * record's ID.
     *
     * `$data` is PHI — do not log it.
     *
     * @param string $session    the session UUID to associate this submission with
     * @param Event  $event      the journey event to record; use an `*Initiated` value here
     *                           (e.g. `Event::PreQualifyingInitiated`, `Event::IntakeInitiated`)
     * @param string $teleformId the ID of the teleform definition being submitted
     * @param list<array{id: string, name: string, label: string, type: string, value: list<array<string, mixed>>}> $data
     *                           the submitted form fields. Each entry is one field carrying its `id`, `name`,
     *                           `label`, `type` (e.g. `text`, `textarea`, `radio`, `checkbox`, `dropdown`, `date`,
     *                           `file`, `rating`), and a `value` list; each value holds a `value` plus, for
     *                           choice/file fields, an optional `label`/`url`. An empty array sends `[]`.
     * @param array{page: int, total: int}|null $progress optional multi-page position; sent only when provided
     *
     * @return Response the created intake-submission envelope; `data()` contains the new record details
     *
     * @throws \AsterMD\Sdk\Exception\ValidationException if required fields are missing or malformed
     * @throws \AsterMD\Sdk\Exception\ApiException        on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException  on a network-level failure
     */
    public function create(string $session, Event $event, string $teleformId, array $data, ?array $progress = null): Response
    {
        $body = [
            'session' => $session,
            'event' => $event->value,
            'teleform_id' => $teleformId,
            'data' => $data,
        ];

        if ($progress !== null) {
            $body['progress'] = $progress;
        }

        return $this->transport->send('sales', 'POST', '/intake-submissions/create', body: $body);
    }

    /**
     * Records progress or completion of a multi-step pre-qualifying or intake form.
     *
     * Call this for each subsequent step after the initial `create()`. Use
     * `Event::PreQualifyingInProgress` or `Event::IntakeInProgress` for intermediate
     * steps, and `Event::PreQualifyingCompleted` or `Event::IntakeCompleted` when
     * the prospect finishes the form. The full accumulated `$data` list must be
     * re-sent on every call — the server replaces the stored data array rather than
     * merging partial updates. An empty `$data` array is sent as `[]`. Pass
     * `$progress` for multi-page forms; when sent on an in-progress event the server
     * records it as the event's `meta`.
     *
     * `$data` is PHI — do not log it.
     *
     * @param string $session    the session UUID the existing submission is linked to
     * @param Event  $event      the journey event to record; use `*InProgress` or `*Completed` values
     *                           (e.g. `Event::PreQualifyingInProgress`, `Event::IntakeCompleted`)
     * @param string $teleformId the ID of the teleform definition being updated
     * @param list<array{id: string, name: string, label: string, type: string, value: list<array<string, mixed>>}> $data
     *                           the full accumulated list of submitted form fields (same shape as `create()`);
     *                           replaces the stored data
     * @param array{page: int, total: int}|null $progress optional multi-page position; sent only when provided
     *
     * @return Response the updated intake-submission envelope
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no prior submission exists for this session / teleform combination
     * @throws \AsterMD\Sdk\Exception\ValidationException if required fields are missing or malformed
     * @throws \AsterMD\Sdk\Exception\ApiException        on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException  on a network-level failure
     */
    public function update(string $session, Event $event, string $teleformId, array $data, ?array $progress = null): Response
    {
        $body = [
            'session' => $session,
            'event' => $event->value,
            'teleform_id' => $teleformId,
            'data' => $data,
        ];

        if ($progress !== null) {
            $body['progress'] = $progress;
        }

        return $this->transport->send(
            'sales',
            'PUT',
            '/intake-submissions/update/{session}',
            pathParams: ['session' => $session],
            body: $body,
        );
    }
    /** Smallest part size the multipart flow accepts, in bytes — the server rejects a non-final part below 5 MB. */
    public const MIN_PART_SIZE = 5 * 1024 * 1024;

    /** Largest part size the multipart flow accepts, in bytes — the server rejects a part above 25 MB. */
    public const MAX_PART_SIZE = 25 * 1024 * 1024;

    /** Part size {@see self::uploadLargeFile()} uses when the caller does not choose one, in bytes. */
    public const DEFAULT_PART_SIZE = 10 * 1024 * 1024;

    /**
     * Uploads one file for a `file`-type answer in a single request.
     *
     * Call this before `create()` or `update()`, once per file the prospect attached.
     * The file lands in the submission's private storage folder and nothing on the
     * submission itself is touched — attaching the result is a separate step. Take
     * the `{path, name, mime_type}` object from `$response->data()` and put it on the
     * matching entry of the field's `value` list in the `$data` you then pass to
     * `create()` or `update()`. A field may accept several files; each upload gets a
     * unique stored name, so repeated calls against the same session accumulate
     * rather than overwrite.
     *
     * The server caps a single-shot upload at 10 MB and accepts JPEG, PNG, WebP, GIF,
     * PDF, DOC, DOCX, and plain text; anything larger or of another type is rejected
     * before a byte reaches storage. Video and other large formats go through the
     * multipart flow instead — see {@see self::uploadLargeFile()}.
     *
     * The uploaded bytes are PHI. The SDK never logs them, even with `debug: true`.
     *
     * @param string     $sessionId the session UUID this file belongs to — the same value passed
     *                              as `session` to `create()` and `update()`
     * @param FileUpload $file      the file to upload; build it with `FileUpload::fromPath()` for a
     *                              file on disk or `FileUpload::fromContents()` for bytes in memory
     *
     * @return Response the upload result; `data()` contains `path`, `name`, and `mime_type` to
     *                  attach to the submission's answer
     *
     * @throws \AsterMD\Sdk\Exception\ApiException       if the file exceeds 10 MB, carries a disallowed
     *                                                  MIME type, or the server rejects the request
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function uploadFile(string $sessionId, FileUpload $file): Response
    {
        return $this->transport->send(
            'sales',
            'POST',
            '/intake-submissions/upload-file/{session_id}',
            pathParams: ['session_id' => $sessionId],
            file: $file,
        );
    }

    /**
     * Opens a multipart upload for a file too large for the single-shot endpoint.
     *
     * This is step one of four: initiate, then one `uploadMultipartPart()` call per
     * chunk, then `finishMultipartUpload()` with every part's ETag — or
     * `abortMultipartUpload()` to walk away. Nothing exists in storage until the
     * upload is finished; until then it is only an open multipart upload. Take the
     * `upload_id` from `$response->data()` and pass it to every subsequent call.
     *
     * `$fileSize` is checked here, before any bytes are sent, against the server's
     * 200 MB cap — so a file that is too large costs one cheap round trip rather than
     * a long upload. `$mimeType` must be `video/mp4`, `video/quicktime`, or
     * `video/webm`.
     *
     * Most callers want {@see self::uploadLargeFile()}, which drives this whole flow.
     * Use these four methods directly when you need to upload parts in parallel or
     * resume across processes.
     *
     * @param string $sessionId the session UUID this file belongs to — the same value passed
     *                          as `session` to `create()` and `update()`
     * @param string $fileName  original file name; the server derives the stored name from it
     *                          by inserting a UUID before the extension
     * @param string $mimeType  MIME type of the whole file; must be one the multipart flow allows
     * @param int    $fileSize  total size of the file in bytes, checked against the 200 MB cap
     *
     * @return Response the initiated upload; `data()` contains `upload_id`
     *
     * @throws \AsterMD\Sdk\Exception\ApiException       if the MIME type is disallowed, the size exceeds
     *                                                  200 MB, or the server rejects the request
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function initiateMultipartUpload(string $sessionId, string $fileName, string $mimeType, int $fileSize): Response
    {
        return $this->transport->send(
            'sales',
            'POST',
            '/intake-submissions/upload-file-multipart/initiate/{session_id}',
            pathParams: ['session_id' => $sessionId],
            body: [
                'file_name' => $fileName,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
            ],
        );
    }

    /**
     * Uploads one part's bytes into an open multipart upload.
     *
     * Step two of the multipart flow, called once per chunk. Parts are independent:
     * they may be sent in any order and in parallel. The server keeps no record of
     * which parts have arrived, so you must collect the `{part_number, etag}` object
     * from every call's `$response->data()` and hand the complete set to
     * `finishMultipartUpload()`. Pass each ETag back exactly as received, surrounding
     * quote characters included.
     *
     * `$partNumber` is yours to assign and must be unique within the upload; the
     * server allows 1 through 10000, which this method enforces locally. A part may not
     * exceed 25 MB, and every part except the final one must be at least 5 MB — the
     * latter is only discovered at `finishMultipartUpload()`, since part size alone
     * does not reveal which part is last.
     *
     * The uploaded bytes are PHI. The SDK never logs them, even with `debug: true`.
     *
     * @param string     $uploadId   the `upload_id` returned by `initiateMultipartUpload()`
     * @param int        $partNumber 1-indexed position of this part within the file, 1–10000
     * @param FileUpload $part       this part's bytes; build it with `FileUpload::fromContents()`
     *
     * @return Response the stored part; `data()` contains `part_number` and `etag` — keep both
     *
     * @throws \InvalidArgumentException                if `$partNumber` falls outside 1–10000
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if the upload does not exist or has already finished
     * @throws \AsterMD\Sdk\Exception\ApiException       if the part exceeds 25 MB or the server rejects the request
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function uploadMultipartPart(string $uploadId, int $partNumber, FileUpload $part): Response
    {
        if ($partNumber < 1 || $partNumber > 10000) {
            throw new \InvalidArgumentException("Part number must be between 1 and 10000, got {$partNumber}.");
        }

        return $this->transport->send(
            'sales',
            'POST',
            '/intake-submissions/upload-file-multipart/part/{upload_id}',
            pathParams: ['upload_id' => $uploadId],
            query: ['part_number' => $partNumber],
            file: $part,
        );
    }

    /**
     * Assembles the uploaded parts into the finished file.
     *
     * Step three of the multipart flow. Send the full set of `{part_number, etag}`
     * pairs collected from `uploadMultipartPart()`, in any order — the server sorts
     * them before completing. The result has the same `{path, name, mime_type}` shape
     * the single-shot endpoint returns, so it is attached to the submission's answer
     * identically.
     *
     * If a part is missing, or a non-final part is under the 5 MB minimum, the
     * completion is rejected and nothing is left partially written; the upload stays
     * open, so you can retry with a corrected list or call
     * `abortMultipartUpload()`.
     *
     * @param string $uploadId the `upload_id` returned by `initiateMultipartUpload()`
     * @param list<array{part_number: int, etag: string}> $parts every part's number and its ETag
     *                         exactly as returned, quote characters included
     *
     * @return Response the completed upload; `data()` contains `path`, `name`, and `mime_type` to
     *                  attach to the submission's answer
     *
     * @throws \InvalidArgumentException                 if `$parts` is empty
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if the upload does not exist or has already finished
     * @throws \AsterMD\Sdk\Exception\ApiException       if the server could not assemble the parts
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function finishMultipartUpload(string $uploadId, array $parts): Response
    {
        if ($parts === []) {
            throw new \InvalidArgumentException('At least one part is required to finish a multipart upload.');
        }

        return $this->transport->send(
            'sales',
            'POST',
            '/intake-submissions/upload-file-multipart/finish/{upload_id}',
            pathParams: ['upload_id' => $uploadId],
            body: ['parts' => $parts],
        );
    }

    /**
     * Cancels an open multipart upload and discards the parts already sent.
     *
     * Call this whenever a multipart upload will not be finished — the prospect
     * abandoned the form, a part failed irrecoverably, or the file turned out to be
     * wrong. Leaving an upload open leaves its parts sitting in storage, so abort
     * rather than walk away. The call is idempotent: aborting twice, or aborting an
     * upload that already finished or was already aborted, still succeeds.
     *
     * {@see self::uploadLargeFile()} calls this for you when a step fails.
     *
     * @param string $uploadId the `upload_id` returned by `initiateMultipartUpload()`
     *
     * @return Response the abort acknowledgement; `data()` contains `aborted`
     *
     * @throws \AsterMD\Sdk\Exception\ApiException       if the server rejects the request
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function abortMultipartUpload(string $uploadId): Response
    {
        return $this->transport->send(
            'sales',
            'POST',
            '/intake-submissions/upload-file-multipart/abort/{upload_id}',
            pathParams: ['upload_id' => $uploadId],
        );
    }

    /**
     * Uploads a large file end to end, driving the whole multipart flow.
     *
     * This is the method to reach for when a `file`-type answer holds something the
     * single-shot {@see self::uploadFile()} will not take — a consultation video, for
     * instance. It initiates the upload, walks the file from disk one part at a time,
     * collects each part's ETag, and finishes the upload, returning the same
     * `{path, name, mime_type}` result you attach to the submission's answer. Only
     * one part is ever held in memory, so a 200 MB file costs `$partSize` bytes, not
     * 200 MB.
     *
     * If any step fails the upload is aborted before the original exception is
     * rethrown, so a failure never leaves orphaned parts behind. A failure to abort
     * is swallowed — the error you get back is always the one that actually broke the
     * upload.
     *
     * Parts are sent sequentially. When you need them in parallel, or need to resume
     * an upload in another process, drive {@see self::initiateMultipartUpload()},
     * {@see self::uploadMultipartPart()}, and {@see self::finishMultipartUpload()}
     * yourself.
     *
     * The uploaded bytes are PHI. The SDK never logs them, even with `debug: true`.
     *
     * @param string      $sessionId the session UUID this file belongs to — the same value passed
     *                               as `session` to `create()` and `update()`
     * @param string      $filePath  path to the file on local disk; must exist and be readable
     * @param string|null $fileName  name to send to the server; defaults to the path's base name
     * @param string|null $mimeType  MIME type of the file; defaults to a type derived from the file
     *                               name, and must be one the multipart flow allows
     * @param int         $partSize  bytes per part, between {@see self::MIN_PART_SIZE} and
     *                               {@see self::MAX_PART_SIZE}; the last part may be smaller
     *
     * @return Response the completed upload; `data()` contains `path`, `name`, and `mime_type` to
     *                  attach to the submission's answer
     *
     * @throws \InvalidArgumentException                 if `$filePath` is unreadable or empty, or
     *                                                   `$partSize` falls outside the allowed range
     * @throws \AsterMD\Sdk\Exception\ApiException       if the server rejects the file, a part, or the
     *                                                   completion — the upload is aborted first
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function uploadLargeFile(
        string $sessionId,
        string $filePath,
        ?string $fileName = null,
        ?string $mimeType = null,
        int $partSize = self::DEFAULT_PART_SIZE,
    ): Response {
        if ($partSize < self::MIN_PART_SIZE || $partSize > self::MAX_PART_SIZE) {
            throw new \InvalidArgumentException(sprintf(
                'Part size must be between %d and %d bytes, got %d.',
                self::MIN_PART_SIZE,
                self::MAX_PART_SIZE,
                $partSize,
            ));
        }

        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("File is not readable: {$filePath}");
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize === 0) {
            throw new \InvalidArgumentException("File is empty: {$filePath}");
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException("Failed to open file: {$filePath}");
        }

        try {
            $chunk = fread($handle, $partSize);
            if ($chunk === false || $chunk === '') {
                throw new \InvalidArgumentException("File is empty: {$filePath}");
            }

            // The first part fixes the name and MIME type for the whole upload, so
            // initiate agrees with every part that follows it.
            $part = FileUpload::fromContents($chunk, $fileName ?? basename($filePath), $mimeType);
            $uploadId = $this->stringField(
                $this->initiateMultipartUpload($sessionId, $part->fileName(), $part->mimeType(), $fileSize),
                'upload_id',
            );

            try {
                $parts = [];
                $partNumber = 1;

                while (true) {
                    $result = $this->uploadMultipartPart($uploadId, $partNumber, $part);
                    $parts[] = [
                        'part_number' => $partNumber,
                        'etag' => $this->stringField($result, 'etag'),
                    ];

                    $chunk = fread($handle, $partSize);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    ++$partNumber;
                    $part = FileUpload::fromContents($chunk, $part->fileName(), $part->mimeType());
                }

                return $this->finishMultipartUpload($uploadId, $parts);
            } catch (\Throwable $e) {
                try {
                    $this->abortMultipartUpload($uploadId);
                } catch (\Throwable) {
                    // Surface the failure that broke the upload, not the cleanup's.
                }

                throw $e;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Reads a required string out of a success envelope.
     *
     * The multipart flow threads the server's own identifiers back into the next
     * call, so a missing one has to stop the upload here rather than surface later
     * as an opaque rejection from `finish`.
     *
     * @throws ApiException if the field is absent or not a non-empty string
     */
    private function stringField(Response $response, string $key): string
    {
        $value = $response->data()[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new ApiException(
                "The server response did not include a usable {$key}.",
                $response->statusCode(),
                [],
            );
        }

        return $value;
    }
}
