# Changelog

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `EInvoicingManager` and the `EInvoicing` facade: named transports from
  `config/einvoicing.php`, the built-in `recommand` driver over Guzzle, custom drivers with
  `extend()`, and Peppol BIS validation of every send through the core `ValidatingTransport`.
- `EInvoicing::send()` with the `einvoicing_outbound_documents` table and a guarded state
  machine (`queued`, `sending`, `pending`, `delivered`, `failed`, `unconfirmed`): retries only
  for failures that cannot duplicate an invoice, never an automatic resend of an unknown
  outcome, and expiry of abandoned sending claims.
- Delivery status polling with configurable delays, and the events `DocumentSent`,
  `DocumentDelivered`, `DocumentFailed` and `DocumentUnconfirmed`.
- Inbound documents in `einvoicing_inbound_documents`: fetched, stored, announced once with
  `DocumentReceived`, then acknowledged.
- Signed Recommand webhooks on `POST {prefix}/{transport}`, verified on the raw body, outside
  the `web` middleware group, with the `WebhookReceived` event.
- Artisan commands `einvoicing:doctor`, `einvoicing:install-rules`, `einvoicing:poll-inbound`
  and `einvoicing:resolve`.
- `EInvoicing::fake()` for application tests.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
