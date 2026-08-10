<?php

declare(strict_types=1);

namespace AsterMD\Sdk;

use AsterMD\Sdk\Auth\InMemoryTokenStore;
use AsterMD\Sdk\Auth\TokenManager;
use AsterMD\Sdk\Auth\TokenStore;
use AsterMD\Sdk\Http\CurlLoggingClient;
use AsterMD\Sdk\Http\LogRedactor;
use AsterMD\Sdk\Http\NativeCurlClient;
use AsterMD\Sdk\Http\Transport;
use AsterMD\Sdk\Http\UrlBuilder;
use AsterMD\Sdk\Log\DailyFileLogSink;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Top-level entry point for the AsterMD PHP SDK.
 *
 * Instantiate once (e.g. as a singleton in your DI container) and call the
 * resource accessors to interact with the API. All resources share the same
 * authenticated HTTP transport, so a single client instance handles token
 * acquisition, caching, and renewal transparently across all calls.
 *
 * ```php
 * $client = new AsterMDClient(
 *     clientId:     'your-client-id',
 *     clientSecret: 'your-client-secret',
 *     // optional file-backed token cache (survives requests):
 *     tokenStore:   new FileTokenStore('/var/cache/astermd/token.json'),
 *     // optional debug logging — credentials are redacted by default:
 *     debug:        true,
 *     debugFile:    '/var/log/astermd/sdk.log',
 * );
 *
 * $session = $client->sessions()->create();
 * $sessionId = $session->data()['session'];
 * ```
 *
 * By default the client uses the production host `api.astermd.com`
 * with a native-cURL PSR-18 transport and an in-memory token store. Override
 * any of these via constructor parameters to integrate with your own HTTP client
 * or token persistence layer.
 *
 * @throws \InvalidArgumentException if `debug=true` and neither `debugFile` nor `debugSink` is provided
 * @throws \Exception if `debugTimezone` is not a valid IANA timezone identifier
 */
final class AsterMDClient
{
    private readonly Config $config;
    private readonly TokenManager $tokenManager;
    private readonly Transport $transport;

