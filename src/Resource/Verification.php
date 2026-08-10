<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Enum\IdentityCheck;
use AsterMD\Sdk\Response;

/**
 * Address, email, and identity checks run against third-party providers on the `platform` service.
 *
 * These endpoints let your storefront validate what a prospect types before it
 * becomes an order. They sit in front of the order flow rather than inside it: use
 * them while a form is still on screen, so a bad address, an undeliverable email, or
 * an identity that cannot be corroborated is caught before you call
 * `patients()->create()` and commit a prospect record.
 *
 * Each method proxies a provider your organization has configured in the AsterMD
 * dashboard. If the relevant integration is missing or inactive the server rejects
 * the call — see the throws notes on each method. Address validation, email
 * verification, and identity results are cached server-side, so repeating an
 * identical check is cheap and does not re-bill the provider; identity results
 * report this via a `cached` flag.
 *
 * A typical address field wires both address methods together — `autofillAddress()`
 * as the prospect types, then `verifyAddress()` once on the chosen line:
 *
 * ```php
 * // As the prospect types, for a suggestion dropdown.
 * $suggestions = $client->verification()->autofillAddress('123 Main St, San')->data();
 *
 * // Once they pick or finish a line, confirm it is real and deliverable.
 * $check = $client->verification()->verifyAddress('123 Main St, San Francisco, CA 94105')->data();
 * if ($check['valid'] === true) {
 *     $normalised = $check['formatted_address'];
 * }
 * ```
 *
 * @see \AsterMD\Sdk\Resource\Geo for IP-based geolocation and blocklist checks on the same service
 */
