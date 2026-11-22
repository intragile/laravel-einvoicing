# Changelog

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- **French e-invoicing (B2Brouter):** `EInvoicing::france(?string $transport = null)` returns the
  core's `B2BrouterFrance` (send as plateforme agréée, lifecycle statuses, buyer responses,
  payments, e-reporting) and `EInvoicing::annuaire(?string $transport = null)` the live
  `B2BrouterAnnuaire`, both for a `b2brouter` transport, by default the one named in the new
  `einvoicing.france.transport` setting (`EINVOICING_FRANCE_TRANSPORT`). A missing or
  non-B2Brouter transport throws a `ConfigurationException` naming that setting. Built by the new
  `France\FranceManager` singleton.
- **Annuaire binding:** only when `einvoicing.france.transport` is set, the core's
  `France\Routing\Annuaire` is bound to that transport's `B2BrouterAnnuaire`, so
  `France\Routing\Router` resolves from the container; an `Annuaire` the application bound itself
  is kept. New settings `einvoicing.france.annuaire_attempts` (default 4, 1 to 10) and
  `annuaire_retry_delay` (seconds, default 3, 0 to 30).
- **Events:** the webhook fires `LifecycleStatusChanged` (transport, B2Brouter invoice id,
  `LifecycleStatus`, received or issued) for a notification carrying a French lifecycle status,
  and `EReportStateChanged` (transport, ledger id) for `ledger.state_change`.
- **Testing:** `B2BrouterFranceSmokeTest` (group `b2brouter-france-live`, testsuite `Live`,
  excluded from the default run) runs the French flow through the bridge against a French
  B2Brouter sandbox or staging account: the `Annuaire` binding, a send that must reach `Deposited`
  and a full payment that must read back as `Collected`; it refuses any other key or host.
  `B2BrouterWebhookReplayTest` (same group) replays captured B2Brouter webhook deliveries through
  the webhook route: real signatures must verify and fire `LifecycleStatusChanged`.
- **Doctor:** a `france` row when `einvoicing.france.transport` is set: the transport must be a
  valid B2Brouter transport; the row names its key kind (sandbox, staging or production).
- **Polish KSeF:** `EInvoicing::ksef()` returns the new `Ksef\KsefManager` singleton:
  `transport()` (the core's `KsefTransport` for the configured context, built on first use and
  cached for the process; a `KsefTransport` bound in the container is used instead),
  `latarnia()`, `offlineCertificate()`, `settings()` and
  `queue(Invoice|string $invoice, bool $offline = false, ?string $reference = null)`, which stores
  an FA(3) row in `einvoicing_outbound_documents` and dispatches `SendKsefInvoice`.
  `EInvoicing::inbound('ksef')` returns the same transport.
- **KSeF configuration:** a new `einvoicing.ksef` section: `environment` (`KSEF_ENVIRONMENT`,
  default `test`; production only when set), `nip`, `credentials` (certificate and private key
  read from files, with an optional passphrase, or a KSeF token from `KSEF_TOKEN`),
  `offline_certificate`, `max_response_bytes` (default 67,108,864, at least 52,428,816),
  `inbound_min_interval_minutes` (default 15, 0 to 1440) and `deadline_warning_hours` (default 24,
  1 to 720). Errors name the setting and path, never the key, passphrase or token.
- **KSeF jobs:** `SendKsefInvoice` sends with the claim, backoff and state machine of
  `SendDocument`, and repeats a send whose outcome is unknown (KSeF answers a second copy as a
  duplicate). `PollKsefStatus` reads the status once per run along `status.delays`, stores
  `ksef_number`, `invoicing_mode` and the receipt (`upo`); on a synchronous connection it waits for
  the final status inline (about 60 seconds at most) and tries the receipt once.
- **KSeF duplicates:** a status 440 delivers the row under the original KSeF number only when the
  original's receipt proves the same file (`provider_code` `440`, the original's receipt stored); a
  different file fails the row with category `duplicate`; an undecidable comparison is retried and
  finally leaves the row `unconfirmed`.
