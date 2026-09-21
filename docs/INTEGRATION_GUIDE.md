# AsterMD PHP SDK — Integration Guide

This is the handoff document for engineers (or AI sessions) building an
application on top of the `astermd/sdk` Composer package. It assumes you have a
working PHP project and an AsterMD `client_id` + `client_secret`.

If you only want to install and call one endpoint, the project `README.md` is
shorter and probably enough. Use this document when you are wiring the SDK into
a real flow.

---

## Table of contents

1. [Mental model](#1-mental-model)
2. [Install](#2-install)
3. [Client construction](#3-client-construction)
4. [End-to-end order flow](#4-end-to-end-order-flow)
5. [Resources reference](#5-resources-reference)
6. [Auth lifecycle](#6-auth-lifecycle)
7. [Error handling](#7-error-handling)
8. [Framework recipes](#8-framework-recipes)
9. [Custom transport (BYO PSR-18)](#9-custom-transport-byo-psr-18)
10. [Debugging — copy-paste-able curl logs](#10-debugging--copy-paste-able-curl-logs)

---

## 1. Mental model

The SDK is a thin, typed wrapper around the AsterMD public REST API.

```
your app
  └── AsterMDClient
        ├── ->sessions()         ->intakeSubmissions()    ->carts()           ->teleforms()
        ├── ->patients()         ->opportunities()   ->treatments()
        ├── ->doctorsNetworks()  ->channels()
        └── catalog: ->products() / ->categories() / ->labTests()
                     ->medications() / ->shippings()
```

Every resource method returns an `AsterMD\Sdk\Response`:

- `->statusCode(): int`
- `->data(): array` — the `data` field from the envelope (decoded JSON as a PHP array)
- `->meta(): array` — pagination, totals, etc.
- `->message(): string` — server-supplied human message
- `->raw(): string` — the raw response body (for logging / debugging)

`data` is NOT a typed DTO — it is a decoded array. The SDK deliberately stays
slim and does not generate per-endpoint models; you read fields directly:
`$response->data()['id']`.

The SDK does NOT handle:

- Eligibility checks (your app decides who's eligible)
- Payment processing (your payment provider runs outside the SDK)
- Server-side event forwarding (handled by AsterMD; no SDK exposure)

---

## 2. Install

```bash
composer require astermd/sdk
```

Requirements:

- PHP `>=8.4` (8.5 recommended)
- `ext-curl`, `ext-json`, `ext-mbstring`, `ext-openssl`

The package depends only on `psr/http-client`, `psr/http-factory`,
`psr/http-message`, and `nyholm/psr7`. No Guzzle, no framework coupling.

---

## 3. Client construction

```php
use AsterMD\Sdk\AsterMDClient;

$client = new AsterMDClient(
    clientId:     'your-client-id',
    clientSecret: 'your-client-secret',
);
```

Optional constructor parameters:

| Parameter        | Type                                        | Default                            | Purpose                                       |
| ---------------- | ------------------------------------------- | ---------------------------------- | --------------------------------------------- |
| `baseHost`       | `?string`                                   | `api.astermd.com`                  | Host only; SDK appends `/v1/{service}/{path}` |
| `httpClient`     | `?Psr\Http\Client\ClientInterface`          | `NativeCurlClient`                 | Swap the transport (see §9)                   |
| `requestFactory` | `?Psr\Http\Message\RequestFactoryInterface` | `Nyholm\Psr7\Factory\Psr17Factory` | PSR-17                                        |
| `streamFactory`  | `?Psr\Http\Message\StreamFactoryInterface`  | same as request factory            | PSR-17                                        |
| `tokenStore`     | `?AsterMD\Sdk\Auth\TokenStore`              | `InMemoryTokenStore`               | Token cache (see §6)                          |
| `timeoutSeconds` | `int`                                       | `10`                               | Per-request cURL timeout                      |

The `client_id` and `client_secret` are validated as non-empty at construction time.

---

## 4. End-to-end order flow

This is the choreography the SDK is designed to support. Cache the session
UUID (cookie / localStorage / DB) so you can resume from any step.

```php
use AsterMD\Sdk\AsterMDClient;
use AsterMD\Sdk\Enum\Event;

$client = new AsterMDClient(clientId: '…', clientSecret: '…');
```

### Step 1 — Start a session (page view)

```php
$session = $client->sessions()->create()->data()['session'];
// Server logs an implicit `visit_page` event. No payload required.
// Persist $session for the rest of the journey.

// Forward the visitor's browser User-Agent AND IP so the session is attributed
// to their device/geo, not this server-side PHP client. The SDK runs
// server-to-server, so only your app knows the real values — read them from
// $_SERVER (User-Agent -> `User-Agent` header, IP -> `X-Original-Client-Ip`).
$session = $client->sessions()->create(
    userAgent: $_SERVER['HTTP_USER_AGENT'] ?? null,
    clientIp:  $_SERVER['REMOTE_ADDR'] ?? null,
)->data()['session'];

// Optional payload — send any additional creation hints the server accepts.
// $session = $client->sessions()->create(['test' => true], $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null)->data()['session'];
```

### Step 2 — Pre-qualifying (identity check, basic questions)

Before recording answers, validate what the prospect typed. These checks are
independent of the session and store nothing — the verdict is yours to act on.

```php
use AsterMD\Sdk\Enum\IdentityCheck;

// Address field: suggestions as they type, then one validation on the chosen line.
$suggestions = $client->verification()->autofillAddress('123 Main St, San')->data();
$address = $client->verification()->verifyAddress('123 Main St, San Francisco, CA 94105')->data();

// Email: `unknown` means inconclusive (provider rate-limited), not a failure — let them through.
if ($client->verification()->verifyEmail('jane@example.com')->data()['result'] === 'invalid') {
    // ask for a different address
}

// Identity: the enum sets the request's `slug` and dictates which fields are required.
$identity = $client->verification()->verifyIdentity(IdentityCheck::DobVerify, [
    'firstName' => 'Jane',
    'lastName'  => 'Doe',
    'phone'     => '+15550001234',
    'dob'       => '1990-01-15',
])->data();

if ($identity['valid'] === false) {
    foreach ($identity['reasons'] as $reason) {
        // ['code' => 'dob_mismatch', 'message' => '…']
    }
}
```

Then record the answers themselves:

```php
$teleformId = '6a01a937449da4a6bd492a8a'; // fetched from your config / channel

// On form start — `data` is a list of field objects (id / name / label / type / value)
$client->intakeSubmissions()->create(
    session:    $session,
    event:      Event::PreQualifyingInitiated,
    teleformId: $teleformId,
    data:       [
        ['id' => 'name-2345', 'name' => 'name', 'label' => 'Full name', 'type' => 'text', 'value' => [['value' => 'Jane']]],
    ],
);

// As the user advances (multi-step) — send full accumulated data every time
$client->intakeSubmissions()->update(
    session:    $session,
    event:      Event::PreQualifyingInProgress,
    teleformId: $teleformId,
    data:       [
        ['id' => 'name-2345', 'name' => 'name', 'label' => 'Full name', 'type' => 'text', 'value' => [['value' => 'Jane']]],
        ['id' => 'dob-2345', 'name' => 'dob', 'label' => 'Date of birth', 'type' => 'date', 'value' => [['value' => '1990-01-15']]],
    ],
);

// On completion
$client->intakeSubmissions()->update(
    session:    $session,
    event:      Event::PreQualifyingCompleted,
    teleformId: $teleformId,
    data:       [/* full list of field objects */],
);
```

### Step 3 — Build the cart

```php
// After pre-qualifying, build the cart from selected products.
// Each item is {product_id, name, qty}; the event and channel_id are derived server-side.
$client->carts()->create($session, [
    ['product_id' => '6a1c28f05f315cee0e41c301', 'name' => 'Semaglutide', 'qty' => 2],
    ['product_id' => '6a1c28f05f315cee0e41c357', 'name' => 'Tirzepatide', 'qty' => 3],
]);

// Later — user changes their mind (send the full replacement list)
$client->carts()->update($session, [
    ['product_id' => '6a1c28f05f315cee0e41c301', 'name' => 'Semaglutide', 'qty' => 1],
]);
```

### Step 4 — Eligibility (handled by your app, not the SDK)

```php
if (! $yourApp->isEligible($prequalAnswers)) {
    // route user to the disqualified flow; you decide.
    return;
}
```

### Step 5 — Create the prospect

```php
$prospect = $client->patients()->create([
    'first_name' => 'Jane',
    'email'      => 'jane@example.com',
    'phone_number' => '5551234567',
    // … remaining patient fields per the API reference in your AsterMD dashboard
])->data();

$prospectId = $prospect['id'];
```

### Step 6 — Create the opportunity, attaching the session

```php
$opportunity = $client->opportunities()->create([
    'first_name' => 'Jane',
    'email'      => 'jane@example.com',
    'sessions'   => [$session],       // already supported per spec
    // … other opportunity fields
])->data();

$opportunityId = $opportunity['id'];
```

### Step 7 — Intake (the main clinical questionnaire)

Same shape as pre-qualifying, different events:

```php
$intakeTeleformId = '5b03c428b9aa12f56dec3091';

$client->intakeSubmissions()->create(
    session: $session,
    event:   Event::IntakeInitiated,
    teleformId: $intakeTeleformId,
    data: [],
);

// during the form
$client->intakeSubmissions()->update(
    session: $session,
    event:   Event::IntakeInProgress,
    teleformId: $intakeTeleformId,
    data:    $accumulatedAnswers,
);

// on submit
$client->intakeSubmissions()->update(
    session: $session,
    event:   Event::IntakeCompleted,
    teleformId: $intakeTeleformId,
    data:    $allAnswers,
);
```

### Step 8 — Submit patient health information (PHI)

Some flows require an OTP'd PHI block before clinical review:

```php
$client->patients()->submitHealthInformation([
    'patient_id' => $prospectId,
    'answers'    => $clinicalAnswers,
]);

// If your flow uses PHI OTP verification, you receive a verification token
// out-of-band, then submit again with it (when re-submitting):
$client->patients()->submitHealthInformation(
    $payload,
    phiVerificationToken: 'tok-from-otp-step',
);
```

PHI OTP verification:

```php
$client->patients()->verifyHealthInformationOtp([
    'patient_id' => $prospectId,
    'otp'        => '123456',
]);
```

### Step 9 — Doctor's-network case creation

When the order requires routing to an external clinical network:

```php
$client->doctorsNetworks()->sync([
    'session_id'     => $session,
    'network'        => 'md_integrations',         // optional; open-ended enum
    'opportunity_id' => $opportunityId,            // OR provide user_info below
    'products'       => [['product_id' => 'p1']],
]);

// Alternative: skip opportunity_id and provide minimal user_info
$client->doctorsNetworks()->sync([
    'session_id' => $session,
    'user_info'  => [
        'first_name'    => 'Jane',
        'email'         => 'jane@example.com',
        // optional overrides:
        'last_name'     => 'Doe',
        'phone_number'  => '5551234567',
        'date_of_birth' => '1990-01-15',
        'address'       => [
            'address'    => '123 Main St',
            'zip_code'   => '10001',
            'city_name'  => 'New York',
            'state_name' => 'NY',
        ],
    ],
    'products'   => [['product_id' => 'p1', 'variant_id' => 'v1']],
]);
```

The SDK enforces the "opportunity_id OR (first_name + email)" precondition
**client-side** and throws `InvalidArgumentException` before sending the
request. The deprecated `emit_opportunity` field is silently stripped.

### Step 10 — Payment (external)

```
your app → your payment provider → settlement webhook
```

The SDK is uninvolved here.

### Step 11 — Create the treatment (= order)

Once settlement confirms:

```php
$treatment = $client->treatments()->create([
    'external_order_id' => 'EXT-12345',
    'channel_id'        => '…',
    'integration_id'    => '…',
    'patient'           => ['id' => $prospectId],     // shape per sales contract
    'product'           => ['id' => 'p1', 'variant_id' => 'v1'],
    'shipping_address'  => [/* … */],
])->data();
```

> **Alternative when the order originates in an external CRM:** if your CRM is the source of truth for the order record and you only need AsterMD to be aware of it, call `$client->treatments()->sync($session, ['28618'], $_SERVER['HTTP_USER_AGENT'])` instead of `treatments()->create(...)`.

### Step 12 — Resume from any step

A cached `$session` UUID is the only thing you need. Every method that touches
a session accepts it as an argument; nothing is held client-side.

```php
// Days later — pick up where the user left off
$client->intakeSubmissions()->update(
    session:    $cachedSessionUuid,
    event:      Event::IntakeInProgress,
    teleformId: $teleformId,
    data:       $previouslySavedAnswers,
);
```

---

## 5. Resources reference

All methods return `AsterMD\Sdk\Response`.

### `sessions()`

| Method                                                | HTTP   | Path                             |
| ----------------------------------------------------- | ------ | -------------------------------- |
| `create(?array $data = null, ?string $userAgent = null, ?string $clientIp = null): Response` | POST   | `/v1/sales/sessions/create`      |

> **Note:** `view()` is variadic. `view($id)` fetches one; `view($id1, $id2)` fetches multiple in one round-trip. The server route is `GET /v1/sales/sessions/view?session_ids=...`.
| `view(string ...$sessions): Response`                 | GET    | `/v1/sales/sessions/view?session_ids=…` |
| `update(string $session, array $data = []): Response` | PUT    | `/v1/sales/sessions/update/{id}` |
| `delete(string $session): Response`                   | DELETE | `/v1/sales/sessions/delete/{id}` |

### `intakeSubmissions()`

| Method                                                                                                    | HTTP | Path                                             |
| --------------------------------------------------------------------------------------------------------- | ---- | ------------------------------------------------ |
| `view(string $id, string $teleformId): Response`                                                          | GET  | `/v1/sales/intake-submissions/view/{id}`         |
| `create(string $session, Event $event, string $teleformId, array $data, ?array $progress = null): Response` | POST | `/v1/sales/intake-submissions/create`            |
| `update(string $session, Event $event, string $teleformId, array $data, ?array $progress = null): Response` | PUT  | `/v1/sales/intake-submissions/update/{session}`  |
| `uploadFile(string $sessionId, FileUpload $file): Response`                                               | POST | `/v1/sales/intake-submissions/upload-file/{session_id}` |
| `initiateMultipartUpload(string $sessionId, string $fileName, string $mimeType, int $fileSize): Response` | POST | `/v1/sales/intake-submissions/upload-file-multipart/initiate/{session_id}` |
| `uploadMultipartPart(string $uploadId, int $partNumber, FileUpload $part): Response`                      | POST | `/v1/sales/intake-submissions/upload-file-multipart/part/{upload_id}?part_number=…` |
| `finishMultipartUpload(string $uploadId, array $parts): Response`                                         | POST | `/v1/sales/intake-submissions/upload-file-multipart/finish/{upload_id}` |
| `abortMultipartUpload(string $uploadId): Response`                                                        | POST | `/v1/sales/intake-submissions/upload-file-multipart/abort/{upload_id}` |
| `uploadLargeFile(string $sessionId, string $filePath, ?string $fileName = null, ?string $mimeType = null, int $partSize = 10485760): Response` | — | drives the four multipart calls above |

`view()`'s `$id` is the **session UUID** (the submission is looked up by session + `teleform_id`). `$data` is a list of field objects (`{id, name, label, type, value[]}`). `$progress` (`{page, total}`) is optional for multi-page forms. The stored `data` and the server-derived `contact` block are **PHI** — do not log the response body.

`Event` enum values:

- `Event::PreQualifyingInitiated` / `InProgress` / `Completed`
- `Event::IntakeInitiated` / `InProgress` / `Completed`

#### Uploading answers to `file`-type fields

A `file`-type field's bytes are uploaded **before** the submission that references
them. The upload endpoints do not touch the submission itself: each returns a
`{path, name, mime_type}` object, which you attach to the matching entry of that
field's `value` list in the `$data` you pass to `create()` or `update()`. A field may
accept several files (the form builder's `maxFiles` property); every upload gets its
own stored name, so repeated uploads against one session accumulate.

Files are handed to the SDK as a `FileUpload` — `AsterMD\Sdk\Http\FileUpload`:

```php
use AsterMD\Sdk\Http\FileUpload;

// From a path — file name and MIME type are derived from it.
$upload = FileUpload::fromPath('/var/uploads/id-front.jpg');

// From bytes already in memory — the file name is required.
$upload = FileUpload::fromContents($bytes, 'id-front.jpg', 'image/jpeg');
```

**Small files — one request.** `uploadFile()` takes up to 10 MB and accepts JPEG,
PNG, WebP, GIF, PDF, DOC, DOCX, and plain text:

```php
$result = $client->intakeSubmissions()
    ->uploadFile($sessionId, FileUpload::fromPath('/var/uploads/id-front.jpg'))
    ->data();

// $result === ['path' => '…/id-front-<uuid>.jpg', 'name' => '…', 'mime_type' => 'image/jpeg']
```

**Large files — the multipart flow.** Video answers (`video/mp4`,
`video/quicktime`, `video/webm`, up to 200 MB) go through a chunked multipart upload.
`uploadLargeFile()` runs the whole flow for you, streaming the file from disk one
part at a time so memory use stays at `$partSize` regardless of file size, and
aborting the upload if any step fails:

```php
$result = $client->intakeSubmissions()
    ->uploadLargeFile($sessionId, '/var/uploads/consult-video.mp4')
    ->data();
```

Drive the four calls yourself when you need parts uploaded in parallel, or an upload
resumed in another process:

1. `initiateMultipartUpload()` → returns `upload_id`.
2. `uploadMultipartPart()` once per chunk, in any order → returns `{part_number, etag}`.
   Collect every pair; the server does not track them.
3. `finishMultipartUpload()` with the full set → returns `{path, name, mime_type}`.
4. `abortMultipartUpload()` instead, to cancel — idempotent, and safe to call after a
   finish or a previous abort.

Part sizes must be between 5 MB (`IntakeSubmissions::MIN_PART_SIZE`, the minimum the
server accepts for any non-final part) and 25 MB (`MAX_PART_SIZE`, its cap); the final
part may be smaller. Pass each ETag back exactly as received, surrounding quote characters
included.

Uploaded bytes are **PHI**. The SDK never writes them to a debug log, even with
`debug: true`.

### `carts()`

| Method                                                       | HTTP | Path                                  |
| ------------------------------------------------------------ | ---- | ------------------------------------- |
| `create(string $session, array $items): Response`            | POST | `/v1/sales/carts/create`              |
| `update(string $session, array $items): Response`            | PUT  | `/v1/sales/carts/update/{session}`    |

`items` is a list of items. Each item: `product_id` (string), `name` (string), `qty` (int) — all required. The journey event and `channel_id` are derived server-side, so the SDK does not send them.

### `teleforms()`

| Method                                           | HTTP | Path                                        | Notes                                        |
| ------------------------------------------------ | ---- | ------------------------------------------- | -------------------------------------------- |
| `viewByIdentifier(string $identifier): Response` | GET  | `/v1/sales/teleforms/view-url/{identifier}` | Look up a teleform by its slug |
| `view(string $id): Response`                     | GET  | `/v1/sales/teleforms/view/{id}`             |                                              |

### `patients()`

| Method                                                                                 | HTTP  | Path                                                    |
| -------------------------------------------------------------------------------------- | ----- | ------------------------------------------------------- |
| `create(array $data): Response`                                                        | POST  | `/v1/sales/patients/create`                              |
| `view(string $id): Response`                                                           | GET   | `/v1/sales/patients/view/{id}`                           |
| `update(string $id, array $data): Response`                                            | PUT   | `/v1/sales/patients/update/{id}`                         |
| `status(string $id, string $status): Response`                                         | PATCH | `/v1/sales/patients/status/{id}`                         |
| `submitHealthInformation(array $data, ?string $phiVerificationToken = null): Response` | POST  | `/v1/sales/patients/health-information`                  |
| `verifyHealthInformationOtp(array $data): Response`                                    | POST  | `/v1/sales/patients/health-information/otp-verification` |

`submitHealthInformation` sends the optional token as the
`x-phi-verification-token` HTTP header when provided.

### `opportunities()`

| Method                                         | HTTP  | Path                                 | Notes                               |
| ---------------------------------------------- | ----- | ------------------------------------ | ----------------------------------- |
| `create(array $data): Response`                | POST  | `/v1/sales/opportunities/create`      | Accepts `sessions` (array of UUIDs) |
| `view(string $id): Response`                   | GET   | `/v1/sales/opportunities/view/{id}`   |                                     |
| `update(string $id, array $data): Response`    | PUT   | `/v1/sales/opportunities/update/{id}` |                                     |
| `status(string $id, string $status): Response` | PATCH | `/v1/sales/opportunities/status/{id}` | Sends `{opportunity_status: ...}`   |

### `treatments()`

| Method                                                             | HTTP | Path                             |
| ------------------------------------------------------------------ | ---- | -------------------------------- |
| `create(array $data): Response`                                    | POST | `/v1/sales/treatments/create`    |
| `view(string $id): Response`                                       | GET  | `/v1/sales/treatments/view/{id}` |
| `list(array $query = []): Response`                                | GET  | `/v1/sales/treatments/list`      |
| `sync(string $session, array $orderIds, string $userAgent, ?string $utmSource = null, ?array $payment = null, ?array $verification = null): Response` | POST | `/v1/sales/treatments/sync`      |

Use `sync()` to push an order settled in an external CRM into AsterMD after settlement happens outside the SDK.
`$userAgent` is required by the API. `$payment` and `$verification` are optional and
carry the settled payment method and any identity/contact verification already
performed by the caller.

### `doctorsNetworks()`

| Method                           | HTTP | Path                              |
| -------------------------------- | ---- | --------------------------------- |
| `sync(array $payload): Response` | POST | `/v1/sales/doctors-networks/sync` |

Client-side: strips `emit_opportunity`; requires `opportunity_id` OR
`user_info.first_name` + `user_info.email`.

### `channels()`

| Method                                   | HTTP | Path                                        |
| ---------------------------------------- | ---- | ------------------------------------------- |
| `view(string $id): Response`             | GET  | `/v1/sales/channels/view/{id}`              |
| `details(string $id): Response`          | GET  | `/v1/sales/channels/detail/{id}`            |
| `assignedProducts(string $id): Response` | GET  | `/v1/sales/channels/assigned-products/{id}` |

> `details()` returns the channel's expanded record (channel + embedded integrations / theming / related references resolved server-side) in one round-trip — use it when you want everything `view()` returns plus the nested associations. Note the URL path is `detail` (singular) by server convention; the SDK method is `details()`.

### Catalog (read-only)

| Resource        | `list()`                     | `view()`                         |
| --------------- | ---------------------------- | -------------------------------- |
| `products()`    | `/v1/sales/products/list`    | `/v1/sales/products/view/{id}`   |
| `categories()`  | `/v1/sales/categories/list`  | `/v1/sales/categories/view/{id}` |
| `labTests()`    | `/v1/sales/lab-tests/list`   | `/v1/sales/lab-tests/view/{id}`  |
| `medications()` | `/v1/sales/medications/list` | — (list only)                    |
| `shippings()`   | `/v1/sales/shippings/list`   | `/v1/sales/shippings/view/{id}`  |

All `list()` methods accept an `array $query` argument that is encoded as the
query string (e.g. `['page' => 2, 'limit' => 50]` → `?page=2&limit=50`).

### `verification()`

| Method                                                        | HTTP | Path                                        |
| ------------------------------------------------------------- | ---- | ------------------------------------------- |
| `autofillAddress(string $search): Response`                    | GET  | `/v1/platform/extensions/address-autofill`  |
| `verifyAddress(string $address): Response`                     | GET  | `/v1/platform/extensions/address-verify`    |
| `verifyEmail(string $email): Response`                         | GET  | `/v1/platform/extensions/email-verify`      |
| `verifyIdentity(IdentityCheck $check, array $data): Response`   | POST | `/v1/platform/extensions/identity-verify`   |

`verifyIdentity()` takes an `IdentityCheck` enum that sets the request's `slug`
and determines which fields `$data` must carry:

| `IdentityCheck` case | Required in `$data`                     | Optional                             |
| -------------------- | --------------------------------------- | ------------------------------------ |
| `Crosscheck`         | `firstName`, `lastName`                 | `email`, `phone`, `ipAddress`, `address` |
| `DobVerify`          | `firstName`, `lastName`, `phone`, `dob` | `email`, `address`                   |
| `SsnVerify`          | `firstName`, `lastName`, `phone`, `ssn` | `email`, `dob`, `address`            |

The result is provider-agnostic apart from `raw` — read `valid` for the verdict
(`null` when the provider answered but returned nothing decisive), `basis` for
which rule decided it, `score`/`threshold` for scored checks, and `reasons` for
coded explanatory signals.

### `geo()`

| Method                             | HTTP | Path                                     |
| ---------------------------------- | ---- | ---------------------------------------- |
| `info(string $ip): Response`       | GET  | `/v1/platform/extensions/geo-info`       |
| `blocklist(string $ip): Response`  | GET  | `/v1/platform/extensions/geo-blocklist`  |

Both reject private and reserved IP ranges, and both return the provider's
hyphenated keys verbatim (`region-code`, `is-listed`, …).

> Every method in these two sections requires the matching integration to be
> active for your organization; when it is not, the call fails with
> `ApiException` (HTTP 403). Invalid input on these endpoints is reported as
> HTTP 400, which also surfaces as `ApiException` rather than
> `ValidationException` — see [§7 Error handling](#7-error-handling).

---

## 6. Auth lifecycle

The SDK trades `client_id` + `client_secret` for a short-lived JWT at
`POST /v1/auth/api-credentials/token`. It manages the token automatically.

What you don't have to think about:

- **First call:** SDK lazily acquires the token.
- **Subsequent calls:** SDK reuses the cached token.
- **30-second pre-buffer:** SDK treats the token as expired 30s before its
  stated `access_token_expiry` to avoid clock-skew races.
- **401 in flight:** SDK re-authenticates once and retries the original
  request. If the second attempt also fails, you get `AuthenticationException`.
- **`org_id`:** The org is encoded inside the JWT by the server. You never
  thread it through requests.

### Token storage

By default the token lives in an `InMemoryTokenStore` — fine for CLI scripts
and worker processes, but every fresh PHP request will re-authenticate because
PHP is share-nothing: each request is an isolated process with no shared memory.

For web applications, choose a shared store. The options in recommended order:

| Option    | Class                       | Requires                  | Notes                                                        |
| --------- | --------------------------- | ------------------------- | ------------------------------------------------------------ |
| **Redis** | write your own (see below)  | Redis server + client lib | Recommended. TTL-native, no filesystem exposure, network ACL |
| **APCu**  | write your own (see below)  | `php-apcu` extension      | Good for single-server; no extra service needed              |
| **File**  | `FileTokenStore` (built-in) | Nothing                   | Works anywhere; needs filesystem hardening (see below)       |

---

#### Option 1 — Redis (recommended)

Redis is the safest choice: the token never touches the filesystem, access is
controlled by Redis auth and network rules, and TTL expiry is handled natively.

```php
namespace App\AsterMD;

use AsterMD\Sdk\Auth\Token;
use AsterMD\Sdk\Auth\TokenStore;
use DateTimeImmutable;
use Redis;

final class RedisTokenStore implements TokenStore
{
    public function __construct(
        private readonly Redis $redis,
        private readonly string $key = 'astermd_jwt',
    ) {}

    public function get(): ?Token
    {
        $raw = $this->redis->get($this->key);
        if (!is_string($raw)) {
            return null;
        }
        try {
            $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return new Token($payload['value'], new DateTimeImmutable($payload['expires_at']));
    }

    public function put(Token $token): void
    {
        $ttl = max(1, $token->expiresAt()->getTimestamp() - time());
        $this->redis->setex($this->key, $ttl, json_encode([
            'value'      => $token->value(),
            'expires_at' => $token->expiresAt()->format(DATE_ATOM),
        ]));
    }

    public function clear(): void
    {
        $this->redis->del($this->key);
    }
}
```

```php
$client = new AsterMDClient(
    clientId:     '…',
    clientSecret: '…',
    tokenStore:   new App\AsterMD\RedisTokenStore($redis),
);
```

---

#### Option 2 — APCu (single-server, no extra service)

APCu is a PHP extension (`php-apcu`) that provides shared memory across all
worker processes on a single server. No Redis, no files, no extra service.

```php
namespace App\AsterMD;

use AsterMD\Sdk\Auth\Token;
use AsterMD\Sdk\Auth\TokenStore;
use DateTimeImmutable;

final class ApcuTokenStore implements TokenStore
{
    public function __construct(private readonly string $key = 'astermd_jwt') {}

    public function get(): ?Token
    {
        $payload = apcu_fetch($this->key);
        if (!is_array($payload)) {
            return null;
        }
        return new Token($payload['value'], new DateTimeImmutable($payload['expires_at']));
    }

    public function put(Token $token): void
    {
        $ttl = max(1, $token->expiresAt()->getTimestamp() - time());
        apcu_store($this->key, [
            'value'      => $token->value(),
            'expires_at' => $token->expiresAt()->format(DATE_ATOM),
        ], $ttl);
    }

    public function clear(): void
    {
        apcu_delete($this->key);
    }
}
```

```php
$client = new AsterMDClient(
    clientId:     '…',
    clientSecret: '…',
    tokenStore:   new App\AsterMD\ApcuTokenStore(),
);
```

---

#### Option 3 — File cache (built-in, no dependencies)

`FileTokenStore` ships with the SDK. Use it when neither Redis nor APCu is
available — for example, shared hosting environments where you cannot install
extensions or run additional services.

```php
use AsterMD\Sdk\Auth\FileTokenStore;

$client = new AsterMDClient(
    clientId:     '…',
    clientSecret: '…',
    tokenStore:   new FileTokenStore(__DIR__ . '/../storage/astermd/token.json'),
);
```

**The file must not be publicly accessible.** The SDK sets `0600` permissions
on the file automatically, but you also need to ensure the web server will not
serve it as a static file:

- **Preferred layout**: keep the file outside the web root (e.g., `storage/` sits
  next to `public/`, not inside it). This is the Laravel/Symfony convention and
  works on any VPS or cloud environment.
- **If you must stay within the document root**: block the directory in your
  server config.

  _nginx:_

  ```nginx
  location ^~ /storage/ {
      deny all;
      return 404;
  }
  ```

  _Apache `.htaccess` inside `storage/`:_

  ```apacheconf
  Deny from all
  ```

**Remaining risk on shared hosting**: `0600` ensures OS-level isolation between
users on the same server. On properly configured shared hosts this is sufficient.
If your host does not enforce per-account user isolation (rare but possible),
prefer Redis or APCu, or rotate your `client_secret` frequently.

---

## 7. Error handling

All SDK exceptions extend `AsterMD\Sdk\Exception\AsterMDException`.

```php
use AsterMD\Sdk\Exception\{
    AsterMDException,
    TransportException,
    AuthenticationException,
    NotFoundException,
    ValidationException,
    RateLimitException,
    ApiException,
};

try {
    $response = $client->patients()->create($payload);
} catch (ValidationException $e) {
    // 422 — fix and retry
    foreach ($e->fieldErrors() as $field => $messages) {
        // $messages is array<string>
    }
} catch (NotFoundException $e) {
    // 404
} catch (RateLimitException $e) {
    sleep($e->retryAfter() ?? 5);
    // retry…
} catch (AuthenticationException $e) {
    // Re-auth failed; check that client_id/client_secret are correct.
} catch (TransportException $e) {
    // No HTTP response — DNS / TLS / timeout. Retry with backoff.
} catch (ApiException $e) {
    // Other 4xx/5xx. Inspect $e->statusCode() and $e->envelope().
}
```

Hierarchy:

```
AsterMDException (abstract)
├── TransportException
└── ApiException                ← carries statusCode() + envelope()
    ├── AuthenticationException (401)
    ├── NotFoundException       (404)
    ├── ValidationException     (422) ← + fieldErrors()
    └── RateLimitException      (429) ← + retryAfter()
```

`TransportException` is intentionally NOT an `ApiException` — no HTTP response
was ever received, so there is no `statusCode` to inspect.

Only HTTP 422 becomes a `ValidationException`. The `verification()` and `geo()`
endpoints report invalid input as HTTP 400 and an inactive integration as HTTP
403, so both arrive as a plain `ApiException` — check `$e->statusCode()` to tell
them apart.

---

## 8. Framework recipes

### Laravel

`config/services.php`:

```php
return [
    // …
    'astermd' => [
        'client_id'     => env('ASTERMD_CLIENT_ID'),
        'client_secret' => env('ASTERMD_CLIENT_SECRET'),
        'base_host'     => env('ASTERMD_BASE_HOST'), // null on prod
    ],
];
```

`app/Providers/AsterMDServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use AsterMD\Sdk\AsterMDClient;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

final class AsterMDServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AsterMDClient::class, function (Container $app): AsterMDClient {
            $config = $app['config']['services.astermd'];

            return new AsterMDClient(
                clientId:     $config['client_id'],
                clientSecret: $config['client_secret'],
                baseHost:     $config['base_host'] ?: null,
                tokenStore:   new \App\AsterMD\ApcuTokenStore(),
            );
        });
    }
}
```

Register it in `bootstrap/providers.php` (Laravel 11+) or `config/app.php`
(older versions). Inject `AsterMDClient` into controllers via the constructor.

### Symfony

`config/services.yaml`:

```yaml
services:
  AsterMD\Sdk\AsterMDClient:
    arguments:
      $clientId: "%env(ASTERMD_CLIENT_ID)%"
      $clientSecret: "%env(ASTERMD_CLIENT_SECRET)%"
      $baseHost: "%env(default::ASTERMD_BASE_HOST)%"
      $tokenStore: '@App\AsterMD\ApcuTokenStore'
```

Then type-hint `AsterMDClient` in any service constructor.

### Plain PHP

Just `new AsterMDClient(...)` once at the top of your bootstrap and pass it
around. There is no global state in the SDK.

---

## 9. Custom transport (BYO PSR-18)

Pass any PSR-18 client into the constructor:

```php
use AsterMD\Sdk\AsterMDClient;
use GuzzleHttp\Client;
use Nyholm\Psr7\Factory\Psr17Factory;

$factory = new Psr17Factory();
$client  = new AsterMDClient(
    clientId:        '…',
    clientSecret:    '…',
    httpClient:      new Client(['timeout' => 10]),   // Guzzle
    requestFactory:  $factory,
    streamFactory:   $factory,
);
```

The default `AsterMD\Sdk\Http\NativeCurlClient` exists so the SDK has zero
network dependencies; you only need to swap it if your app already standardizes
on another client.

---

## 10. Debugging — copy-paste-able curl logs

When you need to reproduce a failing request in Postman or `curl`, pass
`debug: true` at construction. Every outbound request — including the
token-acquisition call — is logged in a copy-pasteable format.

**Credentials are redacted by default.** Bearer tokens, the `client_secret` in
the token-exchange body, `x-phi-verification-token` headers, and `access_token` /
`refresh_token` values in responses are replaced with `[REDACTED]`. Request and
response bodies on `/patients/*` endpoints are dropped entirely, because they
carry PII and PHI.

> **What is still logged.** Redaction is not anonymisation. Entries retain full
> URLs, non-sensitive headers, and the bodies of every non-patient endpoint —
> which includes contact details on `opportunities/*`. Treat the log destination
> as sensitive, and apply the same access controls and retention rules you would
> to any other store of customer data. Never commit log files to version control.

### Parameters

| Parameter            | Type       | Default | Purpose                                                                          |
| -------------------- | ---------- | ------- | -------------------------------------------------------------------------------- |
| `debug`              | `bool`     | `false` | Master toggle. All other debug params are ignored when `false`.                  |
| `debugFile`          | `?string`  | `null`  | Base path for the log file. The SDK writes one dated file per day from it.       |
| `debugSink`          | `?Closure` | `null`  | `fn(string $entry): void`. Takes precedence over `debugFile` when set.           |
| `debugTimezone`      | `?string`  | `null`  | IANA timezone for timestamps and for the file date. Default `'UTC'`.             |
| `debugRedact`        | `bool`     | `true`  | Mask credentials and PHI. Set `false` to log verbatim — never in production.     |
| `debugRetentionDays` | `int`      | `7`     | Days of daily log files to keep. `0` keeps everything. Ignored with `debugSink`. |

Either `debugFile` or `debugSink` must be provided when `debug: true`; omitting
both throws `InvalidArgumentException` at construction.

### Example — file-based logging with daily rotation

```php
use AsterMD\Sdk\AsterMDClient;

$client = new AsterMDClient(
    clientId:     'your-client-id',
    clientSecret: 'your-client-secret',
    debug:        true,
    debugFile:    '/var/log/astermd/sdk.log',   // never inside the web root
    debugRetentionDays: 7,                      // 0 keeps every file
    // debugTimezone: 'America/New_York',        // optional; UTC is the default
);
```

`debugFile` is a **base path**, not the file that gets written. The SDK derives a
dated filename from it, keeping your directory, stem, and extension:

```
/var/log/astermd/sdk-2026-08-08.log   ← today
/var/log/astermd/sdk-2026-08-07.log
/var/log/astermd/sdk-2026-08-01.log   ← removed once older than the retention window
```

Follow today's file with
`tail -f /var/log/astermd/sdk-$(date -u +%F).log`.

Pruning runs once per PHP process, on the first entry written, so it costs
nothing on the hot path. Only files matching the SDK's own
`{stem}-YYYY-MM-DD.{ext}` pattern are ever deleted, and the date comes from the
filename rather than the modification time — so a file that was merely touched
still ages out on schedule, and nothing else in the directory is at risk.

The parent directory is created if missing. If it cannot be created or written
to, construction throws rather than silently discarding your logs.

### Example — sending logs somewhere other than a file

Supply a `debugSink` closure and the SDK writes no files at all. Rotation,
retention, and delivery become your application's concern. This is the extension
point for **any** destination — a PSR-3 logger, a hosted log service, a cloud
provider's logging API, a message queue — and it requires no change inside the
SDK.

Into an existing PSR-3 logger:

```php
$client = new AsterMDClient(
    clientId:     'your-client-id',
    clientSecret: 'your-client-secret',
    debug:        true,
    debugSink:    static fn (string $entry) => $logger->debug($entry),
);
```

Into a hosted log service over HTTP. Buffer in memory and flush once on
shutdown — a synchronous POST per request would add a network round trip to
every API call:

```php
final class BufferedHttpLogSink
{
    /** @var list<array{_time: string, service: string, entry: string}> */
    private array $buffer = [];

    public function __construct(private readonly \Closure $flush)
    {
        register_shutdown_function(fn () => $this->flush());
    }

    public function __invoke(string $entry): void
    {
        $this->buffer[] = [
            '_time'   => gmdate('c'),
            'service' => 'astermd-sdk',
            'entry'   => $entry,
        ];
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        ($this->flush)($this->buffer);
        $this->buffer = [];
    }
}

$sink = new BufferedHttpLogSink(
    // POST the batch to your log provider's ingest endpoint.
    fn (array $events) => $yourHttpClient->post($ingestUrl, $events),
);

$client = new AsterMDClient(
    clientId:     'your-client-id',
    clientSecret: 'your-client-secret',
    debug:        true,
    debugSink:    $sink(...),
);
```

Whatever the destination, remember that entries are debug material: keep
`debugRedact` at its default, and do not ship them to a system with weaker access
controls than your application database.

### Timezone option

Timestamps and log-file dates default to UTC. Pass any IANA timezone string:

```php
$client = new AsterMDClient(
    clientId:     'your-client-id',
    clientSecret: 'your-client-secret',
    debug:        true,
    debugFile:    '/var/log/astermd/sdk.log',
    debugTimezone: 'Asia/Kolkata',   // timestamps show IST; files roll at IST midnight
);
```

An invalid timezone string causes `DateTimeZone` to throw at construction.

### Log format

Each entry is a blank-line-terminated block:

```
[2026-08-08 14:23:51.123456 UTC]
curl --location --request POST 'https://api.astermd.com/v1/sales/sessions/create' \
  --header 'Accept: application/json' \
  --header 'Authorization: Bearer [REDACTED]' \
  --header 'Content-Type: application/json' \
  --data '{"data":{}}'

# Response: HTTP 200
{"success":true,"message":"ok","data":{"session":"abc"},"meta":{}}

```

Paste the `curl …` block into a terminal or Postman. You will need to substitute
a real token for `[REDACTED]` before it will run; obtain one the same way the SDK
does, or re-run with `debugRedact: false` against non-production credentials.

If the SDK threw a transport error instead of receiving an HTTP response, the
block ends with `# Transport error: <message>` in place of the response section.

---

## Appendix — Common gotchas

- **Don't pass full URLs as `baseHost`.** Just the host (`api.astermd.com`). The SDK builds the rest.
- **`opportunities()->status()` body is `{opportunity_status: ...}`**, not `{status: ...}`. The `patients()->status()` body IS `{status: ...}`. They differ because the APIs differ.
- **For multi-step teleforms, send the FULL accumulated `data` on every update.** The server treats each update as a full snapshot, not a delta.
- **`Medications` has no `view()`** — list only.
- **Token store is in-memory by default.** Web apps almost always want a shared store; see §6.
- **`AuthenticationException` after one auto-retry is final.** The SDK does not loop; treat it as a configuration error.
- **PHP empty arrays encode as JSON `[]`, not `{}`.** If an endpoint requires a JSON object and you have no fields, pass `(object) []` (a stdClass instance) so PHP encodes it as `{}`. The SDK already handles the known case: `sessions()->create()` coerces empty `data` to `{}` internally. (`intakeSubmissions()->create()` / `->update()` take a list of field objects, so their empty `data` is correctly sent as `[]`.) If you hit a `validation.isObject` 400 on any other endpoint, that's the cause — wrap the empty field with `(object) []`.
- **`sessions()->view()` takes an array of IDs.** `view(['uuid'])` for a single lookup, `view(['a','b'])` to batch-fetch; pass an IANA tz as the 2nd arg (`view(['uuid'], 'Asia/Kolkata')`) to format response timestamps. The real route is `/sessions/view?session_ids=…` (comma-joined).
- **Forward the visitor's `User-Agent` and IP yourself.** The SDK runs server-to-server, so it cannot see the visitor's browser or IP. For device/geo attribution, pass `$_SERVER['HTTP_USER_AGENT']` and `$_SERVER['REMOTE_ADDR']` to `sessions()->create(userAgent: …, clientIp: …)` — the SDK sends them as the `User-Agent` and `X-Original-Client-Ip` headers. Each header is omitted when its arg is null/empty. `treatments()->sync()` also takes a `$userAgent`, but there it's a required argument — the API rejects the call without it.