    /**
     * Constructs the SDK client and wires together all internal collaborators.
     *
     * Most parameters are optional and have sensible production defaults. The
     * only required inputs are the API credentials issued by the AsterMD platform.
     *
     * @param string                      $clientId        OAuth2 client ID issued by AsterMD (typically `<name>@api.astermd.com`).
     * @param string                      $clientSecret    OAuth2 client secret corresponding to `$clientId`.
     * @param string|null                 $baseHost        Bare hostname of the API, e.g. `api.astermd.com`. Omit the
     *                                                     scheme and any path segments; the SDK always prepends `https://` and
     *                                                     appends `/v1/{service}/{path}`. Defaults to the production host.
     * @param ClientInterface|null        $httpClient      PSR-18 HTTP client to use for all outbound requests. When omitted a
     *                                                     native-cURL client is used. Inject a custom client (Guzzle, Symfony
     *                                                     HttpClient, etc.) for testing or when you need specific TLS/proxy config.
     * @param RequestFactoryInterface|null $requestFactory PSR-17 request factory. Defaults to `Nyholm\Psr7\Factory\Psr17Factory`.
     * @param StreamFactoryInterface|null  $streamFactory  PSR-17 stream factory. Defaults to `Nyholm\Psr7\Factory\Psr17Factory`.
     * @param TokenStore|null             $tokenStore      Storage backend for the cached JWT. Defaults to `InMemoryTokenStore`
     *                                                     (token lives only for the lifetime of this object). Use `FileTokenStore`
     *                                                     or an APCu/Redis implementation to share the token across requests in
     *                                                     long-running web applications.
     * @param int                         $timeoutSeconds  Per-request connect and read timeout in seconds. Minimum 1. Defaults to 10.
     * @param bool                        $debug           When `true`, wraps the HTTP client with `CurlLoggingClient` to log every
     *                                                     request and response as a copy-pasteable cURL command. The auth token
     *                                                     exchange call is also logged.
     * @param string|null                 $debugFile       Base path for the log file, e.g. `/var/log/astermd/sdk.log`. Required
     *                                                     when `debug=true` and `debugSink` is not provided. The SDK writes one
     *                                                     file per day derived from this path (`sdk-2026-08-08.log`) and prunes
     *                                                     files older than `$debugRetentionDays`. See {@see DailyFileLogSink}.
     * @param \Closure|null               $debugSink       Custom log sink: `fn(string $entry): void`. Takes precedence over
     *                                                     `debugFile` when both are supplied. Use this to forward entries to a
     *                                                     log aggregator, a PSR-3 logger, or any other destination; the SDK then
     *                                                     writes no files and retention is your responsibility.
     * @param string|null                 $debugTimezone   IANA timezone for log entry timestamps and for deciding which calendar
     *                                                     day a log file belongs to (e.g. `America/New_York`). Defaults to `UTC`.
     * @param bool                        $debugRedact     When `true` (the default), bearer tokens, the OAuth2 client secret, PHI
     *                                                     verification tokens, and patient-endpoint bodies are replaced with
     *                                                     `[REDACTED]` before reaching the sink. Set to `false` to log everything
     *                                                     verbatim — this writes live credentials, so never do it in production.
     * @param int                         $debugRetentionDays Days of daily log files to keep when the SDK manages the file itself.
     *                                                     Defaults to 7; `0` keeps every file forever. Ignored when `debugSink`
     *                                                     is supplied.
     *
     * @throws \InvalidArgumentException if `debug=true` and neither `debugFile` nor `debugSink` is given, or if the log
     *                                   directory cannot be created or written to
     * @throws \Exception                if `debugTimezone` is not a valid IANA timezone identifier
     */
    public function __construct(
        string $clientId,
        string $clientSecret,
        ?string $baseHost = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?TokenStore $tokenStore = null,
        int $timeoutSeconds = 10,
        bool $debug = false,
        ?string $debugFile = null,
        ?\Closure $debugSink = null,
        ?string $debugTimezone = null,
        bool $debugRedact = true,
        int $debugRetentionDays = 7,
    ) {
        $this->config = new Config(
            clientId: $clientId,
            clientSecret: $clientSecret,
            baseHost: $baseHost ?? 'api.astermd.com',
            timeoutSeconds: $timeoutSeconds,
        );

        $http = $httpClient ?? new NativeCurlClient($timeoutSeconds);

        if ($debug) {
            if ($debugSink === null && $debugFile === null) {
                throw new \InvalidArgumentException('debug mode requires debugFile or debugSink.');
            }

            $tz = new \DateTimeZone($debugTimezone ?? 'UTC');

            $sink = $debugSink ?? new DailyFileLogSink(
                basePath: (string) $debugFile,
                retentionDays: $debugRetentionDays,
                timezone: $tz,
            )(...);

            $http = new CurlLoggingClient(
                $http,
                $sink,
                $tz,
                $debugRedact ? new LogRedactor() : null,
            );
        }

        $factory = new Psr17Factory();
        $reqFactory = $requestFactory ?? $factory;
        $streams = $streamFactory ?? $factory;
        $urlBuilder = new UrlBuilder($this->config->baseHost());

        $this->tokenManager = new TokenManager(
            config: $this->config,
            httpClient: $http,
            requestFactory: $reqFactory,
            streamFactory: $streams,
            urlBuilder: $urlBuilder,
            store: $tokenStore ?? new InMemoryTokenStore(),
        );

        $this->transport = new Transport(
            httpClient: $http,
            requestFactory: $reqFactory,
            streamFactory: $streams,
            urlBuilder: $urlBuilder,
            tokenProvider: fn (): string => $this->tokenManager->bearerToken(),
            onUnauthorized: fn (): bool => $this->tokenManager->refresh(),
        );
    }

    /**
     * Returns the immutable configuration object used to construct this client.
     *
     * Useful when you need to inspect the resolved base host or timeout for
     * diagnostics, or to pass the same configuration to another component.
     *
     * @return Config the configuration this client was built with
     */
    public function config(): Config
    {
        return $this->config;
    }

    /**
     * Builds the fully-qualified CDN URL for a media asset path returned by the AsterMD API.
     *
     * Convenience wrapper around {@see Config::assetUrl()}. Pass the relative path
     * exactly as it appears in API response fields (e.g. `$response->data()['image']`)
     * to get the fully-qualified URL.
     *
     * Download the asset and serve it from your own filesystem or CDN rather than
     * hot-linking this URL from a storefront — see {@see Config::assetUrl()}.
     *
     * @param string $path relative asset path as returned by the API (leading slash optional)
     * @return string fully-qualified URL, e.g. `https://cdn.astermd.com/{path}`
     */
    public function assetUrl(string $path): string
    {
        return $this->config->assetUrl($path);
    }

    /**
     * Returns the Sessions resource for tracking visitor and order-flow sessions.
     *
     * Sessions are the first entity created in any order flow. A session UUID ties
     * together intake submissions, cart operations, and eventually the
     * treatment record. Call `sessions()->create()` at the start of each visitor's
     * journey and persist the returned session UUID in your frontend.
     *
     * @return \AsterMD\Sdk\Resource\Sessions
     */
    public function sessions(): \AsterMD\Sdk\Resource\Sessions
    {
        return new \AsterMD\Sdk\Resource\Sessions($this->transport);
    }

