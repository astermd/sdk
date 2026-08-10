# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.0.1] - 2026-08-10

First public release.

### Added

- `AsterMDClient` entry point wrapping the AsterMD order-flow API: sessions,
  intake submissions, carts, checkout events, teleforms, patients, opportunities,
  treatments, doctors' networks, channels, and the read-only catalog resources
  (products, categories, lab tests, medications, shipping options).
- `verification()` for address autofill and verification, email verification,
  and identity verification; `geo()` for IP geolocation and geo-blocklist
  checks.
- Native-cURL PSR-18 transport with no Guzzle dependency; any PSR-18 client can
  be injected instead.
- Automatic JWT acquisition, caching, and renewal via `TokenManager`, with
  pluggable `TokenStore` backends (`InMemoryTokenStore`, `FileTokenStore`).
- Redacted debug logging. `debug: true` renders every request as a
  copy-pasteable cURL command with bearer tokens, the OAuth2 client secret, PHI
  verification tokens, and `patients/*` bodies masked. Pass `debugRedact: false`
  to log verbatim.
- `DailyFileLogSink`: one debug log file per day, pruned after
  `debugRetentionDays` (default 7). Supply your own `debugSink` closure to route
  entries elsewhere instead.
- `QueryParamCipher` for encrypted URL query-param tokens.

### Requirements

- PHP 8.4 or newer; 8.5 recommended.
