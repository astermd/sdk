# Contributing — astermd/sdk

Short guide for adding a new endpoint, fixing a bug, or otherwise working on
this package. **Read `CLAUDE.md` first** for the load-bearing conventions
(PHP 8.4+, no Guzzle, Docker-first local setup, PSR-12, strict types, PHPDoc
on the public surface).

## Local setup

Two supported environments. Docker is the default; native PHP is a one-flag
opt-out. Both run the same `make` targets — only the execution context differs.

### Option A — Docker (default; no host PHP required)

```bash
make build       # build the PHP 8.4 image (the minimum supported version)
make install     # composer install inside the container
make ci          # lint + stan + test (mirrors release gate)
```

Other targets: `make test`, `make lint`, `make fix`, `make stan`, `make shell`.

### Option B — Native PHP on the host

Requirements:

- PHP `>=8.4` (8.5 recommended)
- Composer
- Extensions: `ext-curl`, `ext-json`, `ext-mbstring`, `ext-openssl`

```bash
DOCKER=0 make install   # composer install on host
DOCKER=0 make ci        # lint + stan + test on host

# Or export it once per shell session:
export DOCKER=0
make ci
```

`make build` and `make shell` become no-ops in this mode (`DOCKER=0` prints a
notice). Everything else is identical to the Docker path.

### Switching between the two

The two workflows share `vendor/` because the host mount and the container's
`/app` point to the same files. That means a `vendor/` populated by one path
is reused by the other — but if you hit a `PHP_VERSION_ID` constraint mismatch
(rare), run `rm -rf vendor composer.lock && make install` in the mode you intend
to use going forward.

## Branching and commits

- Work on a feature branch off `feature/init` (current default) or whatever
  the maintainer designates.
- One logical change per commit. Use Conventional Commits prefixes: `feat:`,
  `fix:`, `chore:`, `docs:`, `test:`, `refactor:`.
- Every commit must keep `make ci` green. If you tee multiple commits, run
  `make ci` on the last one before pushing.

## Adding a new endpoint

Most contributions will be "wrap one more endpoint." The pattern is mechanical:

### 1. Confirm the contract exists

Confirm the endpoint and its request/response schema in the API reference in
your AsterMD dashboard. If the contract is not published there, stop and ask —
never infer a request shape from a sibling endpoint or from a response you
happened to observe. A guessed shape that works today is a breaking change
waiting to happen.

### 2. Write the failing test first

Create or extend `tests/Unit/Resource/{Name}Test.php`. Use the shared
`tests/Support/MockHttpClient.php` helper. The template:

```php
public function testNewMethodSendsExpectedRequest(): void
{
    $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

    $this->resource->newMethod('arg-1', ['k' => 'v']);

    $req = $this->http->lastRequest();
    self::assertSame('POST', $req->getMethod());
    self::assertSame(
        'https://api.astermd.com/v1/{service}/{path}',
        (string) $req->getUri(),
    );
    self::assertJsonStringEqualsJsonString('{"k":"v"}', (string) $req->getBody());
}
```

Run `make test` and verify the test fails for the right reason (method does not
exist yet).

### 3. Implement the method on the resource

```php
/**
 * One-line purpose. PHPDoc here only if it adds info beyond the signature.
 *
 * @param array<string, mixed> $data
 */
public function newMethod(string $id, array $data): Response
{
    return $this->transport->send(
        '{service}',                       // 'auth' | 'sales' | 'platform'
        'POST',                             // method
        '/{module}/new-method/{id}',        // path template
        pathParams: ['id' => $id],
        body:       $data,
        // query:   [...],                   // when applicable
        // headers: [...],                   // when applicable (e.g. x-phi-verification-token)
    );
}
```

Rules:

- Resources only know about `Transport`. Never call PSR-18 / cURL / `json_*` from a resource.
- Return `AsterMD\Sdk\Response`. Do not build a typed DTO per endpoint.
- If client-side validation is genuinely needed (e.g. enforcing "either X or Y"), throw `\InvalidArgumentException` BEFORE calling the transport.

### 4. Add the accessor to `AsterMDClient` (only if adding a NEW resource)

```php
public function newResource(): \AsterMD\Sdk\Resource\NewResource
{
    return new \AsterMD\Sdk\Resource\NewResource($this->transport);
}
```