    /**
     * Returns the IntakeSubmissions resource for recording pre-qualifying and intake form submissions.
     *
     * An intake submission captures the step-by-step answers a prospect provides
     * during pre-qualification and intake questionnaires. Each submission is tagged
     * with a session UUID and an {@see \AsterMD\Sdk\Enum\Event} value that advances
     * the server-side journey state machine.
     *
     * @return \AsterMD\Sdk\Resource\IntakeSubmissions
     */
    public function intakeSubmissions(): \AsterMD\Sdk\Resource\IntakeSubmissions
    {
        return new \AsterMD\Sdk\Resource\IntakeSubmissions($this->transport);
    }

    /**
     * Returns the Carts resource for initialising and updating shopping carts.
     *
     * A cart is created once per session and updated as the prospect changes their
     * product selection. Cart operations record the `cart_initiated` event on the
     * server side, which is used for session-journey attribution.
     *
     * @return \AsterMD\Sdk\Resource\Carts
     */
    public function carts(): \AsterMD\Sdk\Resource\Carts
    {
        return new \AsterMD\Sdk\Resource\Carts($this->transport);
    }

    /**
     * Returns the CheckoutEvents resource for recording a session's checkout-funnel events.
     *
     * Checkout events track the prospect through the final order-flow stage: reaching
     * checkout, post-cart upsell offers and their outcomes, and the order result. Each
     * call carries an {@see \AsterMD\Sdk\Enum\CheckoutEvent} value that advances the
     * server-side checkout state machine and attributes the order to the session.
     *
     * @return \AsterMD\Sdk\Resource\CheckoutEvents
     */
    public function checkoutEvents(): \AsterMD\Sdk\Resource\CheckoutEvents
    {
        return new \AsterMD\Sdk\Resource\CheckoutEvents($this->transport);
    }

    /**
     * Returns the Teleforms resource for reading questionnaire and intake form definitions.
     *
     * Teleform definitions describe the fields, validation rules, and display order
     * of a form. Fetch a definition by its ID or `form_json_identifier` slug
     * to render the form in your frontend before recording submissions via
     * {@see intakeSubmissions()}.
     *
     * @return \AsterMD\Sdk\Resource\Teleforms
     */
    public function teleforms(): \AsterMD\Sdk\Resource\Teleforms
    {
        return new \AsterMD\Sdk\Resource\Teleforms($this->transport);
    }

    /**
     * Returns the Patients resource for managing patient (customer) records on the sales service.
     *
     * A patient record is created after the prospect clears eligibility checks. It
     * holds PII / PHI fields and acts as the persistent identity anchor across
     * multiple treatment orders. Methods on this resource require appropriate
     * permissions; `submitHealthInformation()` additionally accepts a PHI
     * verification token for verified submissions.
     *
     * @return \AsterMD\Sdk\Resource\Patients
     */
    public function patients(): \AsterMD\Sdk\Resource\Patients
    {
        return new \AsterMD\Sdk\Resource\Patients($this->transport);
    }

    /**
     * Returns the Opportunities resource for managing lead and order-context records on the sales service.
     *
     * An opportunity groups one or more sessions under a prospect's purchase intent
     * and tracks the lead through its lifecycle from `new` to `converted`. It is
     * typically created after the patient record exists and before the treatment
     * (order) is placed.
     *
     * @return \AsterMD\Sdk\Resource\Opportunities
     */
    public function opportunities(): \AsterMD\Sdk\Resource\Opportunities
    {
        return new \AsterMD\Sdk\Resource\Opportunities($this->transport);
    }

    /**
     * Returns the Treatments resource for recording and querying treatment (order) records.
     *
     * A treatment is created after a third-party payment processor confirms
     * settlement. The SDK does not handle payment itself; it ingests the confirmed
     * order via `create()` or imports orders settled in an external CRM via `sync()`.
     *
     * @return \AsterMD\Sdk\Resource\Treatments
     */
    public function treatments(): \AsterMD\Sdk\Resource\Treatments
    {
        return new \AsterMD\Sdk\Resource\Treatments($this->transport);
    }

