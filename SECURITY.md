# Security Policy

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

## Reporting a vulnerability

Please report security issues privately to **info@intragile.eu** rather than opening a public
issue. Include the affected version and, if possible, steps to reproduce. You will receive a
reply within a few working days.

## Handling of credentials

This package handles access point credentials (API key id, secret) and webhook signing secrets.
By design:

- secrets never appear in exception messages, `einvoicing:doctor` output, events, queued job
  payloads (jobs carry only a row id) or the debug output of the manager;
- request and response bodies are never logged;
- webhook signatures are checked on the raw request body in constant time, and a request with a
  missing or wrong signature is refused before its content is parsed.

Custom drivers registered with `EInvoicing::extend()` must keep credentials out of their own
exception messages, because those messages are stored with the document.

Never commit real credentials, and use a Recommand playground team for manual tests.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
