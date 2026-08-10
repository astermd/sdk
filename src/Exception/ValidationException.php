<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Exception;

/**
 * Thrown when the API returns a 422 Unprocessable Entity response.
 *
 * Indicates that the request payload failed server-side validation. The server
 * typically returns an `errors` object in the response envelope mapping field
 * names to their error message arrays (e.g. `{"email": ["The email is required."]}``).
 * These are extracted by {@see \AsterMD\Sdk\Http\Transport} and exposed via
 * `fieldErrors()` for per-field display in your UI. Inspect `envelope()` for
 * the full server response.
 */
final class ValidationException extends ApiException
{
    /**
     * @param string                      $message     the top-level error message from the response envelope
     * @param int                         $statusCode  the HTTP status code (422)
     * @param array<string, mixed>        $envelope    the decoded JSON response body
     * @param array<string, list<string>> $fieldErrors map of field name → list of validation error messages,
     *                                                  extracted from the `errors` key in the response envelope
     */
    public function __construct(
        string $message,
        int $statusCode,
        array $envelope,
        private readonly array $fieldErrors,
    ) {
        parent::__construct($message, $statusCode, $envelope);
    }

    /**
     * Returns per-field validation error messages extracted from the server's `errors` envelope key.
     *
     * Returns a map of field name (string) to a list of validation messages for
     * that field (e.g. `['email' => ['The email field is required.'], 'phone_number' => ['Must be numeric.']]`).
     * Returns an empty array when the server did not include an `errors` key or
     * when the `errors` structure could not be parsed.
     *
     * @return array<string, list<string>> field-name → validation messages map
     */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
