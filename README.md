# AsterMD PHP SDK

Slim, framework-agnostic PHP SDK for the AsterMD order-flow API.

- PHP `>=8.4` (8.5 recommended)
- Zero runtime dependencies beyond the `psr/http-*` interfaces + `nyholm/psr7`
- Native-cURL PSR-18 client by default; bring your own PSR-18 client if you prefer

## Install

```bash
composer require astermd/sdk
```

## Quick start

```php
use AsterMD\Sdk\AsterMDClient;
use AsterMD\Sdk\Enum\Event;

$client = new AsterMDClient(
    clientId:     'your-client-id',
    clientSecret: 'your-client-secret',
);

// 1. Start a session (implicit `visit_page` event server-side)
$session = $client->sessions()->create()->data()['session'];

// 2. Pre-qualifying form
$client->intakeSubmissions()->create(
    session: $session,
    event: Event::PreQualifyingInitiated,
    teleformId: 'your-teleform-id',
    // `data` is a list of field objects: id / name / label / type / value
    data: [
        ['id' => 'name-1', 'name' => 'name', 'label' => 'Full name', 'type' => 'text', 'value' => [['value' => 'Jane']]],
    ],
);

// 3. Create the opportunity, attaching the session
$opportunity = $client->opportunities()->create([
    'first_name' => 'Jane',
    'email' => 'jane@example.com',
    'sessions' => [$session],
])->data();

// 4. Intake
$client->intakeSubmissions()->update(
    session: $session,
    event: Event::IntakeCompleted,
    teleformId: 'your-teleform-id',
    data: [
        ['id' => 'name-1', 'name' => 'name', 'label' => 'Full name', 'type' => 'text', 'value' => [['value' => 'Jane']]],
        ['id' => 'dob-1', 'name' => 'dob', 'label' => 'Date of birth', 'type' => 'date', 'value' => [['value' => '1990-01-15']]],
    ],
);

// 5. After external payment settles, create the treatment (= order)
$client->treatments()->create([
    'external_order_id' => 'EXT-123',
    'patient' => ['id' => '...'],
    'product' => ['id' => '...'],
]);
```

Credentials are issued from your AsterMD dashboard, which is also where the full
API reference lives. Load the secret from an environment variable or a secrets
manager — never commit it.

## Resources

`sessions`, `intakeSubmissions`, `carts`, `checkoutEvents`, `teleforms`,
`patients`, `opportunities`, `treatments`, `doctorsNetworks`, `channels`,
`products`, `categories`, `labTests`, `medications`, `shippings`,
`verification`, `geo`.

## Errors

Every error extends `AsterMD\Sdk\Exception\AsterMDException`:

- `TransportException` — cURL / network failure
- `AuthenticationException` — 401
- `NotFoundException` — 404
- `ValidationException` — 422 (`->fieldErrors()`)
- `RateLimitException` — 429 (`->retryAfter()`)
- `ApiException` — other 4xx/5xx

## Debugging

Pass `debug: true` with a `debugFile` to log every request as a copy-pasteable
curl command plus its response.

```php
$client = new AsterMDClient(
    clientId:     'your-client-id',
    clientSecret: 'your-client-secret',
    debug:        true,
    debugFile:    '/var/log/astermd/sdk.log',
);
```

**Credentials are redacted by default.** Bearer tokens, the client secret, PHI
verification tokens, and the bodies of `patients/*` calls are replaced with
`[REDACTED]`:

```
curl --location --request POST 'https://api.astermd.com/v1/sales/sessions/create' \
  --header 'Authorization: Bearer [REDACTED]' \
  --header 'Content-Type: application/json' \
  --data '{"data":{}}'

# Response: HTTP 200
{"success":true,"message":"ok","data":{"session":"…"}}
```

Pass `debugRedact: false` to log verbatim, including the live JWT. Never do that
in production.

### Log rotation

`debugFile` is a *base path*. The SDK writes one file per day derived from it and
deletes files older than `debugRetentionDays` (default 7; `0` keeps everything):

```
/var/log/astermd/sdk-2026-08-08.log
/var/log/astermd/sdk-2026-08-07.log
```

Pruning happens once per process, and only files matching the SDK's own
`{name}-YYYY-MM-DD.{ext}` pattern are ever removed.

### Sending logs somewhere else

Supply a `debugSink` closure and the SDK writes no files at all — retention and
delivery become yours. This works with any destination: a PSR-3 logger, a hosted
log service, a cloud provider's logging API, a queue.

```php
$client = new AsterMDClient(
    clientId:     'your-client-id',
    clientSecret: 'your-client-secret',
    debug:        true,
    debugSink:    fn (string $entry) => $logger->debug($entry),
);
```

See [`docs/INTEGRATION_GUIDE.md`](docs/INTEGRATION_GUIDE.md) for worked examples.

## Development

Two supported workflows. Docker is the default (`make build && make install`).
Native PHP is a one-flag opt-out (`DOCKER=0 make ...`).

### Using Docker (default, no host PHP required)

```bash
make build       # build the PHP 8.4 image (the minimum supported version)
make install     # composer install (inside the container)
make ci          # lint + stan + test
# also: make test, make lint, make fix, make stan, make shell
```

### Using native PHP on the host

Requirements: PHP `>=8.4`, Composer, and `ext-curl`/`ext-json`/`ext-mbstring`/`ext-openssl`.

```bash
DOCKER=0 make install   # composer install on host
DOCKER=0 make ci        # lint + stan + test on host
# tip: export DOCKER=0 in your shell to make it the default for the session
```

Both paths run the **same** commands; only the execution context differs. CI gate
order is identical: `php-cs-fixer --dry-run` → `phpstan analyse` → `phpunit`.

## Compliance

This SDK is a client for an API that carries personal and protected health
information. Using it does not by itself make your application HIPAA compliant —
that depends on your own infrastructure, policies, and agreements. Contact
info@astermd.com regarding a Business Associate Agreement.

See [`SECURITY.md`](SECURITY.md) for vulnerability reporting and credential-handling
guidance.

## Further reading

| Audience                            | Document                                                                                                        |
| ----------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| **Integrators building on the SDK** | [`docs/INTEGRATION_GUIDE.md`](docs/INTEGRATION_GUIDE.md) — order flow, resources, auth lifecycle, framework recipes |
| Contributors                        | [`CONTRIBUTING.md`](CONTRIBUTING.md) — local setup, how to add an endpoint                                       |
| Architecture                        | [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — single-page overview                                             |
| Changes                             | [`CHANGELOG.md`](CHANGELOG.md)                                                                                   |

## Support

Questions, access requests, and licensing enquiries: **info@astermd.com**

## License

[MIT](LICENSE) © 2026 AsterMD
