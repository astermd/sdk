# Architecture

Single-page overview of how the `astermd/sdk` package is wired together.
For end-user usage see `docs/INTEGRATION_GUIDE.md`. For agent/contributor conventions see `CLAUDE.md`.

## Layers

```
┌──────────────────────────────────────────────────────────────────────┐
│  Consumer application (Laravel / Symfony / plain PHP)                │
└─────────────────────────────┬────────────────────────────────────────┘
                              │
┌─────────────────────────────▼────────────────────────────────────────┐
│  AsterMDClient                 ← entry point, holds collaborators     │
│   • config()                                                          │
│   • sessions()  intakeSubmissions()  teleforms()  patients()               │
│   • opportunities()  treatments()  doctorsNetworks()  channels()      │
│   • products() categories() labTests() medications() shippings()      │
│   • verification()  geo()                                             │
└─────────────────────────────┬────────────────────────────────────────┘
                              │
                ┌─────────────┴──────────────┐
                │                            │
┌───────────────▼─────────────┐  ┌───────────▼──────────────────┐
│  Resource\* (final)         │  │  Auth\TokenManager (final)   │
│   thin wrappers, one        │  │   lazy acquire / refresh,    │
│   method per endpoint;      │  │   30s pre-buffer, single     │
│   no HTTP, no JSON          │  │   401 auto-retry             │
└───────────────┬─────────────┘  └───────────┬──────────────────┘
                │                            │
                └──────────────┬─────────────┘
                               │
                  ┌────────────▼─────────────┐
                  │  Http\Transport (final)  │
                  │   • PSR-7 request build  │
                  │   • Bearer token attach  │
                  │   • envelope decode      │
                  │   • status → exception   │
                  └────────────┬─────────────┘
                               │
                  ┌────────────▼─────────────┐
                  │  PSR-18 ClientInterface  │
                  │  (NativeCurlClient by    │
                  │   default; pluggable)    │
                  └──────────────────────────┘
```

## File map

```
src/
├── AsterMDClient.php         entry point; wires Config + TokenManager + Transport
├── Config.php                immutable value object (clientId, secret, host, timeout)
├── Response.php              immutable response (status, data, meta, message, raw)
├── Auth/
│   ├── Token.php             value object (value, expiresAt, isExpired pre-buffer)
│   ├── TokenStore.php        interface — pluggable persistence
│   ├── InMemoryTokenStore.php  default; per-process only, does not survive between requests
│   ├── FileTokenStore.php    file-backed store; survives across requests, 0o600 permissions
│   └── TokenManager.php      owns its OWN no-auth Transport for token exchange
├── Http/
│   ├── NativeCurlClient.php  default PSR-18; ext-curl only, no Guzzle
│   ├── CurlFormatter.php     formats a PSR-7 request as a copy-pasteable curl command
│   ├── CurlLoggingClient.php PSR-18 decorator that logs requests + responses when debug=true
│   ├── LogRedactor.php       masks tokens, secrets, and PHI in debug output (on by default)
│   ├── UrlBuilder.php        builds /v1/{service}/{path} URLs
│   └── Transport.php         the single HTTP chokepoint
├── Log/
│   └── DailyFileLogSink.php  built-in debug sink; one file per day, prunes old files
├── Resource/
│   ├── AbstractResource.php  base; only knows about Transport
│   ├── Sessions.php          /v1/sales/sessions/*
│   ├── IntakeSubmissions.php /v1/sales/intake-submissions/*
│   ├── Carts.php             /v1/sales/carts/*
│   ├── CheckoutEvents.php    /v1/sales/checkout-events/*
│   ├── Teleforms.php         /v1/sales/teleforms/*
│   ├── Patients.php          /v1/sales/patients/*
│   ├── Opportunities.php     /v1/sales/opportunities/*
│   ├── Treatments.php        /v1/sales/treatments/*
│   ├── DoctorsNetworks.php   /v1/sales/doctors-networks/sync (+ client-side validation)
│   ├── Channels.php          /v1/sales/channels/*
│   ├── Products.php          /v1/sales/products/*
│   ├── Categories.php        /v1/sales/categories/*
│   ├── LabTests.php          /v1/sales/lab-tests/*
│   ├── Medications.php       /v1/sales/medications/list  (list-only)
│   ├── Shippings.php         /v1/sales/shippings/*
│   ├── Verification.php      /v1/platform/extensions/{address,email,identity}-*
│   └── Geo.php               /v1/platform/extensions/geo-*
├── Presenter/
│   └── ChannelDetail.php     read model over the channel detail envelope
├── Support/
│   └── QueryParamCipher.php  AES-128-CBC codec for encrypted URL params (caller supplies the key)
├── Enum/
│   ├── Event.php             pre_qualifying_* / intake_* events
│   ├── CheckoutEvent.php     checkout_visited / upsell_* / order_* events
│   └── IdentityCheck.php     crosscheck / dob_verify / ssn_verify
└── Exception/
    ├── AsterMDException.php      abstract base
    ├── TransportException.php    cURL / network failure (no HTTP response)
    └── ApiException.php          base for everything with a status code
        ├── AuthenticationException.php   (401, after one auto-retry)
        ├── NotFoundException.php         (404)
        ├── ValidationException.php       (422 + fieldErrors)
        └── RateLimitException.php        (429 + retryAfter)
```

