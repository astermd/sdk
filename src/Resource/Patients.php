<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Response;

/**
 * Patient (customer) record operations on the `sales` service.
 *
 * A patient is the persistent identity anchor for an end-user across the order
 * flow. Records are created after the prospect clears eligibility checks and
 * their contact details are known. The full required fields are defined in the
 * sales `PatientCreateRequest` schema (`first_name`, `last_name`, `email`,
 * `phone_number`, `addresses`); partial fields may be accepted on update.
 *
 * PHI (protected health information) submission and OTP verification are
 * separate, sequential steps that follow record creation. Never log request bodies
 * for methods on this resource — they routinely carry PII and PHI.
 */
final class Patients extends AbstractResource
{
    /**
     * Creates a new patient record on the sales service.
     *
     * Call this after the prospect has passed eligibility screening and you are
     * ready to persist their identity. The shape of `$data` follows the sales
     * `PatientCreateRequest` schema; see the API reference in your AsterMD
     * dashboard under `/patients/create` for the authoritative field list.
     * Required fields are `first_name`, `last_name`, `email`, `phone_number`, and
     * `addresses` (at least one address object). Inspect `$response->data()` for
     * the new patient ID.
     *
     * @param array<string, mixed> $data patient fields per the `PatientCreateRequest` schema.
     *                                   Known top-level keys:
     *                                   `first_name` (string, required),
     *                                   `last_name` (string, required),
     *                                   `email` (string email, required),
     *                                   `phone_number` (string digits-only, required),
     *                                   `addresses` (list of address objects, required — each with
     *                                     `address_line1`, `country`, `state`, `city`, `zip`),
     *                                   `middle_name` (string, optional),
     *                                   `email_accepted` (bool, default false),
     *                                   `status` (int: 0=INACTIVE 1=ACTIVE 2=DRAFT 3=PENDING 5=LOCKED, default 1)
     *
     * @return Response the created patient envelope; `data()` contains the new patient record including its ID
     *
     * @throws \AsterMD\Sdk\Exception\ValidationException if required fields are missing or malformed
     * @throws \AsterMD\Sdk\Exception\ApiException        on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException  on a network-level failure
     */
    public function create(array $data): Response
    {
        return $this->transport->send('sales', 'POST', '/patients/create', body: $data);
    }

    /**
     * Fetches a patient record by its ID.
     *
     * Use this to retrieve full patient details for display or downstream processing
     * (e.g. before creating an opportunity or treatment linked to this patient).
     * Inspect `$response->data()` for the patient's fields.
     *
     * @param string $id the ID of the patient record
     *
     * @return Response the patient envelope; `data()` contains the full patient record
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no patient exists for the given ID
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function view(string $id): Response
    {
        return $this->transport->send('sales', 'GET', '/patients/view/{id}', pathParams: ['id' => $id]);
    }

    /**
     * Updates mutable fields on an existing patient record.
     *
     * Partial updates are accepted — only the fields present in `$data` will be
     * changed; other fields are left intact. Use the same field names as
     * `create()`. Commonly used to update contact details or address information
     * after the initial record is created.
     *
     * @param string               $id   the ID of the patient to update
     * @param array<string, mixed> $data fields to update; accepts the same keys as `create()` (partial update)
     *
     * @return Response the updated patient envelope
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no patient exists for the given ID
     * @throws \AsterMD\Sdk\Exception\ValidationException if the supplied fields are malformed
     * @throws \AsterMD\Sdk\Exception\ApiException        on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException  on a network-level failure
     */
    public function update(string $id, array $data): Response
    {
        return $this->transport->send(
            'sales',
            'PUT',
            '/patients/update/{id}',
            pathParams: ['id' => $id],
            body: $data,
        );
    }

    /**
     * Updates the lifecycle status of a patient record.
     *
     * Sends `{"status": "$status"}` as the request body. The status values
     * correspond to: `0`=INACTIVE, `1`=ACTIVE, `2`=DRAFT, `3`=PENDING,
     * `5`=LOCKED. Use integer string representations or the numeric values as
     * the server accepts either per the sales API schema.
     *
     * @param string $id     the ID of the patient
     * @param string $status the new lifecycle status value to set
     *
     * @return Response the updated status envelope
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no patient exists for the given ID
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function status(string $id, string $status): Response
    {
        return $this->transport->send(
            'sales',
            'PATCH',
            '/patients/status/{id}',
            pathParams: ['id' => $id],
            body: ['status' => $status],
        );
    }

    /**
     * Submits protected health information (PHI) for a patient.
     *
     * This endpoint carries sensitive health data. When `$phiVerificationToken`
     * is provided it is forwarded as the `x-phi-verification-token` request header
     * to confirm the caller has completed the PHI verification flow. Omit it
     * for non-verified submissions. Never log request bodies for this endpoint —
     * they contain PHI fields that must not appear in logs. The shape of `$data`
     * follows the sales health-information schema; see the API reference in your
     * AsterMD dashboard for the full field list.
     *
     * @param array<string, mixed> $data                 PHI payload per the sales health-information schema
     * @param string|null          $phiVerificationToken optional PHI verification token; forwarded as
     *                                                   `x-phi-verification-token` header when present
     *
     * @return Response the PHI submission confirmation envelope
     *
     * @throws \AsterMD\Sdk\Exception\ValidationException if required PHI fields are missing or malformed
     * @throws \AsterMD\Sdk\Exception\ApiException        on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException  on a network-level failure
     */
    public function submitHealthInformation(array $data, ?string $phiVerificationToken = null): Response
    {
        $headers = $phiVerificationToken !== null
            ? ['x-phi-verification-token' => $phiVerificationToken]
            : [];

        return $this->transport->send(
            'sales',
            'POST',
            '/patients/health-information',
            body: $data,
            headers: $headers,
        );
    }

    /**
     * Verifies the one-time password (OTP) issued during the PHI submission flow.
     *
     * After calling `submitHealthInformation()`, the server dispatches an OTP to
     * the patient out-of-band (e.g. via SMS or email). Pass the received code in
     * `$data` to complete verification. The exact field name for the OTP code
     * follows the sales schema; see the API reference in your AsterMD dashboard
     * for the current field list.
     * Never log request bodies for this call — they carry the OTP code.
     *
     * @param array<string, mixed> $data OTP verification payload; must include the OTP code delivered out-of-band
     *
     * @return Response the verification confirmation envelope
     *
     * @throws \AsterMD\Sdk\Exception\ValidationException if the OTP is missing, malformed, or expired
     * @throws \AsterMD\Sdk\Exception\ApiException        on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException  on a network-level failure
     */
    public function verifyHealthInformationOtp(array $data): Response
    {
        return $this->transport->send(
            'sales',
            'POST',
            '/patients/health-information/otp-verification',
            body: $data,
        );
    }
}
