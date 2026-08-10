# Security Policy

## Reporting a vulnerability

Please report security vulnerabilities privately to **info@astermd.com**.

Do **not** open a public issue for a security report. Public disclosure before a
fix is available puts every integrator at risk.

Include, where you can:

- the affected version of `astermd/sdk`;
- a description of the issue and its impact;
- steps to reproduce, or a minimal proof of concept;
- any suggested remediation.

We will acknowledge your report and keep you updated on remediation progress.
Please give us a reasonable opportunity to release a fix before disclosing the
issue publicly.

## Supported versions

Security fixes are applied to the latest released minor version. Please upgrade
before reporting an issue against an older release.

## Handling credentials and PHI

This SDK is a client for an API that carries personal and protected health
information. A few obligations sit with you, the integrator:

- **Never commit your `clientSecret`.** Load it from an environment variable or
  a secrets manager.
- **Debug logging is redacted by default.** `debug: true` masks bearer tokens,
  the client secret, PHI verification tokens, and the bodies of `patients/*`
  requests and responses. Passing `debugRedact: false` disables that and writes
  live credentials to your sink — use it only against non-production credentials
  and never leave it on.
- **Debug logs still record URLs, headers, and non-patient bodies.** Treat the
  log destination as sensitive and apply the same retention and access controls
  you would to any other system carrying customer data.
- **Token caches contain live credentials.** `FileTokenStore` writes its cache
  with `0600` permissions; if you implement your own `TokenStore`, protect it at
  least as well.
- **Using this SDK does not by itself make your application HIPAA compliant.**
  Compliance depends on your own infrastructure, policies, and agreements.
  Contact info@astermd.com regarding a Business Associate Agreement.
