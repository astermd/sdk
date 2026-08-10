<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Response;

/**
 * Session lifecycle operations on the `sales` service.
 *
 * A session is the first entity created in every order flow. It carries a
 * server-generated UUID that must be threaded through all subsequent calls:
 * intake submissions, cart operations, and the final treatment record all
 * reference a session UUID to enable server-side journey attribution. Create a
 * session at the very beginning of a visitor's interaction and persist the UUID
 * in your frontend (e.g. localStorage or a cookie) for the duration of their visit.
 *
 * Creating a session triggers an implicit server-side `visit_page` event; no
 * additional event call is needed to record the page view.
 */
final class Sessions extends AbstractResource
{
    /**
     * Creates a new tracking session and records an implicit `visit_page` event on the server.
     *
     * The server auto-generates a UUID for the session; you cannot supply one.
     * An empty JSON object `{}` is always sent as the request body, even when
     * `$data` is `null` or `[]`, because the AsterMD API requires an object-shaped
     * body and returns HTTP 500 when no body is provided. Inspect
     * `$response->data()['session']` for the new session UUID.
     *
     * All `$data` fields are optional. Known keys (per the sales API
     * `SessionCreateRequest`): `channel_id` (string — normally derived from the API
     * credential, so rarely needed), `referrer` (string), `utm`
     * (`{source, medium, campaign}`), and `custom_params` (arbitrary object). The
     * server also enriches the stored record with `ip`, `geo`, and browser/device
     * fields derived from the request; the response `data()` returns that enriched
     * read-model. Any additional keys you pass are forwarded unmodified.
     *
     * Pass `$userAgent` to attribute the session to the visitor's browser and
     * `$clientIp` to attribute it to the visitor's IP: both are forwarded verbatim
     * as request headers (`User-Agent` and `X-Original-Client-Ip`) so the server
     * derives device and geo/IP from the end user rather than from this
     * server-side PHP client. Because the SDK runs server-to-server, only the
     * consuming application knows the real values — read them from
     * `$_SERVER['HTTP_USER_AGENT']` and the visitor's IP (e.g.
     * `$_SERVER['REMOTE_ADDR']`, or the left-most `X-Forwarded-For` entry behind a
     * proxy). Each header is omitted when its argument is `null` or empty.
     *
     * @param array<string, mixed>|null $data      optional extra fields to include in the creation body;
     *                                              pass `null` or omit to send an empty object `{}`
     * @param string|null               $userAgent the visitor's browser User-Agent to forward as the
     *                                              `User-Agent` header (e.g. `$_SERVER['HTTP_USER_AGENT']`);
     *                                              `null` or empty omits the header
     * @param string|null               $clientIp  the visitor's IP address to forward as the
     *                                              `X-Original-Client-Ip` header (e.g. `$_SERVER['REMOTE_ADDR']`);
     *                                              `null` or empty omits the header
     *
     * @return Response the created session envelope; `data()['session']` contains the new session UUID
     *
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function create(?array $data = null, ?string $userAgent = null, ?string $clientIp = null): Response
    {
        $headers = [];

        if ($userAgent !== null && $userAgent !== '') {
            $headers['User-Agent'] = $userAgent;
        }

        if ($clientIp !== null && $clientIp !== '') {
            $headers['X-Original-Client-Ip'] = $clientIp;
        }

        return $this->transport->send('sales', 'POST', '/sessions/create', body: $data ?? [], headers: $headers);
    }

    /**
     * Fetches one or more sessions by their UUIDs in a single round-trip.
     *
     * The server route is `GET /sessions/view?session_ids=uuid1,uuid2` — not the
     * REST-style `/sessions/view/{id}` implied by the spec. Pass an array of UUIDs
     * to batch-fetch sessions. The response `data()` is keyed by session UUID; each
     * entry carries an aggregated `data` read-model and an `events` timeline.
     *
     * Pass `$tz` (an IANA timezone, e.g. `Asia/Kolkata`) to have the server format
     * all timestamps in that zone; when omitted, timestamps are returned in UTC.
     *
     * @param list<string> $sessions one or more session UUIDs to retrieve
     * @param string|null  $tz       optional IANA timezone applied to response timestamps (e.g. `Asia/Kolkata`);
     *                               omit for UTC
     *
     * @return Response the session data envelope; `data()` is keyed by session UUID, each with `data` and `events`
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no sessions match the supplied UUIDs
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function view(array $sessions, ?string $tz = null): Response
    {
        $query = ['session_ids' => implode(',', $sessions)];

        if ($tz !== null && $tz !== '') {
            $query['tz'] = $tz;
        }

        return $this->transport->send('sales', 'GET', '/sessions/view', query: $query);
    }

    /**
     * Updates mutable fields on an existing session record.
     *
     * Sends a `PUT` request with `$data` as the JSON body. Use this to attach
     * additional context to a session after creation (e.g. UTM parameters resolved
     * client-side). Pass only the fields you want to change; the server performs a
     * merge rather than a full replacement.
     *
     * @param string               $session the UUID of the session to update
     * @param array<string, mixed> $data    fields to update on the session record; empty array sends `{}`
     *
     * @return Response the updated session envelope
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if the session UUID does not exist
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function update(string $session, array $data = []): Response
    {
        return $this->transport->send(
            'sales',
            'PUT',
            '/sessions/update/{id}',
            pathParams: ['id' => $session],
            body: $data,
        );
    }

    /**
     * Permanently removes a session record from the server.
     *
     * This is a destructive operation; deleted sessions cannot be recovered.
     * Typically used in test-suite teardown or to purge abandoned sessions. In
     * production order flows, sessions are generally retained for attribution
     * purposes.
     *
     * @param string $session the UUID of the session to delete
     *
     * @return Response confirmation envelope; `message()` describes the outcome
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if the session UUID does not exist
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function delete(string $session): Response
    {
        return $this->transport->send(
            'sales',
            'DELETE',
            '/sessions/delete/{id}',
            pathParams: ['id' => $session],
        );
    }
}
