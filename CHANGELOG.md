# Changelog

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- **Validation:** `EInvoicing::xrechnung()` and `EInvoicing::facturx()` return the core
  XRechnung and Factur-X validators; `EInvoicing::validator()` returns the validator the send job
  uses: a `DocumentValidator` bound in the container, or else the core `PeppolPayloadValidator`
  (Peppol BIS rules for BIS documents, KoSIT rules for XRechnung); `null` while faked or with
  validation off. `EInvoicing::transport($name, validated: false)` returns the bare driver.
- **Sending:** XRechnung 3.0 (UBL Invoice, UBL CreditNote, CII) and the XRechnung 3.0 Extension
  (UBL Invoice, CII) go over Peppol through the core; `send()` accepts CII XML as well.
- **Inbound:** `inbound.max_fetch_attempts` (default 10) caps the total fetch and acknowledgement
  attempts of an inbound document; `einvoicing:poll-inbound` skips capped documents unless
  `--include-capped` is given, and the doctor warns about them.
- **Doctor:** a row per rule package with its installed version, a row for invalid numeric job
  settings, and a check of the Recommand credential length.
- **Configuration:** `ConfigValue` reads numeric settings, so numeric strings from the environment
  are accepted and anything else is refused with an error that names the key.
- **Documentation:** a test compiles every PHP sample of the READMEs
  (`tests/Unit/CodeSamplesTest.php`); a versioning policy in the README.
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

### Changed

- **Validation (behaviour change):** the `SendDocument` job validates the document before it
  claims the row. An invalid document ends as `failed` with category `validation` and
  `attempts` 0; a missing rule set leaves the row `queued` and fails the job with the reason.
- **Validation (public API):** a `DocumentValidator` bound in the container replaces the Peppol
  rules for the send job and `EInvoicing::transport()`; the transports wrap the core
  `ValidatingTransport` with a `DocumentValidator`, no longer a fixed `Validator::peppol()`.
- **Sending (behaviour change):** `send()` refuses, with `UnsupportedDocument` and without storing
  anything, every document the core cannot send over Peppol, including a UBL invoice with the
  writers' default specification identifier `urn:cen.eu:en16931:2017`; this holds with
  validation off as well.
- **Jobs:** every numeric job setting is checked before `send()`, the inbound recorder or
  `einvoicing:resolve --resend` writes or requeues a row, and before a job claims, counts or
  fetches, so an invalid setting leaves no row without a job. Follow-up jobs run on the
  connection of the job that schedules them. `MessageJob::schedule()`,
  `MessageJob::runsSynchronously()` and `PollDeliveryStatus::start()` take an optional connection
  name; `OutboundMessage::fromDocument()` returns `static`.
- **Doctor:** recognises an SQS FIFO queue by name, prefix and suffix, or full URL; checks the
  base URL with neutral placeholder credentials; reports a bound `DocumentValidator`.
- **Configuration:** `webhooks.enabled` accepts `"true"`/`"false"` and `"1"`/`"0"` from the
  environment. The Recommand API key id and secret must be at least eight characters long.
- **Dependencies (public API):** requires `laravel/framework` `^12.71|^13.0` instead of the
  individual `illuminate/*` packages, `guzzlehttp/guzzle` `^7.15.2`, `intragile/php-einvoicing`
  `^1.0` and all three rule packages (`intragile/einvoicing-rules-en16931`, `-xrechnung` and
  `-facturx`) `^1.0`. Laravel 13 is supported on PHP 8.3 as well.

### Fixed

- `einvoicing:resolve --sent` checks the job settings before the row becomes pending, so an
  invalid setting no longer leaves a pending row without a status poll.
- A Recommand credential that is too short is reported as such, naming the key, instead of as an
  invalid base URL.
- A "not accepted" answer that cannot be recorded because another worker changed the row is
  reported instead of being lost.
- A lost inbound transition no longer reloads the stored XML.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
