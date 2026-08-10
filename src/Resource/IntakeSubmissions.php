<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Enum\Event;
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
}
