# CLAUDE.md — AsterMD PHP SDK

Guidance for any AI agent (Claude Code, Copilot, etc.) working in this repo.
Read `docs/ARCHITECTURE.md` first — it is the source of truth for scope and
architecture.

---

## 1. What this package is

`astermd/sdk` — a slim, framework-agnostic PHP SDK wrapping the order-flow
portion of the AsterMD API (auth, sessions, intake, patients, opportunities,
treatments, and the read-only catalog). It is _not_ a wrapper for the entire
API; treat it like Stripe's SDK — a curated public surface, not exhaustive.

See `docs/INTEGRATION_GUIDE.md` for the endpoint list and
`docs/ARCHITECTURE.md` for the rationale.

## 2. Non-negotiable constraints

- **PHP `>=8.4`, recommended 8.5.** Don't introduce syntax requiring newer.
- **No Guzzle.** The default HTTP transport is the native-cURL PSR-18 client in
  `src/Http/NativeCurlClient.php`. Consumers may inject their own PSR-18 client;
  the SDK depends only on `psr/http-*` interfaces and `nyholm/psr7`.
- **Zero runtime deps beyond `psr/http-{client,factory,message}` and
  `nyholm/psr7`.** Any new runtime dependency requires explicit maintainer
  approval.
- **`declare(strict_types=1);` on every PHP file.** No exceptions.
- **PSR-12 style**, enforced by PHP-CS-Fixer. **PSR-4 autoload** from `src/`
  under namespace `AsterMD\Sdk\`.
- **The supported toolchains are Docker (default) and native PHP
  (`DOCKER=0 make ...`).** Never bypass `make` by calling `php`/`composer`/
  `phpunit` directly. New tooling must run under both modes.

## 3. Documentation style — PHPDoc and comments

The project follows a Stripe-PHP-grade docblock convention. Every public class
and public method gets a rich PHPDoc block.

- **Class-level**: multi-paragraph business description. What does this thing
  represent? What is it for? Where does it sit in the order flow? Include a usage
  example where it aids understanding (e.g. `AsterMDClient`, `FileTokenStore`).
- **Method-level**: 2–4 sentence prose description (what + when in the order flow
  + side effects on the server + what to inspect on the returned `Response`),
  plus:
  - `@param` on **every** parameter, even obvious scalars. For `array` params:
    - If the SDK builds the body from known keys, write the inline
      `array{key?: type}` shape.
    - If the shape is a caller-supplied pass-through (e.g. `Patients::create($data)`),
      use `array<string, mixed>` with the known top-level keys documented in
      prose, plus a pointer to the API reference in the AsterMD dashboard.
    - Limit shapes to one level of nesting to keep annotations maintainable.
  - `@return` always present, with a brief phrase describing what `data()`
    contains.
  - `@throws` for everything that can actually fire. Every resource method gets
    `\AsterMD\Sdk\Exception\ApiException` and
    `\AsterMD\Sdk\Exception\TransportException` at minimum. Add
    `ValidationException`, `NotFoundException`, etc. where relevant.
    `DoctorsNetworks::sync` additionally throws `\InvalidArgumentException`.
- **Internal classes**: same standard. `Transport`, `TokenManager`,
  `NativeCurlClient`, `CurlLoggingClient`, `LogRedactor`, `DailyFileLogSink`,
  `FileTokenStore` all get full docblocks because contributors read them.
- **Private helpers**: skip PHPDoc when the typed signature is self-documenting.
  Add it when the behaviour is non-obvious (a specific algorithm, a workaround
  for a known server quirk, a deliberate invariant).
- **Inline comments** stay exceptional — write them only when the *why* is
  non-obvious. Never restate what the code already says.

Reference: `src/Resource/Sessions.php` and `src/Resource/DoctorsNetworks.php`
are the canonical examples — match their depth and shape for new resources.

## 4. Architectural rules

- Each resource class wraps one logical API surface and extends
  `AbstractResource`, depending only on `Transport`.
- `Transport` is the **only** place that builds requests, applies the JWT,
  decodes the success envelope, and maps HTTP status codes to exceptions.
  Resources never touch HTTP, JSON, or PSR-7 types directly.
- `TokenManager` is the only place that exchanges credentials for a JWT. It
  auto-retries once on 401, then raises `AuthenticationException`. The token is
  treated as expired 30 seconds before its stated `access_token_expiry` to avoid
  clock-skew races.
- `org_id` lives **inside the JWT**. The SDK must never accept or thread an
  `org_id` argument. If a future endpoint appears to need one, ask first.
- Base host comes from `Config::baseHost` (default `api.astermd.com`). The SDK
  accepts a host only and appends `/v1/{service}/{path}` per call. Never accept
  full URLs from consumers.
- Internal classes (`Transport`, `NativeCurlClient`, `UrlBuilder`,
  `TokenManager`, `LogRedactor`, `DailyFileLogSink`) are `final`. Resource
  classes are non-final so consumers can extend them.
- Value objects (`Config`, `Token`, `Response`) are immutable.
- **Debug logging is opt-in via `debug: true`.** `CurlLoggingClient` wraps the
  PSR-18 client BEFORE it reaches `TokenManager`, so the auth call is logged
  too. Redaction via `LogRedactor` is **on by default**; `debugRedact: false`
  disables it.

## 5. Testing rules

- Every public method on every resource has a unit test.
- Tests use a **mock PSR-18 client** — never make real HTTP calls.
- Each resource test asserts: URL (host + `/v1/{service}/{path}`), HTTP method,
  headers (`Authorization: Bearer …`, `Content-Type`, optional
  `x-phi-verification-token`), and request body shape.
- `TokenManager` tests cover: lazy acquisition, expiry pre-buffer, single 401
  auto-retry, second-401 → `AuthenticationException`.
- `Transport` tests cover envelope decoding and one assertion per
  HTTP-status-to-exception mapping.
- `LogRedactor` tests cover every sensitive header and field, and that
  `patients/*` bodies are dropped.
- `DailyFileLogSink` tests cover dated filenames, appending, retention pruning,
  and that unrelated files are never deleted.
- `UrlBuilder` tests cover the default host and a custom host override.

CI gate, in order: `php-cs-fixer --dry-run --diff` → `phpstan analyse --level max`
→ `phpunit`. Any failure blocks release.

## 6. Security & privacy

- Never log `client_secret`, `access_token`, OTP codes, or PHI fields.
  `LogRedactor` enforces this for debug output; keep it that way.
- Never log full request/response bodies for `patients/*` endpoints or any
  endpoint carrying `x-phi-verification-token`.
- **Never hardcode a key, secret, token, or credential in source** — not even as
  a default or a placeholder "for now". Cryptographic keys are caller-supplied
  parameters.
- Do not embed real credentials in fixtures or tests — use obviously fake
  placeholders.
- Do not document internals of token construction, hashing, or datastore layout.
  The SDK exposes the public _interface_ only.

## 7. Public-repository hygiene

This repository is public. Nothing in a tracked file may reveal internal
infrastructure, tooling, or roadmap:

- No internal hostnames, bucket names, datastore or message-broker names, or
  named third-party vendors.
- No non-production environments. Every host and URL in docs and code is
  production.
- No references to internal issue trackers, private repositories, or internal
  specification files. Point readers at **the API reference in the AsterMD
  dashboard** instead.
- No "pending", "not yet implemented", or "coming soon" notes. If a capability
  is not shipped, the public docs are silent about it.
- All support, security, and licensing enquiries go to **info@astermd.com**.

## 8. Workflow expectations

- For any non-trivial change, read the doc covering the area first
  (`docs/ARCHITECTURE.md` or `docs/INTEGRATION_GUIDE.md`); if it is stale, update
  it in the same change.
- Add a `CHANGELOG.md` entry under `## [Unreleased]` for user-visible changes.
- Run `make ci` before declaring work done. Don't claim a fix without the green
  output to back it up.
- Default to _no_ new files. Prefer editing existing ones. Don't create
  speculative abstractions — the package is intentionally small.
