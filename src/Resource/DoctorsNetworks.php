<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Response;
use InvalidArgumentException;

/**
 * Routes cases to the configured doctors' network integration on the `sales` service.
 *
 * AsterMD supports pluggable physician-network integrations. Calling `sync()`
 * creates or updates a case in the network configured for your organisation.
 * This is typically called after a treatment is created to initiate the clinical
 * review workflow.
 *
 * Either an `opportunity_id` or a `user_info` block (containing at least
 * `first_name` and `email`) must be present in the payload. A client-side
 * validation guard throws `\InvalidArgumentException` before making any HTTP call
 * if neither is supplied. The deprecated `emit_opportunity` key is silently stripped
 * from the payload before transmission.
 */
final class DoctorsNetworks extends AbstractResource
{
    /**
     * Creates or updates a case in the configured doctors' network integration.
     *
     * Call this after a treatment record has been created to route the case to
     * the physician network for clinical review. Supply either `opportunity_id`
     * (preferred — links the case to an existing CRM lead) or a `user_info` block
     * with at least `first_name` and `email` for anonymous submissions. The
     * deprecated `emit_opportunity` key is stripped client-side before the request
     * is sent. Inspect `$response->data()` for the network case reference.
     *
     * @param array<string, mixed> $payload sync request fields.
     *                                      Known top-level keys:
     *                                      `session_id` (string UUID, optional — links the case to a session),
     *                                      `network` (string, optional — target network identifier),
     *                                      `opportunity_id` (string ID, required unless `user_info` present),
     *                                      `user_info` (array with at least `first_name` string and `email` string,
     *                                        required unless `opportunity_id` present),
     *                                      `products` (list of product references, optional)
     *
     * @return Response the doctors'-network case envelope; `data()` contains the case reference
     *
     * @throws \InvalidArgumentException                  if neither `opportunity_id` nor `user_info` with `first_name` and `email` is present
     * @throws \AsterMD\Sdk\Exception\ValidationException if the server rejects the payload as malformed
     * @throws \AsterMD\Sdk\Exception\ApiException        on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException  on a network-level failure
     */
    public function sync(array $payload): Response
    {
        unset($payload['emit_opportunity']);

        $this->validate($payload);

        return $this->transport->send('sales', 'POST', '/doctors-networks/sync', body: $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validate(array $payload): void
    {
        $hasOpportunity = isset($payload['opportunity_id'])
            && is_string($payload['opportunity_id'])
            && $payload['opportunity_id'] !== '';

        $userInfo = $payload['user_info'] ?? null;
        $hasMinimalUserInfo = is_array($userInfo)
            && isset($userInfo['first_name'], $userInfo['email'])
            && is_string($userInfo['first_name']) && $userInfo['first_name'] !== ''
            && is_string($userInfo['email']) && $userInfo['email'] !== '';

        if (!$hasOpportunity && !$hasMinimalUserInfo) {
            throw new InvalidArgumentException(
                'doctors-networks/sync requires either opportunity_id or user_info with first_name + email.',
            );
        }
    }
}