## Request lifecycle

For a typical authenticated call (e.g. `patients()->view('p1')`):

1. **Resource method** is invoked on the user's `AsterMDClient` accessor.
   The resource calls `$this->transport->send('sales', 'GET', '/patients/view/{id}', pathParams: ['id' => 'p1'])`.
2. **`Transport::send()`** calls the injected `tokenProvider` closure.
   In the production wiring this closure delegates to `TokenManager::bearerToken()`.
3. **`TokenManager`** checks its `TokenStore` for a non-expired token. If found, returns the JWT string. Otherwise it dispatches a token-exchange request through its own _separate_, _unauthenticated_ `Transport` to `POST /v1/auth/api-credentials/token`, parses `access_token` + `access_token_expiry`, stores the result, and returns the JWT.
4. **`Transport`** builds a PSR-7 request via `UrlBuilder` (`https://{host}/v1/{service}/{path}`), sets `Authorization: Bearer <jwt>` and `Accept: application/json`, attaches any extra headers (e.g. `x-phi-verification-token`), and encodes the body if present.
5. **PSR-18 client** sends the request. By default this is `NativeCurlClient` (`ext-curl`). Network failure raises `TransportException`.
6. **`Transport`** reads the response status. If 2xx, it decodes the envelope and returns a `Response`. If 401, it calls the `onUnauthorized` closure — which delegates to `TokenManager::refresh()` — and replays the request once. If the replay still 401s (or for any non-2xx other than the single 401 retry path), it maps the status to the matching exception subclass and throws.
7. **Resource method** returns the `Response` value object to the caller.

## Why two Transports for token acquisition

A naive `TokenManager` that called the SDK's main `Transport` to fetch its token would recurse infinitely on the first call: the main `Transport` would ask `TokenManager` for a bearer token, `TokenManager` would call `Transport` to fetch one, which would ask `TokenManager` again, and so on.

The fix is straightforward: `TokenManager` constructs a private `Transport` with `tokenProvider: fn () => null` and `onUnauthorized: fn () => false`.
That transport sends the credential exchange and nothing else — it can't recurse because it neither provides nor expects a bearer token.

## Why the SDK is so small

The contract intentionally exposes only the order-flow surface (auth, sessions, intake-submissions, patients, opportunities, treatments, doctors-networks, channels, catalog). Administrative surfaces — analytics, reporting, org/role management, and integration management — are out of scope and will stay out. They are managed from the AsterMD dashboard, and they are not what an integrating application needs.

This is the Stripe model: a deliberate, curated surface rather than an exhaustive 1-to-1 wrapper around every endpoint.

## Invariants that future changes must preserve

- **`Transport` is the only place that touches HTTP, JSON, or PSR-7 types.** Resources never see them.
- **`TokenManager` is the only place that exchanges credentials for a JWT.**
- **`org_id` lives in the JWT** — never as an SDK argument or per-request header.
- **`AsterMDClient` accepts a host, not a URL.** It always builds `/v1/{service}/{path}` itself.
- **The default transport is native cURL.** A consumer may swap in any PSR-18 client; the SDK must never depend on a specific implementation.
- **Internal classes are `final`. Resource classes are non-final** (so consumers can extend them).
- **Value objects (`Config`, `Token`, `Response`) are immutable.**

If a proposed change violates any of these, raise it on a PR first — the small surface is the value.

A `CurlLoggingClient` decorator can optionally wrap the PSR-18 client (inserted between `AsterMDClient` and all downstream consumers) when `debug: true` is passed at construction; this covers both the main API calls and the token-exchange request without modifying `Transport` or `TokenManager`.

Because that decorator sees the credential exchange, it is also the only place that can leak one. A `LogRedactor` is attached by default and masks bearer tokens, the client secret, PHI verification tokens, and `patients/*` bodies before anything reaches the sink; `debugRedact: false` removes it. Sink behaviour is likewise pluggable: `DailyFileLogSink` handles the built-in dated-file-plus-retention case, and any `debugSink` closure replaces it entirely.