- **KSeF inbox:** `einvoicing:poll-inbound --transport=ksef` reads the buyer inbox through the
  existing intake jobs, at most once per `inbound_min_interval_minutes` (less a minute of slack,
  at least one minute; kept in the default cache store). Inbound ids are
  `<KSeF number>@<storage date>`, and a plain KSeF number matches the same row. The inbox position
  is kept by `Ksef\DatabaseKsefCursorStore` in the new table `einvoicing_ksef_cursors`.
- **KSeF migration:** `add_ksef_to_einvoicing_tables` adds `ksef_number` (indexed),
  `invoicing_mode`, `offline_deadline` and `upo` to `einvoicing_outbound_documents` and creates
  `einvoicing_ksef_cursors`; `create_einvoicing_tables` creates the same columns on a fresh
  install.
- **KSeF commands:** `einvoicing:ksef-certificate` enrols a KSeF Offline or Authentication
  certificate (EC P-256 or RSA-2048) and writes it with its private key, mode 0600, refusing to
  overwrite and never printing the key. `einvoicing:ksef-deadlines` lists offline rows not yet
  delivered or failed with their deadlines, using the outages announced in Latarnia where
  reachable, and stores the deadline on the row (a calculation aid, not legal advice).
- **Events:** `KsefOfflineDeadlineApproaching` (`$message`, `$deadline`) for an offline row whose
  deadline is within `deadline_warning_hours` or has passed. The document events fire for KSeF rows
  as for Peppol.
- **Doctor:** `ksef` rows when `einvoicing.ksef` is in use: configuration and credentials, the
  certificates' expiry (an Offline certificate within 30 days warns), the cursor table, a shadowed
  `ksef` transport, and the public Latarnia status, read by default with a 5-second timeout. KSeF
  itself is never called.
- **Testing:** `KsefLiveTest` (group `ksef-live`, testsuite `Live`, excluded from the default run)
  sends an invoice through `EInvoicing::ksef()->queue()` on KSeF TE and receives it with
  `einvoicing:poll-inbound --transport=ksef` in a second context, with random NIPs and self-signed
  seals, within 15 metadata queries; it reaches no KSeF host but TE.
- **Documentation:** a "Polish KSeF" section in both READMEs.

### Changed

- Requires `intragile/php-einvoicing` ^1.2.
- The Composer description and keywords name the French and Polish (KSeF) support.

## [1.0.0] - 2026-11-16

### Added

- **Drivers:** the built-in `b2brouter` (B2Brouter) and `einvoicebe` (e-invoice.be) drivers,
  with their webhook parsers, account filters and doctor rows (B2Brouter sandbox-key warning;
  e-invoice.be local-only acknowledgement). Their config blocks ship **commented out** in
  `config/einvoicing.php`, because `einvoicing:doctor` checks every listed transport: uncomment
  the ones you use. New keys: `api_key`, `account_id`, `document_type_codes` and
  `webhook_tolerance` (B2Brouter); `api_key`, `tenant_id`, `inbound_lookback_days` and
  `inbound_max_pages` (e-invoice.be). Storecove is planned. Recommand is live-tested on the Peppol
  test network, B2Brouter and e-invoice.be against the provider sandbox (simulated network); see the
  core's `docs/VERIFICATION.md`.
- **Webhooks:** provider-neutral webhooks on the core's `Transport\Webhook\WebhookParser`;
  `EInvoicing::extendWebhook($driver, $factory)` registers the parser of a custom driver (or
  replaces a built-in one), so the webhook route accepts it.
- **Facade:** `EInvoicing::accountId()`, `EInvoicing::extendWebhook()` and
  `EInvoicing::hasDriver()` in the facade's method list.
- **Documentation:** a driver table and per-driver webhook setup in the README. Webhook signatures
  are verified offline against each provider's documented scheme (e-invoice.be also against its
  published test vector); webhooks were not live-tested. Known limits deferred to 1.x: no
  operator command for a `pending` row whose status polls are used up, e-invoice.be `date_from`
  is not used, and no guard against a second status-poll chain started by a stateless webhook.
