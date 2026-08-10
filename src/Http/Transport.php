<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Http;

use AsterMD\Sdk\Exception\ApiException;
use AsterMD\Sdk\Exception\AuthenticationException;
use AsterMD\Sdk\Exception\NotFoundException;
use AsterMD\Sdk\Exception\RateLimitException;
use AsterMD\Sdk\Exception\TransportException;
use AsterMD\Sdk\Exception\ValidationException;
use AsterMD\Sdk\Response;
use Closure;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Single HTTP chokepoint for all outbound API requests.
 *
 * Every resource method delegates to `send()`, which handles the full request
 * lifecycle: URL construction, bearer-token attachment, PSR-7 request building,
 * JSON serialisation of the request body, dispatch to the PSR-18 client, envelope
 * decoding, and status-code-to-exception mapping.
 *
 * The 401-retry protocol is built in: when the server returns 401, Transport
 * calls `$onUnauthorized` once (which triggers {@see TokenManager::refresh()}).
 * If `$onUnauthorized` returns `true`, the original request is replayed with the
 * refreshed token. A second 401 causes {@see AuthenticationException} to be thrown
 * without another retry.
 *
 * HTTP status mapping:
 * - 200–299 → {@see Response} (success envelope decoded)
 * - 401     → {@see AuthenticationException}
 * - 404     → {@see NotFoundException}
 * - 422     → {@see ValidationException} (field errors extracted from `errors` key)
 * - 429     → {@see RateLimitException} (`Retry-After` header parsed when present)
 * - other   → {@see ApiException}
 * - network failure → {@see TransportException}
 */
final class Transport
{
    /**
     * @param ClientInterface         $httpClient      PSR-18 client used for all outbound calls.
     * @param RequestFactoryInterface $requestFactory  PSR-17 factory for building requests.
     * @param StreamFactoryInterface  $streamFactory   PSR-17 factory for JSON body streams.
     * @param UrlBuilder              $urlBuilder      Constructs fully-qualified request URLs.
     * @param Closure(): ?string      $tokenProvider   Returns the current bearer token string, or `null`
     *                                                  for unauthenticated requests (used by `TokenManager`
     *                                                  internally to avoid recursion).
     * @param Closure(): bool         $onUnauthorized  Invoked exactly once when the server returns 401.
     *                                                  Should refresh the token and return `true` to signal
     *                                                  that a retry should be attempted, or `false` to skip
     *                                                  the retry and let the 401 surface as an exception.
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly UrlBuilder $urlBuilder,
        private readonly Closure $tokenProvider,
        private readonly Closure $onUnauthorized,
    ) {
    }

    /**
     * Dispatches an authenticated HTTP request and returns the decoded success envelope.
     *
     * Builds the request URL from `$service`, `$path`, `$pathParams`, and `$query`,
     * attaches the bearer token from the token provider, serialises `$body` as JSON
     * (coercing an empty array to `{}` since AsterMD endpoints expect an object),
     * and applies any extra `$headers`. On 401 the token is refreshed and the request
     * is replayed once before raising {@see AuthenticationException}.
     *
     * @param string                     $service    Service segment of the URL (e.g. `sales`, `auth`).
     * @param string                     $method     HTTP method in uppercase (e.g. `GET`, `POST`, `PUT`, `PATCH`, `DELETE`).
     * @param string                     $path       Path relative to `/v1/{service}`, with `{placeholder}` tokens for path params
     *                                               (e.g. `/patients/view/{id}`).
     * @param array<string, string|int>  $pathParams Map of placeholder name → value for substitution in `$path`.
     * @param array<string, scalar>      $query      Query string parameters to append to the URL.
     * @param array<string, mixed>|null  $body       Request body as a PHP array; `null` sends no body. An empty array `[]`
     *                                               is serialised as `{}` to satisfy AsterMD's object-shape requirement.
     * @param array<string, string>      $headers    Additional request headers (e.g. `x-phi-verification-token`).
     *
     * @return Response the decoded success envelope; `data()` holds the primary resource payload
     *
     * @throws \AsterMD\Sdk\Exception\AuthenticationException if the request returns 401 and token refresh does not recover it
     * @throws \AsterMD\Sdk\Exception\NotFoundException       if the request returns 404
     * @throws \AsterMD\Sdk\Exception\ValidationException     if the request returns 422 (field-level errors available via `fieldErrors()`)
     * @throws \AsterMD\Sdk\Exception\RateLimitException      if the request returns 429 (retry delay available via `retryAfter()`)
     * @throws \AsterMD\Sdk\Exception\ApiException            if the request returns any other non-2xx status
     * @throws \AsterMD\Sdk\Exception\TransportException      if a network-level failure (cURL error, DNS, TLS, timeout) prevents the request from completing
     */
    public function send(
        string $service,
        string $method,
        string $path,
        array $pathParams = [],
        array $query = [],
        ?array $body = null,
        array $headers = [],
    ): Response {
        $response = $this->dispatch($service, $method, $path, $pathParams, $query, $body, $headers);

        if ($response->getStatusCode() === 401 && ($this->onUnauthorized)()) {
            $response = $this->dispatch($service, $method, $path, $pathParams, $query, $body, $headers);
        }

        return $this->handle($response);
    }

