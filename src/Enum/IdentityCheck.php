<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Enum;

/**
 * The identity check to run against the configured identity provider on the `platform` service.
 *
 * One case is passed to {@see \AsterMD\Sdk\Resource\Verification::verifyIdentity()} to
 * select which check the provider performs. The three checks answer different
 * questions and therefore require different fields on the accompanying payload:
 *
 *  - **{@see self::Crosscheck}** — corroborates that the supplied name, and any
 *    contact details given alongside it, belong to a real, consistent identity.
 *    Scored: the provider returns an identity confidence between 0 and 1, which the
 *    platform compares against the threshold configured on your integration.
 *  - **{@see self::DobVerify}** — asserts that a specific date of birth matches the
 *    records held for the identity. Returns a direct match verdict rather than a score.
 *  - **{@see self::SsnVerify}** — asserts that a Social Security Number matches the
 *    records held for the identity. Returns a direct match verdict, or a Customer
 *    Identification Program verdict when CIP scoring is enabled on your account.
 *
 * The result's `basis` field always reports which rule produced `valid`, so a change
 * in how the verdict was reached is visible rather than silent.
 *
 * ```php
 * $result = $client->verification()->verifyIdentity(
 *     IdentityCheck::DobVerify,
 *     [
 *         'firstName' => 'John',
 *         'lastName'  => 'Doe',
 *         'phone'     => '+15550001234',
 *         'dob'       => '1990-01-15',
 *     ],
 * )->data();
 *
 * if ($result['valid'] === false) {
 *     foreach ($result['reasons'] as $reason) {
 *         // e.g. ['code' => 'dob_mismatch', 'message' => '…']
 *     }
 * }
 * ```
 *
 * @see \AsterMD\Sdk\Resource\Verification::verifyIdentity()
 */
enum IdentityCheck: string
{
    /**
     * Identity crosscheck, scored against your integration's configured threshold.
     *
     * Requires `firstName` and `lastName`. Optionally accepts `email`, `phone`,
     * `ipAddress`, and `address` — each one supplied narrows the check and may add
     * explanatory reasons to the result. Any `dob` passed is ignored.
     */
    case Crosscheck = 'crosscheck';

    /**
     * Date-of-birth verification, returning a direct match verdict.
     *
     * Requires `firstName`, `lastName`, `phone`, and `dob`. Optionally accepts
     * `email` and `address`. An `address` given for this check must carry a
     * `country` of `US` or `CA`.
     */
    case DobVerify = 'dob_verify';

    /**
     * Social Security Number verification, returning a direct match or CIP verdict.
     *
     * Requires `firstName`, `lastName`, `phone`, and `ssn`. Optionally accepts
     * `email`, `dob`, and `address`.
     */
    case SsnVerify = 'ssn_verify';
}