    /**
     * Returns the DoctorsNetworks resource for routing cases to a configured physician network.
     *
     * `DoctorsNetworks::sync()` creates or updates a case in the doctors' network
     * integration configured for your organisation. It requires either an
     * opportunity ID or minimal patient demographics and is typically called
     * after a treatment is created.
     *
     * @return \AsterMD\Sdk\Resource\DoctorsNetworks
     */
    public function doctorsNetworks(): \AsterMD\Sdk\Resource\DoctorsNetworks
    {
        return new \AsterMD\Sdk\Resource\DoctorsNetworks($this->transport);
    }

    /**
     * Returns the Channels resource for reading sales-channel definitions and their assigned products.
     *
     * A channel represents a storefront context that determines product availability
     * and pricing. Use `channels()->view()` to fetch a channel's configuration and
     * `channels()->assignedProducts()` to enumerate purchasable products for that
     * storefront.
     *
     * @return \AsterMD\Sdk\Resource\Channels
     */
    public function channels(): \AsterMD\Sdk\Resource\Channels
    {
        return new \AsterMD\Sdk\Resource\Channels($this->transport);
    }

    /**
     * Returns the Products resource for browsing the product catalog.
     *
     * Products are read-only reference data on the `sales` service. Use `list()` with
     * optional filter/pagination query parameters to enumerate available products, or
     * `view()` to fetch a single product by ID for display in your storefront.
     *
     * @return \AsterMD\Sdk\Resource\Products
     */
    public function products(): \AsterMD\Sdk\Resource\Products
    {
        return new \AsterMD\Sdk\Resource\Products($this->transport);
    }

    /**
     * Returns the Categories resource for browsing the product category catalog.
     *
     * Categories are read-only reference data on the `sales` service used to group
     * products. Use `list()` to enumerate categories and `view()` to fetch a single
     * category by ID for use in product browsing and filtering.
     *
     * @return \AsterMD\Sdk\Resource\Categories
     */
    public function categories(): \AsterMD\Sdk\Resource\Categories
    {
        return new \AsterMD\Sdk\Resource\Categories($this->transport);
    }

    /**
     * Returns the LabTests resource for browsing the lab-test catalog.
     *
     * Lab tests are read-only reference data on the `sales` service. Use `list()` to
     * paginate through available tests or `view()` to fetch a single entry by ID
     * when presenting diagnostic options to a prospect.
     *
     * @return \AsterMD\Sdk\Resource\LabTests
     */
    public function labTests(): \AsterMD\Sdk\Resource\LabTests
    {
        return new \AsterMD\Sdk\Resource\LabTests($this->transport);
    }

    /**
     * Returns the Medications resource for browsing the medication reference catalog.
     *
     * Medications are read-only reference data on the `sales` service. The public
     * API exposes a list endpoint only; use `list()` with optional filter/pagination
     * query parameters to enumerate available medications.
     *
     * @return \AsterMD\Sdk\Resource\Medications
     */
    public function medications(): \AsterMD\Sdk\Resource\Medications
    {
        return new \AsterMD\Sdk\Resource\Medications($this->transport);
    }

    /**
     * Returns the Shippings resource for browsing shipping option definitions.
     *
     * Shipping options are read-only reference data on the `sales` service. Use
     * `list()` to enumerate available shipping methods or `view()` to fetch a
     * specific option by ID for display at checkout.
     *
     * @return \AsterMD\Sdk\Resource\Shippings
     */
    public function shippings(): \AsterMD\Sdk\Resource\Shippings
    {
        return new \AsterMD\Sdk\Resource\Shippings($this->transport);
    }

    /**
     * Returns the Verification resource for address, email, and identity checks.
     *
     * These checks run against providers configured for your organization and belong
     * in front of the order flow, while a form is still on screen. Use
     * `verification()->autofillAddress()` and `verifyAddress()` on address fields,
     * `verifyEmail()` before you rely on an address for confirmations, and
     * `verifyIdentity()` during pre-qualifying to corroborate who is filling the form
     * in — all before `patients()->create()` commits a prospect record.
     *
     * @return \AsterMD\Sdk\Resource\Verification
     */
    public function verification(): \AsterMD\Sdk\Resource\Verification
    {
        return new \AsterMD\Sdk\Resource\Verification($this->transport);
    }

    /**
     * Returns the Geo resource for IP-based geolocation and blocklist lookups.
     *
     * Both methods take the visitor's public IP address. Use `geo()->info()` to
     * localise the storefront — pre-filling country or state, picking a currency — and
     * `geo()->blocklist()` to detect an IP with a known reputation problem before
     * accepting an order.
     *
     * @return \AsterMD\Sdk\Resource\Geo
     */
    public function geo(): \AsterMD\Sdk\Resource\Geo
    {
        return new \AsterMD\Sdk\Resource\Geo($this->transport);
    }
}