    /**
     * @param array<string, string|int> $pathParams
     * @param array<string, scalar> $query
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    private function dispatch(
        string $service,
        string $method,
        string $path,
        array $pathParams,
        array $query,
        ?array $body,
        array $headers,
    ): ResponseInterface {
        $url = $this->urlBuilder->build($service, $path, $pathParams, $query);
        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Accept', 'application/json');

        $token = ($this->tokenProvider)();
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            // PHP's json_encode([]) emits the array literal `[]`, not the object `{}`.
            // Every AsterMD endpoint expects an object-shaped body, so coerce empty.
            $json = $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($json));
        }

        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    private function handle(ResponseInterface $response): Response
    {
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $envelope = $this->decode($raw);

        if ($status >= 200 && $status < 300) {
            return new Response(
                statusCode: $status,
                data: $this->arrayField($envelope, 'data'),
                meta: $this->arrayField($envelope, 'meta'),
                message: is_string($envelope['message'] ?? null) ? $envelope['message'] : '',
                raw: $raw,
            );
        }

        $message = is_string($envelope['message'] ?? null) ? $envelope['message'] : "HTTP {$status}";

        throw match (true) {
            $status === 401 => new AuthenticationException($message, $status, $envelope),
            $status === 404 => new NotFoundException($message, $status, $envelope),
            $status === 422 => new ValidationException(
                $message,
                $status,
                $envelope,
                $this->fieldErrors($envelope),
            ),
            $status === 429 => new RateLimitException(
                $message,
                $status,
                $envelope,
                $this->retryAfter($response),
            ),
            default => new ApiException($message, $status, $envelope),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                return [];
            }

            /** @var array<string, mixed> $decoded */
            return $decoded;
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    private function arrayField(array $envelope, string $key): array
    {
        $value = $envelope[$key] ?? null;

        if (!is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, list<string>>
     */
    private function fieldErrors(array $envelope): array
    {
        $errors = $envelope['errors'] ?? [];
        if (!is_array($errors)) {
            return [];
        }

        $out = [];
        foreach ($errors as $field => $messages) {
            if (!is_string($field)) {
                continue;
            }
            if (is_array($messages)) {
                $out[$field] = array_values(array_filter($messages, 'is_string'));
            } elseif (is_string($messages)) {
                $out[$field] = [$messages];
            }
        }

        return $out;
    }

    private function retryAfter(ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('Retry-After');
        if ($header === '' || !ctype_digit($header)) {
            return null;
        }

        return (int) $header;
    }
}
