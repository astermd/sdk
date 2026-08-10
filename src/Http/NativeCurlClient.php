<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Http;

use AsterMD\Sdk\Exception\TransportException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Default PSR-18 HTTP client powered by PHP's native ext-curl extension.
 *
 * Used automatically when no custom PSR-18 client is injected into
 * {@see \AsterMD\Sdk\AsterMDClient}. It has no Guzzle or any other external
 * HTTP library dependency — it is the only HTTP implementation shipped in this
 * SDK. Consumers may replace it entirely by passing any PSR-18-compliant client
 * (Guzzle, Symfony HttpClient, etc.) via the `$httpClient` constructor parameter
 * on `AsterMDClient`.
 *
 * The client enforces TLS peer and host verification (`CURLOPT_SSL_VERIFYPEER` and
 * `CURLOPT_SSL_VERIFYHOST=2`) and does not follow redirects. Both the connect
 * timeout and the read timeout are set to `$timeoutSeconds`.
 */
final class NativeCurlClient implements ClientInterface
{
    private readonly Psr17Factory $factory;

    /**
     * @param int $timeoutSeconds connect and read timeout in seconds applied to every request;
     *                            passed as both `CURLOPT_CONNECTTIMEOUT` and `CURLOPT_TIMEOUT`
     */
    public function __construct(private readonly int $timeoutSeconds = 10)
    {
        $this->factory = new Psr17Factory();
    }

    /**
     * Executes the given PSR-7 request via cURL and returns a PSR-7 response.
     *
     * Implements the PSR-18 `ClientInterface` contract. On a cURL-level failure
     * (network error, DNS failure, TLS error, timeout) a {@see TransportException}
     * is thrown instead of returning a response.
     *
     * @param RequestInterface $request the PSR-7 request to send
     *
     * @return ResponseInterface the PSR-7 response from the server
     *
     * @throws \AsterMD\Sdk\Exception\TransportException if cURL reports an error (e.g. connection refused, timeout)
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $ch = curl_init();

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = $name . ': ' . $value;
            }
        }

        $url = (string) $request->getUri();
        $method = $request->getMethod();

        if ($url === '' || $method === '') {
            throw new TransportException('Request must carry a non-empty URI and HTTP method.');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = (string) $request->getBody();
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $message = curl_error($ch);
            $errno = curl_errno($ch);

            throw new TransportException("cURL error ({$errno}): {$message}");
        }

        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if (!is_string($raw)) {
            throw new TransportException('cURL returned non-string response.');
        }

        $rawHeaders = substr($raw, 0, $headerSize);
        $rawBody = substr($raw, $headerSize);

        $response = $this->factory->createResponse($status);
        foreach ($this->parseHeaders($rawHeaders) as $name => $values) {
            foreach ($values as $value) {
                $response = $response->withAddedHeader($name, $value);
            }
        }

        return $response->withBody($this->factory->createStream($rawBody));
    }

    /**
     * @return array<string, list<string>>
     */
    private function parseHeaders(string $raw): array
    {
        $out = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $out[trim($name)][] = trim($value);
        }

        return $out;
    }
}
