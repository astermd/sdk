<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Response;

/**
 * IP-based geolocation and threat-blocklist lookups on the `platform` service.
 *
 * Both methods take a visitor's public IP address — the same value you pass to
 * `sessions()->create()` — and answer a different question about it. `info()` tells
 * you where the visitor appears to be, which is useful for pre-filling a country or
 * state field, choosing a currency, or deciding whether a prospect falls inside a
 * region you are licensed to serve. `blocklist()` tells you whether the IP carries a
 * known reputation problem, so you can add friction to a suspect checkout.
 *
 * Both require the geo integration to be active for your organization, and both
 * reject private and reserved IP ranges — guard against sending a loopback or LAN
 * address from a local development environment or from behind a proxy that has not
 * been configured to forward the client IP.
 *
 * ```php
 * $ip = $_SERVER['REMOTE_ADDR'];
 *
 * $geo = $client->geo()->info($ip)->data();
 * $prefillState = $geo['region-code'] ?? null;   // e.g. 'CA'
 *
 * if ($client->geo()->blocklist($ip)->data()['is-listed'] === true) {
 *     // step up verification before accepting the order
 * }
 * ```
 *
 * @see \AsterMD\Sdk\Resource\Verification for address, email, and identity checks on the same service
 */
class Geo extends AbstractResource
{
    /**
     * Looks up geolocation data for a public IP address.
     *
     * Call this early in a session to localise the storefront — pre-filling country
     * and state, selecting a currency, or short-circuiting a flow for a region you do
     * not serve. `$response->data()` carries a `valid` flag alongside `city`,
     * `country`, `country-code`, `region`, `region-code`, `currency-code`,
     * `calling-code`, `latitude`, `longitude`, and a `timezone` object. Note the
     * hyphenated key names — they are passed through from the provider as-is. Treat
     * the result as a hint rather than a fact: VPNs and mobile carriers routinely
     * place a visitor far from their real location.
     *
     * @param string $ip public IPv4 or IPv6 address to look up; private and reserved ranges are rejected
     *
     * @return Response the geolocation envelope; `data()` contains the location record
     *
     * @throws \AsterMD\Sdk\Exception\ApiException       if `$ip` is malformed or is a private address
     *                                                   (HTTP 400), if the geo integration is inactive for your
     *                                                   organization (HTTP 403), or on any other non-2xx response
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function info(string $ip): Response
    {
        return $this->transport->send(
            'platform',
            'GET',
            '/extensions/geo-info',
            query: ['ip' => $ip],
        );
    }

    /**
     * Checks a public IP address against known threat blocklists.
     *
     * Call this at a decision point where a bad actor is costly — before accepting an
     * order, or before granting access to an intake form — and use the outcome to add
     * friction rather than to reject outright. `$response->data()['is-listed']` is the
     * summary verdict; the accompanying `is-proxy`, `is-tor`, `is-vpn`, `is-malware`,
     * `is-spyware`, `is-bot`, `is-hijacked`, and `is-spider` flags say why, and
     * `blocklists` and `sensors` name the sources that matched. Note the hyphenated
     * key names — they are passed through from the provider as-is.
     *
     * @param string $ip public IPv4 or IPv6 address to check; private and reserved ranges are rejected
     *
     * @return Response the blocklist envelope; `data()` contains the membership and threat flags
     *
     * @throws \AsterMD\Sdk\Exception\ApiException       if `$ip` is malformed or is a private address
     *                                                   (HTTP 400), if the geo integration is inactive for your
     *                                                   organization (HTTP 403), or on any other non-2xx response
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function blocklist(string $ip): Response
    {
        return $this->transport->send(
            'platform',
            'GET',
            '/extensions/geo-blocklist',
            query: ['ip' => $ip],
        );
    }
}