- **Doctor:** `einvoicing:doctor` reports the installed `php-einvoicing` version and warns about
  on/off settings that are not a boolean and about an invalid webhook account id, and shows an
  info row (exit code unchanged) when the optional e-invoice.be `tenant_id` webhook filter is unset.
- **Testing:** `RecommandSmokeTest` (group `recommand-live`, testsuite `Live`, excluded from the
  default run) sends an invoice through `EInvoicing::send()` and the `recommand` driver to the
  Peppol test network; it refuses a key that does not belong to a test-network playground.
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
- Signed webhooks on `POST {prefix}/{transport}`, verified on the raw body, outside the `web`
  middleware group, with the `WebhookReceived` event.
- Artisan commands `einvoicing:doctor`, `einvoicing:install-rules`, `einvoicing:poll-inbound`
  and `einvoicing:resolve`.
- `EInvoicing::fake()` for application tests.

### Changed

- **Dependencies:** `guzzlehttp/guzzle` is allowed in version 8 (`^7.15.2 || ^8.0`), which fresh
  Laravel 13 applications ship, so `composer require` needs no `-W`.
- **Webhooks (public API):** `EInvoicingManager::webhook()` (and `EInvoicing::webhook()`) returns
  the core `WebhookParser` instead of `RecommandWebhook`; `companyId()` is replaced by
  `accountId()`, which returns the Recommand company id, the B2Brouter account id or the
  e-invoice.be tenant id; `WebhookReceived::$event` is the core `Transport\Webhook\WebhookEvent`
  instead of `Recommand\WebhookEvent` (`deduplicationKey` replaces `idempotencyKey`, `accountId`
  replaces `companyId`, `kind` is new).
- **Webhooks (behaviour change):** a notification that reports a delivery change without a state
  (e-invoice.be `document.sent.failed`, or a Recommand event of a non-Peppol channel) now queues a
  status poll for each `pending` document it names, for every driver. It shares the
  `status.max_attempts` budget with the regular polls; after that the row stays `pending`, and
  `einvoicing:resolve` does not handle it.
- **Webhooks (behaviour change):** a notification is ignored as foreign only when both its account
  id and the configured one are set and differ; an event that names an account is no longer
  dropped when no account is configured. Notifications are routed by kind, so a Recommand event of
  an unknown type that carries a `deliveryStatus` is no longer recorded.
- **Doctor:** treats `api_key` as a secret, lists every missing required key per driver, and
  checks each built-in driver's settings through the core configuration.
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
  individual `illuminate/*` packages, `guzzlehttp/guzzle` `^7.15.2 || ^8.0`,
  `intragile/php-einvoicing` `^1.0` and all three rule packages
  (`intragile/einvoicing-rules-en16931`, `-xrechnung` and `-facturx`) `^1.0`. Laravel 13 is
  supported on PHP 8.3 as well.

### Fixed

- On/off settings (`einvoicing.validation.enabled`, `inbound.acknowledge`, `status.poll`,
  `einvoicing.webhooks.enabled`) are read by one reader: `"false"`, `"0"` and `"off"` from the
  environment now turn the feature off, and a value that is not a boolean falls back to its safe
  value (validation, acknowledgements and status polls on; the webhook route off), with a doctor
  warning.
- A blank or non-scalar `company_id`, `account_id` or `tenant_id` no longer turns the webhook
  account filter off: events naming an account are ignored, and the configured value is trimmed
  before it is compared. `EInvoicingManager::accountId()` throws `ConfigurationException` when the
  setting is given but invalid.
- `einvoicing:doctor` reports an invalid base URL in one row instead of two.
- `einvoicing:resolve --sent` checks the job settings before the row becomes pending, so an
  invalid setting no longer leaves a pending row without a status poll.
- A Recommand credential that is too short is reported as such, naming the key, instead of as an
  invalid base URL.
- A "not accepted" answer that cannot be recorded because another worker changed the row is
  reported instead of being lost.
- A lost inbound transition no longer reloads the stored XML.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