class Verification extends AbstractResource
{
    /**
     * Fetches address suggestions for a partial address string.
     *
     * Call this as the prospect types into an address field to drive a suggestion
     * dropdown. Returns up to five suggestions, biased toward the requester's
     * approximate location when one can be determined. Each item in
     * `$response->data()` carries a `unique_id` place reference, the full formatted
     * `address`, and the `main_text` street portion — keep the `unique_id` if you
     * intend to resolve structured components later. Read-only; nothing is stored
     * against the prospect.
     *
     * @param string $search partial address text to autocomplete, e.g. `123 Main St, San`
     *
     * @return Response the suggestions envelope; `data()` contains a list of
     *                  `{unique_id, address, main_text}` items, empty when nothing matched
     *
     * @throws \AsterMD\Sdk\Exception\ApiException       if `$search` is rejected as invalid (HTTP 400),
     *                                                   if the address integration is inactive for your
     *                                                   organization (HTTP 403), or on any other non-2xx response
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function autofillAddress(string $search): Response
    {
        return $this->transport->send(
            'platform',
            'GET',
            '/extensions/address-autofill',
            query: ['search' => $search],
        );
    }

    /**
     * Validates a full address string and returns its normalised form.
     *
     * Call this once the prospect has committed to an address — typically on blur or
     * at checkout — to confirm it is a real, deliverable location before you create a
     * patient or a treatment. `$response->data()` carries a `valid` flag, the
     * provider-normalised `formatted_address` you should store in place of the raw
     * input, a `verdict` reporting how precisely the address resolved
     * (`validationGranularity`, `addressComplete`), and geocoded `location`
     * coordinates when available. Results are cached server-side, so re-validating
     * the same address does not re-bill the provider.
     *
     * @param string $address full address string to validate, e.g. `123 Main St, San Francisco, CA 94105`
     *
     * @return Response the validation envelope; `data()` contains
     *                  `{valid, formatted_address, verdict, location}`
     *
     * @throws \AsterMD\Sdk\Exception\ApiException       if `$address` is rejected as invalid (HTTP 400),
     *                                                   if the address integration is inactive for your
     *                                                   organization (HTTP 403), or on any other non-2xx response
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function verifyAddress(string $address): Response
    {
        return $this->transport->send(
            'platform',
            'GET',
            '/extensions/address-verify',
            query: ['address' => $address],
        );
    }

    /**
     * Checks whether an email address is deliverable.
     *
     * Call this before creating a prospect so that order confirmations and intake
     * links do not bounce. `$response->data()['result']` is `valid` (deliverable),
     * `invalid` (undeliverable), or `unknown` — treat `unknown` as inconclusive
     * rather than as a failure, since it also covers the provider being
     * rate-limited, and let the prospect proceed. When the result is decisive,
     * `reason` and `code` explain it; both are absent for `unknown`. Results are
     * cached server-side for an extended period, so repeat checks of the same
     * address are served without calling the provider.
     *
     * @param string $email email address to verify
     *
     * @return Response the verification envelope; `data()` contains `{result}` plus
     *                  `{reason, code}` when the result is not `unknown`
     *
     * @throws \AsterMD\Sdk\Exception\ApiException       if `$email` is rejected as invalid (HTTP 400),
     *                                                   if the email-verification integration is inactive for
     *                                                   your organization (HTTP 403), or on any other non-2xx response
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function verifyEmail(string $email): Response
    {
        return $this->transport->send(
            'platform',
            'GET',
            '/extensions/email-verify',
            query: ['email' => $email],
        );
    }

    /**
     * Runs an identity check against the configured identity provider and returns a normalised verdict.
     *
     * Use this during pre-qualifying, before a prospect becomes a patient, to
     * corroborate the identity behind a form submission. `$check` selects which
     * check runs and therefore which fields `$data` must carry — see
     * {@see IdentityCheck} for the per-check requirements. The `slug` is set from
     * `$check` and always wins, so a `slug` key in `$data` is ignored.
     *
     * `$response->data()` is provider-agnostic apart from `raw`: inspect `valid` for
     * the verdict (`null` when the provider answered but returned nothing decisive),
     * `basis` for which rule decided it, `score` and `threshold` for scored checks,
     * `reasons` for coded explanatory signals, `cached` to see whether the provider
     * was actually called, and `checkedAt` for when it was. Successful results are
     * cached server-side; a cache hit is not metered against your extension usage.
     * Nothing is persisted against a patient record — the verdict is yours to act on.
     *
     * This request carries personally identifying information, including a Social
     * Security Number for {@see IdentityCheck::SsnVerify}. Its body is never written
     * to debug logs.
     *
     * @param IdentityCheck        $check the identity check to run; sets the request's `slug`
     * @param array<string, mixed> $data  the identity to check. Recognised top-level keys are
     *                                    `firstName`, `lastName`, `email`, `phone`, `dob` (`YYYY-MM-DD`),
     *                                    `ssn` (last 4 or full 9 digits; separators are stripped),
     *                                    `ipAddress`, and `address` — itself an array of `unit`,
     *                                    `streetAddress`, `city`, `state`, `postalCode`, and `country`.
     *                                    Phone numbers are normalised before the provider is called, so
     *                                    `555-000-1234` and `+15550001234` are equivalent, and a bare
     *                                    ten-digit number is read as US/CA. Which keys are required
     *                                    depends on `$check`; see {@see IdentityCheck}. A `slug` key is
     *                                    ignored. Consult the API reference in your AsterMD dashboard
     *                                    for the full field list.
     *
     * @return Response the verification envelope; `data()` contains
     *                  `{provider, check, valid, basis, score, threshold, reasons, cached, checkedAt, raw}`
     *
     * @throws \AsterMD\Sdk\Exception\ApiException       if the payload is missing fields the chosen check
     *                                                   requires (HTTP 400), if the identity integration is
     *                                                   inactive for your organization (HTTP 403), if the
     *                                                   provider itself failed, or on any other non-2xx response
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function verifyIdentity(IdentityCheck $check, array $data): Response
    {
        return $this->transport->send(
            'platform',
            'POST',
            '/extensions/identity-verify',
            body: ['slug' => $check->value] + $data,
        );
    }
}