Resource classes extend `AbstractResource` and are non-final (consumers may
extend them). Infrastructure classes (`Transport`, `TokenManager`, `NativeCurlClient`,
`UrlBuilder`) stay `final`.

### 5. Run the full CI gate

```bash
make ci
```

Must end with `make lint` exit 0, `make stan` exit 0, and PHPUnit reporting
the new test passing. Do not commit until all three are green.

### 6. Update docs that name the endpoint

- `docs/INTEGRATION_GUIDE.md` §5 — add a row to the resource table.
- `docs/INTEGRATION_GUIDE.md` §4 — add to the order-flow walkthrough only if the new method belongs in that flow.
- `README.md` only if the change affects the resource list or quick-start.
- `CHANGELOG.md` — add an entry under `## [Unreleased]`.

### 7. Commit

```bash
git add src/Resource/NewResource.php tests/Unit/Resource/NewResourceTest.php \
        src/AsterMDClient.php docs/INTEGRATION_GUIDE.md
git commit -m "feat: add NewResource.newMethod()"
```

## Adding a new exception type

Only do this if a status code or condition needs distinct handling that
existing types can't express.

1. Create `src/Exception/NewException.php` extending `ApiException` (or
   `AsterMDException` directly for non-HTTP errors).
2. Add a case to the `match` expression in `src/Http/Transport.php::handle()`.
3. Add an assertion to `tests/Unit/Http/TransportTest.php`.
4. Update `docs/INTEGRATION_GUIDE.md` §7 hierarchy diagram and table.

## JSON encoding gotcha — empty array vs. empty object

PHP's `json_encode([])` produces the **JSON array literal `[]`**, never `{}`. AsterMD endpoints validate request bodies (and many nested fields) as JSON **objects**, so sending `[]` for an empty object yields a 400 (`validation.isObject`) or, for top-level empty bodies on `sessions/create`, a 500.

**Two layers of defense already in the SDK:**

1. `Transport::send()` coerces an empty top-level body array to `'{}'` before encoding (covers e.g. `sessions()->create()`).
2. Specific resources coerce known-object nested fields (e.g. `TeleformData` converts an empty `data` to `(object) []`).

**When you add a new endpoint that has a known-object nested field**, do the same:

```php
'someField' => $maybeEmpty === [] ? (object) [] : $maybeEmpty,
```

Do **NOT** generalise this into a Transport-wide recursive coercion — fields like `'sessions' => []` legitimately need to stay as the JSON array `[]` (an empty list, not an empty object). The "is this field an object or a list" decision belongs at the resource layer.

If you observe a `validation.isObject` 400 in the wild and trace it to an SDK-built nested field, add the `=== [] ? (object) [] : $x` coercion to that field in the relevant resource and add a regression test asserting `assertStringContainsString('"field":{}', $body)`.

## Static-analysis tips

PHPStan runs at `level: max`. Common friction patterns:

- `json_decode` returns `mixed`. Either guard with `is_array(...)` at the call
  site, or annotate with `/** @var array<string, mixed> $body */` immediately
  after the decode.
- Test closures (`tokenProvider`, `onUnauthorized`) — keep the return type
  matching what you actually return; `static fn (): ?string => 'x'` will be
  flagged because `null` is never returned.
- Avoid `mixed` in resource signatures. Prefer `array<string, mixed>` and let
  consumers cast at the boundary.

## When in doubt

- Look at how `Sessions`, `Patients`, or `DoctorsNetworks` are structured —
  they cover the three common shapes (simple CRUD, mixed methods including
  PHI header, and client-side validation).

## Releasing

Tags follow SemVer (`vMAJOR.MINOR.PATCH`). Packagist derives the version from
the tag, so `composer.json` must never contain a `version` field.

1. Ensure `main` is at the commit you want to ship and `make ci` is green.
2. Move the `## [Unreleased]` entries in `CHANGELOG.md` under the new version.
3. `git tag vX.Y.Z && git push --tags`.
4. Packagist publishes the new version automatically via the repository webhook.

Bugfixes get a PATCH bump. New endpoints get a MINOR bump. Breaking API
changes get a MAJOR bump — and please raise them on a PR first.
