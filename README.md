# Laravel E-Invoicing

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

[![Tests](https://github.com/intragile/laravel-einvoicing/actions/workflows/tests.yml/badge.svg)](https://github.com/intragile/laravel-einvoicing/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/intragile/laravel-einvoicing.svg)](https://packagist.org/packages/intragile/laravel-einvoicing)
[![PHP](https://img.shields.io/packagist/php-v/intragile/laravel-einvoicing.svg)](https://packagist.org/packages/intragile/laravel-einvoicing)
[![License](https://img.shields.io/packagist/l/intragile/laravel-einvoicing.svg)](LICENSE)

The Laravel bridge for [`intragile/php-einvoicing`](https://github.com/intragile/php-einvoicing):
send and receive EN 16931 invoices over **Peppol**, French e-invoicing through B2Brouter,
Polish **KSeF** FA(3) invoices and Spanish **VERI\*FACTU** billing records from a Laravel
application.

[Magyar nyelvű leírás → README.hu.md](README.hu.md)

> **Status:** version 1.2.0, released 2026-11-23. What is verified, and how, is in
> [What is verified](#what-is-verified) and [docs/VERIFICATION.md](docs/VERIFICATION.md).

- **Configured transports.** Named connections in `config/einvoicing.php`; the built-in drivers
  `recommand`, `b2brouter` and `einvoicebe` talk to the [Recommand](https://recommand.eu),
  [B2Brouter](https://www.b2brouter.net) and [e-invoice.be](https://e-invoice.be) access points,
  and `EInvoicing::extend()` adds your own. Storecove is planned; see
  [Drivers](#drivers).
- **Validation before every send**, on by default: Peppol BIS documents with the official rules,
  installed by one artisan command, and XRechnung documents with the KoSIT rules.
- **Queued sending with a guarded state machine.** A send is claimed with a conditional update,
  so a duplicate or repeated job never sends twice. A send whose outcome is unknown is
  **never resent automatically**: it waits for an operator.
- **Delivery status** by polling and by webhook, with events at every step.
- **Inbound documents** from webhooks or polling: fetched, stored, announced once, and only
  then acknowledged at the access point.
- **Signed webhooks**, verified against the request exactly as received, without the `web`
  middleware group.
- **Polish KSeF:** queued FA(3) sending with the KSeF number and receipt stored, inbox polling
  within KSeF's read limits, offline deadlines and KSeF certificates; see
  [Polish KSeF](#polish-ksef).
- **Spanish VERI\*FACTU:** billing records generated, chained and stored per issuer and
  installation, with the QR URL at once; queued submission to AEAT under its flow control, or
  B2Brouter as the asynchronous alternative, cross-checked against our own engine; see
  [Spanish VERI\*FACTU](#spanish-verifactu).
- `EInvoicing::fake()` for your tests, and `einvoicing:doctor` to check an installation.

> **Live testing, per driver.** Recommand is live-tested on the Peppol test network, through this
> package as well. B2Brouter and e-invoice.be are live-tested against the provider sandbox
> (simulated network). Everything else is tested offline; see [Drivers](#drivers) and
> [What is verified](#what-is-verified).

## Requirements

- PHP 8.3+ with `dom`, `libxml`, `mbstring`
- Laravel 12 or 13
- A queue worker for production use (see [Queues](#queues))

## Packages

This package is the engine's Laravel bridge. Three thin entry packages carry the names people search for; each is a Composer metapackage (no code) that requires this package, and where the use case needs one, its rule package:

| Package | Use it for | Requires |
|---|---|---|
| [`intragile/laravel-xrechnung`](https://github.com/intragile/laravel-xrechnung) | XRechnung 3.0 for Germany | this package, `intragile/einvoicing-rules-xrechnung` |
| [`intragile/laravel-zugferd`](https://github.com/intragile/laravel-zugferd) | ZUGFeRD 2.x and Factur-X 1.0 hybrid PDF invoices | this package, `intragile/einvoicing-rules-facturx` |
| [`intragile/laravel-peppol`](https://github.com/intragile/laravel-peppol) | Sending and receiving over Peppol | this package |

The engine itself is [`intragile/php-einvoicing`](https://github.com/intragile/php-einvoicing); the rule packages are `intragile/einvoicing-rules-en16931`, `intragile/einvoicing-rules-xrechnung` and `intragile/einvoicing-rules-facturx`.

## Drivers

Recommand, B2Brouter and e-invoice.be are the available drivers; Storecove is planned.

| Driver | Peppol test network | Provider sandbox | Status |
|---|---|---|---|
| Recommand (`recommand`) | yes (playground team with `useTestNetwork`) | yes | live-tested on the Peppol test network |
| B2Brouter (`b2brouter`) | no (the sandbox simulates the network) | yes, free | live-tested against the provider sandbox (simulated network) |
| e-invoice.be (`einvoicebe`) | no (the sandbox simulates the network) | yes, free | live-tested against the provider sandbox (simulated network) |
| Storecove | unverified | on request (sales contact) | planned |

A test against a provider sandbox that simulates the network proves the provider's API contract,
not delivery over Peppol; only Recommand can reach the Peppol test network. e-invoice.be sends
Peppol BIS Billing 3.0 UBL only. B2Brouter renders the document it transmits from its own invoice
model, so the delivered bytes may differ from your XML. Webhook signatures are verified offline against each provider's documented scheme (e-invoice.be also against its published test vector); only B2Brouter's webhooks were live-tested (2026-11-18, sandbox deliveries replayed through the Laravel bridge). What each driver sends, its status
mapping, inbound behaviour and webhook details are in the core's
[TRANSPORT.md](https://github.com/intragile/php-einvoicing/blob/main/docs/TRANSPORT.md).

## Installation

```bash
composer require intragile/laravel-einvoicing
php artisan vendor:publish --tag=einvoicing-config     # optional: config/einvoicing.php
php artisan einvoicing:install-rules                   # the Peppol BIS validation rules
php artisan migrate                                    # einvoicing_outbound_documents, einvoicing_inbound_documents, einvoicing_ksef_cursors
php artisan einvoicing:doctor                          # checks everything below
```

> **Before the first release** neither this package nor `intragile/php-einvoicing` is on
> Packagist. Until then, clone both repositories next to your application and add them as
> path repositories in its `composer.json`:
>
> ```json
> "repositories": [
>     { "type": "path", "url": "../php-einvoicing", "options": { "versions": { "intragile/php-einvoicing": "1.2.0" } } },
>     { "type": "path", "url": "../php-einvoicing/packages/rules-en16931", "options": { "versions": { "intragile/einvoicing-rules-en16931": "1.0.0" } } },
>     { "type": "path", "url": "../php-einvoicing/packages/rules-xrechnung", "options": { "versions": { "intragile/einvoicing-rules-xrechnung": "1.0.0" } } },
>     { "type": "path", "url": "../php-einvoicing/packages/rules-facturx", "options": { "versions": { "intragile/einvoicing-rules-facturx": "1.0.0" } } },
>     { "type": "path", "url": "../laravel-einvoicing", "options": { "versions": { "intragile/laravel-einvoicing": "1.0.0" } } }
> ]
> ```
>
> and require `"intragile/laravel-einvoicing": "^1.0"`. The `versions` option gives each path
> package the version the constraints between the packages expect.

The migrations are loaded from the package. To keep a copy in your application, publish them with
`php artisan vendor:publish --tag=einvoicing-migrations`.

`einvoicing:install-rules` downloads the pinned Peppol BIS Billing sources and compiles them
into `storage/einvoicing/peppol-rules` (or `EINVOICING_RULES_DIR`). Without network access,
pass a folder with the downloaded `.sch` files: `--from=DIR`. OpenPeppol publishes no licence
for its rules, so they are installed on your machine, never shipped with the package.

### Configuration

```dotenv
EINVOICING_TRANSPORT=recommand          # the default transport

RECOMMAND_API_KEY_ID=key_...
RECOMMAND_API_SECRET=secret_...
RECOMMAND_COMPANY_ID=c_...
RECOMMAND_WEBHOOK_SECRET=...            # only when you receive webhooks
# RECOMMAND_BASE_URL=https://app.recommand.eu

# EINVOICING_RULES_DIR=/path/to/peppol-rules
# EINVOICING_QUEUE_CONNECTION=redis
# EINVOICING_QUEUE=einvoicing
```

Each access point is one entry in `einvoicing.transports`, with one of the built-in drivers
`recommand`, `b2brouter` or `einvoicebe`. The published config file holds the `recommand` entry;
the other two are in it commented out, because `einvoicing:doctor` checks every listed transport.
Uncomment the ones you use and point `EINVOICING_TRANSPORT` at the default one.

**B2Brouter** (`driver` `b2brouter`):

```php
// config/einvoicing.php
return [
    // ...
    'transports' => [
        'b2brouter' => [
            'driver' => 'b2brouter',
            'api_key' => env('B2BROUTER_API_KEY'),
            'account_id' => env('B2BROUTER_ACCOUNT_ID'),
            'base_url' => env('B2BROUTER_BASE_URL', 'https://api.b2brouter.net'),
            'document_type_codes' => [],
            'webhook_secret' => env('B2BROUTER_WEBHOOK_SECRET'),
            'webhook_tolerance' => null,
        ],
    ],
];
```

```dotenv
B2BROUTER_API_KEY=...                   # a key starting with test_ goes to the sandbox
B2BROUTER_ACCOUNT_ID=12345              # the account that sends and receives
B2BROUTER_WEBHOOK_SECRET=...            # only when you receive webhooks
# B2BROUTER_BASE_URL=https://api.b2brouter.net
```

B2Brouter sends a key starting with `test_` to its sandbox, which never reaches the Peppol
network; the doctor shows a warning for it. `account_id` is a positive whole number.
`document_type_codes` maps further Peppol document type identifiers to B2Brouter document type
codes; Peppol BIS Billing 3.0 and XRechnung 3.0 documents need none (with intragile/php-einvoicing
1.0.1 or later). `webhook_tolerance`, in
seconds, refuses notifications whose signed timestamp is further from now; it is off (`null`) by
default, because B2Brouter retries a notification for about a week.

**e-invoice.be** (`driver` `einvoicebe`):

```php
// config/einvoicing.php
return [
    // ...
    'transports' => [
        'einvoicebe' => [
            'driver' => 'einvoicebe',
            'api_key' => env('EINVOICEBE_API_KEY'),
            'base_url' => env('EINVOICEBE_BASE_URL', 'https://api.e-invoice.be'),
            'tenant_id' => env('EINVOICEBE_TENANT_ID'),
            'inbound_lookback_days' => 7,
            'inbound_max_pages' => 10,
            'webhook_secret' => env('EINVOICEBE_WEBHOOK_SECRET'),
        ],
    ],
];
```

```dotenv
EINVOICEBE_API_KEY=...
EINVOICEBE_WEBHOOK_SECRET=...           # only when you receive webhooks
# EINVOICEBE_TENANT_ID=...              # drops webhook notifications of other tenants
# EINVOICEBE_BASE_URL=https://api.e-invoice.be
```

e-invoice.be has no call to acknowledge a received document, so acknowledgement is local only:
a document stays in its inbox and each poll lists the last `inbound_lookback_days` days (1 to
365), at most `inbound_max_pages` pages of 100 (1 to 100); the package stores and fetches each
document once. With `tenant_id` set, webhook notifications naming another tenant are ignored;
without it (or with an empty `EINVOICEBE_TENANT_ID=` line), every signed notification is
applied, and `einvoicing:doctor` shows an info row for that case when webhooks are on.

The rest of `config/einvoicing.php`: HTTP timeouts and the response size cap (`http`),
validation on or off (`validation.enabled`), send retries (`sending.max_attempts`,
`sending.backoff`), status polling (`status.poll`, `status.delays`, `status.max_attempts`),
inbound acknowledgement and the inbound attempt budget (`inbound.acknowledge`,
`inbound.max_fetch_attempts`) and the webhook route (`webhooks`). In every list of delays the
last value repeats. Numbers may be given as numeric strings, as environment variables deliver
them; anything else is refused with an error that names the key. `webhooks.enabled` also takes
the strings `"true"`/`"false"` and `"1"`/`"0"`. API keys, and the Recommand API key id and
secret, must each be at least 8 characters long.

A transport is built on first use, so missing credentials never break application boot.
Secrets never appear in exception messages, doctor output, events or queued job payloads; jobs
carry only a row id.

## Sending

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;

$message = EInvoicing::send($invoice, reference: 'INV-2026-0001');
```

`send()` accepts a core `Invoice` model, UBL or CII XML as a string, or an `OutboundDocument`. The
routing (sender, receiver, process) is read from the invoice itself. It stores the document as
an `OutboundMessage` in state `queued` and dispatches the `SendDocument` job after the open
database transaction, if any, commits. The optional third argument names a transport other than
the default.

**Give the invoice the process control of what you send.** Only these documents are sent over
Peppol, chosen by the exact pair of document element and specification identifier (BT-24):

- Peppol BIS Billing 3.0: a UBL Invoice or CreditNote;
- XRechnung 3.0: a UBL Invoice, UBL CreditNote or CII invoice;
- XRechnung 3.0 Extension: a UBL Invoice or CII invoice.

The XRechnung CVD and the deprecated XRechnung UBL Credit Note Extension are refused. The core's
writers use `urn:cen.eu:en16931:2017` for an invoice without process control, which is plain
EN 16931 and none of these, so set it:

```php
use IntrAgile\EInvoicing\Model\ProcessControl;
use IntrAgile\EInvoicing\Syntax\Syntax;
use IntrAgile\EInvoicing\Syntax\XRechnung;
use IntrAgile\EInvoicing\Transport\OutboundDocument;
use IntrAgile\EInvoicing\Transport\Peppol\PeppolBis3;

// Peppol BIS Billing 3.0
new Invoice(
    // ...
    processControl: new ProcessControl(
        'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0',
        PeppolBis3::PROCESS,
    ),
);

// XRechnung 3.0, sent as UBL by send($invoice), or as CII:
$invoice = new Invoice(/* ... */ processControl: XRechnung::processControl());
EInvoicing::send(OutboundDocument::fromInvoice($invoice, syntax: Syntax::Cii));
```

Any other document is refused by `send()` itself: it throws `UnsupportedDocument` with the reason
and stores nothing. This holds with validation off as well.

### Validation

With validation on (the default), the `SendDocument` job validates the document before it claims
the row: Peppol BIS documents with the installed Peppol rules, XRechnung documents with the KoSIT
rules of `intragile/einvoicing-rules-xrechnung`, which this package requires. An invalid document
ends as `failed` with category `validation` and `attempts` 0, and nothing leaves your server. A
missing rule set leaves the row `queued` and the job fails with the reason, so install the rules
and queue it again with `einvoicing:resolve ID --resend`.

To check documents with your own rules, bind a validator in the container; the job and
`EInvoicing::transport()` then use it instead of the Peppol rules:

```php
use IntrAgile\EInvoicing\Validation\DocumentValidator;

$this->app->singleton(DocumentValidator::class, fn () => new MyValidator());
```

The default validator loads the Peppol BIS rules when it is built, even if you send only
XRechnung documents, so run `einvoicing:install-rules` in that case too. A bound
`DocumentValidator` is ignored when `validation.enabled` is false. A transport already built by
`EInvoicing::transport()` keeps the validator it was built with, so bind yours before the first
call, for example in a service provider's `register()`.

`EInvoicing::transport()` returns a transport that validates every document;
`EInvoicing::transport($name, validated: false)` returns the bare driver. To validate a document
yourself, for example a received one, `EInvoicing::xrechnung()->validate($xml)` and
`EInvoicing::facturx()->validate($xml)` return a core `ValidationReport`.

### States

```
send() ─► queued ─► sending ─► pending ─► delivered
             ▲         │          └─────► failed
             │         ├─► failed
             └─retry───┤
                       └─► unconfirmed ─► (operator) einvoicing:resolve
```

| State | Meaning | What to do |
|---|---|---|
| `queued` | Stored, waiting for a send job | Nothing; a worker picks it up |
| `sending` | Claimed by a job; the request may be in flight | Nothing; it moves on, or becomes `unconfirmed` when the claim expires |
| `pending` | Accepted by the access point; delivery to the receiver is not confirmed yet | Nothing; status polls and webhooks finish it |
| `delivered` | Delivered to the receiver (terminal) | |
| `failed` | Definitely not delivered (terminal); see `failure_category`, `failure_message`, `provider_code` | Fix the cause, then resend if needed: `einvoicing:resolve ID --resend` |
| `unconfirmed` | The outcome is not known: the access point **may** have accepted the document | Check the access point, then `einvoicing:resolve` (below) |

A send failure is handled by what it proves:

- **Definitely not accepted** (validation error, rejected document, unreachable recipient,
  wrong credentials): `failed`.
- **Safe to retry** (`TransportUnavailable`, `RateLimited`): back to `queued`, sent again after
  `Retry-After` or `sending.backoff`, up to `sending.max_attempts`, then `failed` with category
  `transport`.
- **Anything else** (no answer, a 5xx other than 503, an unreadable 200, any unexpected
  exception): `unconfirmed`. It is never sent again automatically, because sending again might
  deliver the invoice twice.

Send jobs have `$tries = 1`: the queue never repeats a send. A worker that dies in the middle of
a send leaves the row `sending`; a delayed `ExpireSendingClaim` job then moves it to
`unconfirmed`.

### Resolving an unconfirmed send

Look the document up in the access point, by receiver and invoice number, then:

- Recommand: the company's documents in the dashboard or the API.
- B2Brouter: the account's invoices; the document id is the invoice id (digits). Per B2Brouter's API
  documentation, a resend of an invoice number the account already holds is refused as a
  duplicate.
- e-invoice.be: the drafts and sent documents; the document id is `doc-…`. A document still in
  `DRAFT` was uploaded but not sent: either send it at e-invoice.be and use `--sent`, or use
  `--resend`, which uploads a new draft.



```bash
php artisan einvoicing:resolve 42 --sent=<document-id>   # it was accepted: pending, polled from here on
php artisan einvoicing:resolve 42 --resend               # it was not: queue it again (asks for confirmation)
php artisan einvoicing:resolve 42 --resend --force       # the same without the question, e.g. in scripts
```

`--resend` also accepts a `failed` or `queued` row. It never resets the attempt counter, so a
row that failed after `sending.max_attempts` gets **one** new attempt, without backoff; run it
again if that fails too. A row still `sending` longer than the send job's timeout plus 30
seconds is treated as abandoned and can be resolved like `unconfirmed` (relevant on a `sync`
connection, which cannot schedule the claim expiry). That assumes the worker that held it was
killed at its timeout, which needs `pcntl` (see [Queues](#queues)).

### Events

| Event | When |
|---|---|
| `DocumentSent` | the transport accepted the document, whatever its delivery state |
| `DocumentDelivered` | the document reached the receiver |
| `DocumentFailed` | the document was definitely not delivered |
| `DocumentUnconfirmed` | the outcome of the send is unknown; an operator must resolve it |
| `DocumentReceived` | an inbound document is stored (`$event->message->xml`) |
| `WebhookReceived` | a signed webhook for this provider account was verified, before it is applied (repeated deliveries fire it again; `$event->event->deduplicationKey` identifies one delivery) |

All are in `IntrAgile\EInvoicing\Laravel\Events`; the document events carry the
`OutboundMessage` or `InboundMessage` model as `$message`.

```php
use IntrAgile\EInvoicing\Laravel\Events\DocumentDelivered;

Event::listen(function (DocumentDelivered $event) {
    Order::where('invoice_number', $event->message->reference)->update(['einvoice' => 'delivered']);
});
```

Delivery status is polled after `status.delays` while a document is `pending`, up to
`status.max_attempts` checks; then polling stops and the row stays `pending` until a webhook
finishes it. A status check that throws something other than a transport error stops polling
for that document for good; the failed job shows why.

## Receiving

Inbound documents arrive by webhook or by polling; both end in the same pipeline. A row in
`einvoicing_inbound_documents` is `notified`, then `fetched` (the XML is stored), then
`acknowledged` (the access point was told). `DocumentReceived` fires once per document, after
the database commit, and the acknowledgement follows it.

```php
use IntrAgile\EInvoicing\Laravel\Events\DocumentReceived;
use IntrAgile\EInvoicing\Syntax\Ubl\UblReader;

Event::listen(function (DocumentReceived $event) {
    $invoice = (new UblReader)->read($event->message->xml)->invoice;
    // ...
});
```

**Delivery of `DocumentReceived` is at most once.** If the process dies between the commit and
the event, or a listener throws, the document is still acknowledged and the event is not fired
again. Make listeners that must not miss a document queued (`ShouldQueue`), and reconcile from
the table: every stored document is a `fetched` or `acknowledged` row with its XML.

A document that cannot be read (a DOCTYPE, broken XML) stays `notified` with `last_error` and
is not acknowledged. A fetch or acknowledgement that failed with a retryable error (and an
acknowledgement whose outcome is unknown) is retried after `sending.backoff`, up to
`sending.max_attempts`; any other fetch error, and anything left after the last attempt, waits
for the next poll, which queues one more attempt each time the access point still lists the
document.

Every failed fetch and acknowledgement counts against one budget per document,
`inbound.max_fetch_attempts` (default 10, from 1 to 65535). A document that has used it up is
no longer fetched or acknowledged automatically: the poll skips it and reports it as capped,
and `einvoicing:doctor` warns about it. Look at its `last_error`, fix the cause (often at the
access point), then run `php artisan einvoicing:poll-inbound --include-capped`, which gives each
capped document still listed by the access point exactly one more attempt.

### Polling

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('einvoicing:poll-inbound')->everyFiveMinutes()->withoutOverlapping();
// one line per transport when you have several:
// Schedule::command('einvoicing:poll-inbound --transport=other')->everyFiveMinutes();
```

Each poll records new documents and queues their intake. A document the access point still
lists whose row is stuck (`notified` or `fetched`) is queued again, so polling also recovers
documents that a failed job or a `sync` connection left behind. Schedule it even when you use
webhooks.

### Webhooks

The package registers one route, `POST /einvoicing/webhooks/{transport}` (route name
`einvoicing.webhook`), for every transport name. It accepts notifications only for a transport
with a built-in driver (or a custom driver with a parser registered with
`EInvoicing::extendWebhook()`) and a `webhook_secret`; any other name gets `404`. Setup:

1. Run `php artisan einvoicing:doctor`. The `transport <name>: webhook` row prints the full URL,
   `https://<your app>/einvoicing/webhooks/<name>`, where `<name>` is the transport's key in
   `einvoicing.transports` (not the driver), when the transport has a webhook secret and webhooks
   are enabled; otherwise it shows a warning that says what is missing. A missing required key
   (Recommand `company_id`, B2Brouter `account_id`) fails the `config` row.
2. Register that URL at the provider and put the secret it gives you into the transport's
   `webhook_secret`:

   | Driver | Register | Events | Secret |
   |---|---|---|---|
   | `recommand` | a webhook in Recommand | its document events | the webhook's signing `secret` → `RECOMMAND_WEBHOOK_SECRET` |
   | `b2brouter` | a web hook in B2Brouter (API `POST /web_hooks`) | `issued_invoice.state_change`, `received_invoice.created` | the web hook's signing secret (`signing_secret` in the API; B2Brouter's guide calls it `signature_key`) → `B2BROUTER_WEBHOOK_SECRET` |
   | `einvoicebe` | a webhook through the e-invoice.be API (`POST /api/webhooks/`) | `document.received`, `document.sent`, `document.sent.failed` | the `secret` of the create response → `EINVOICEBE_WEBHOOK_SECRET` |

   Notifications are matched against the configured account: Recommand `company_id`, B2Brouter
   `account_id` and, when set, e-invoice.be `tenant_id`. A notification that names another
   account is ignored; one that names no account, or arrives for a transport without a configured
   account, is applied.
3. Nothing else: the route does not run the `web` middleware group, so no CSRF token or
   session is involved, and you need no CSRF exception.

Pass the body exactly as received; each driver's parser verifies it its provider's way, with an
HMAC-SHA256 keyed with the webhook secret: Recommand over the raw body, B2Brouter over
`<t>.<raw body>` (the timestamp from its signature header, a dot, then the raw body), and
e-invoice.be over a canonical JSON form of the event, not over the raw body.
Answers have an empty body: `401` for a missing or wrong signature, `404` for a transport
without a webhook secret, `204` for everything else, including notifications that are ignored
(another provider account, an unknown document, a non-document event), so the access point does
not retry them. Repeated or out-of-order notifications are harmless: inbound documents are
stored once per id, and `delivered` and `failed` never change again. A notification that says a
delivery changed without saying how (e-invoice.be's `document.sent.failed`, or a Recommand
event of a non-Peppol channel) starts an extra status-poll chain for each `pending` document it
names. The extra chain shares the document's `status_checks` count with the regular polls, so
together they stay within `status.max_attempts`. Once those are used up, with `status.poll` off,
or on a `sync` queue connection, nothing is polled and the row stays `pending`, as after the last
regular status poll. Check such a row at the access point: `einvoicing:resolve` does not handle a
`pending` row (it resolves `unconfirmed` and abandoned `sending` rows).

B2Brouter's `X-B2Brouter-Delivery-Id` header, which the core offers as `deduplicationKey` to
your `WebhookReceived` listeners (the package itself does not deduplicate on it), is not
covered by B2Brouter's signature, and `webhook_tolerance` is off by default, so
a captured notification can be replayed later. A replay can only repeat a state B2Brouter really
reported, and `delivered` and `failed` never change again. Set `webhook_tolerance` if replays
matter more to you than late retries (see the core's TRANSPORT.md). Webhooks are not live-tested,
which needs a public HTTPS endpoint; their signatures are verified offline against each
provider's documented scheme (for e-invoice.be also against its published test vector).

**Harden the endpoint.** The route has no rate limit and no body size limit of its own. Add a
throttle in `einvoicing.webhooks.middleware`, for example `['throttle:120,1']`, and cap the
request body in your web server (`client_max_body_size` in nginx, `LimitRequestBody` in
Apache). The prefix is `einvoicing.webhooks.prefix`; `einvoicing.webhooks.enabled = false`
removes the route.

A delivery-status webhook that arrives before the send job has stored the access point's
document id finds no row and is dropped. On a real queue the status polls catch up; on a `sync`
connection nothing does, so check such rows by hand.

## French e-invoicing (B2Brouter)

A `b2brouter` transport can also act as your French plateforme agréée. Name it in
`einvoicing.france.transport` (`EINVOICING_FRANCE_TRANSPORT`); it must be configured in
`einvoicing.transports` with the `b2brouter` driver, and its key, account id, base URL and the
`einvoicing.http` settings are used as for Peppol sending. Requires `intragile/php-einvoicing`
1.1 or later.

> **Live-tested in the B2Brouter sandbox: directory lookup, send, lifecycle, buyer approval and
> payment; an accepted deposit is tested offline only.**

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Transport\B2Brouter\France\BuyerResponse;
use IntrAgile\EInvoicing\Transport\B2Brouter\France\FrenchDocument;

$result = EInvoicing::france()->send(FrenchDocument::fromXml($ublOrCiiXml));
$status = EInvoicing::france()->lifecycle($result->documentId);   // PlatformStatus, CDV code in ->lifecycle
EInvoicing::france()->respond($receivedInvoiceId, BuyerResponse::approved());
EInvoicing::france()->recordPayment($issuedInvoiceId);           // paid in full
$deposit = EInvoicing::france()->depositEReport($flux10Xml);      // EReportDeposit, ->ledgerId
```

`EInvoicing::france($transport)` and `EInvoicing::annuaire($transport)` take another B2Brouter
transport by name; a transport that is missing or not B2Brouter throws a
`ConfigurationException` naming `einvoicing.france.transport`. These clients make real requests:
`EInvoicing::fake()` does not replace them, and nothing is queued or stored. The clients are
cached per transport name for the lifetime of the process, so long-running processes (Octane,
queue workers) must be restarted after a configuration change. For every method, see the core's
[FRANCE.md](https://github.com/intragile/php-einvoicing/blob/main/docs/countries/FRANCE.md),
section "Sending through a plateforme agréée (B2Brouter)".

**Routing.** Only when `einvoicing.france.transport` is set, the core's
`France\Routing\Annuaire` interface is bound to the live `B2BrouterAnnuaire` of that transport,
so the router can be resolved from the container (an `Annuaire` your application binds itself is
kept):

```php
use IntrAgile\EInvoicing\France\Routing\Router;

$decision = app(Router::class)->route($invoice);   // RoutingDecision: ->channel, ->needsDirectoryCheck
```

A cold Annuaire lookup is repeated while B2Brouter is still resolving it, up to
`einvoicing.france.annuaire_attempts` requests (default 4) `annuaire_retry_delay` seconds apart
(default 3), so `route()` can block for about nine seconds; that figure is the polling case
only, and a lookup that hangs costs one HTTP timeout (`einvoicing.http`) before it reads as
`Unknown`. Spaces in a SIREN are ignored. A SIREN that fails the local check, a company B2Brouter
flags `FR_ASSUJETTI_UNKNOWN`, a lookup still unresolved after the last attempt and an unavailable
directory give `Unknown`, and the router then keeps the e-invoice and asks for a directory
check. **`route()` can still throw:** an `InvalidArgumentException` when B2Brouter
refuses the SIREN (HTTP 422), and `AuthenticationFailed` when it refuses the API key
(HTTP 401/403). Catch both around `route()` if a refused lookup must not stop your flow.

**Webhooks.** Register `issued_invoice.state_change`, `received_invoice.state_change` and
`ledger.state_change` at B2Brouter as well. After the usual handling the webhook fires:

| Event | When |
|---|---|
| `LifecycleStatusChanged` | the notification carries a French lifecycle status: `$transport`, `$documentId` (the B2Brouter invoice id), `$status` (`LifecycleStatus`), `$received` (true for an invoice you received, whose status is your own response) |
| `EReportStateChanged` | a deposited e-reporting file changed state: `$transport`, `$ledgerId`; read the state with `EInvoicing::france($event->transport)->eReportStatus($event->ledgerId)` |

Both fire once per delivery, so a repeated or late notification fires them again; compare with
what you stored before acting. They fire whether or not `einvoicing.france.transport` is set:
`LifecycleStatusChanged` also fires for a B2Brouter account that is not French, because the
lifecycle status is derived from the invoice's `data.state`. A `ledger.state_change` webhook now
reaches `WebhookReceived` as well, as an event of kind `Other` whose document id is the ledger id.
B2Brouter sends no notification when a recorded payment turns an invoice `paid` (observed in the
sandbox), so `Collected` never arrives as `LifecycleStatusChanged`: read it from what
`recordPayment()` returns or from `lifecycle()`.

**Doctor.** With `einvoicing.france.transport` set, `einvoicing:doctor` adds a `france` row: the
transport must be a valid B2Brouter transport, and the row says whether its key is a sandbox
(`test_`), staging (`stag_`) or production key. It makes no request.

## Polish KSeF

`EInvoicing::ksef()` sends FA(3) invoices to KSeF, the Polish national e-invoicing system (KSeF
API 2.0), through the queue, and `einvoicing:poll-inbound --transport=ksef` receives the invoices
issued to you. Rows, states and events are the same as for Peppol. Requires
`intragile/php-einvoicing` 1.2 or later. The core's
[POLAND.md](https://github.com/intragile/php-einvoicing/blob/main/docs/countries/POLAND.md)
describes the FA(3) model, the KSeF client and its limits in detail.

> **Live-tested on KSeF TE, the test environment, through this package:** a queued send that
> ends delivered with its KSeF number and receipt (UPO), and the buyer's inbox poll that fires
> `DocumentReceived` (2026-11-22, PHP 8.4 and 8.5). Offline invoices, deadlines, the certificate
> command and the doctor row are tested offline here; the core live-tests offline sending, KSeF
> certificates and the QR links on TE.

### Configuration and credentials

```bash
KSEF_ENVIRONMENT=test                   # test (TE), demo or production; production only when set
KSEF_NIP=5265877635                     # the context the client authenticates for
KSEF_CERTIFICATE_PATH=/run/secrets/ksef-seal.crt.pem
KSEF_PRIVATE_KEY_PATH=/run/secrets/ksef-seal.key.pem
# KSEF_PRIVATE_KEY_PASSPHRASE=...       # for an encrypted key
# KSEF_TOKEN=...                        # instead of a certificate: a KSeF token
# KSEF_OFFLINE_CERTIFICATE_PATH=/run/secrets/ksef-offline.crt.pem
# KSEF_OFFLINE_PRIVATE_KEY_PATH=/run/secrets/ksef-offline.key.pem
# KSEF_OFFLINE_PRIVATE_KEY_PASSPHRASE=...
```

These fill `einvoicing.ksef` in `config/einvoicing.php`:

| Setting | Default | Meaning |
|---|---|---|
| `environment` | `test` | `test`, `demo` or `production`; production is never chosen unless it is set |
| `nip` | — | the ten-digit NIP of the context, check digit validated |
| `credentials` | — | `certificate` and `private_key` (file paths) with an optional `passphrase`, **or** `token`; not both |
| `offline_certificate` | — | `certificate` and `private_key` paths and `passphrase` of the KSeF Offline certificate that signs KOD II of offline invoices; leave empty when unused |
| `max_response_bytes` | 67,108,864 | the cap on every KSeF answer read into memory; at least 52,428,816, the largest encrypted export part |
| `inbound_min_interval_minutes` | 15 | the shortest interval between two inbox polls (0 to 1440; 0 turns the guard off) |
| `deadline_warning_hours` | 24 | how close an offline deadline must be to fire `KsefOfflineDeadlineApproaching` (1 to 720) |

- **Certificates and private keys are read from files**, never from environment values; keep the
  key files readable by the application user only. A qualified seal or signature works, and so
  does a KSeF Authentication certificate. The passphrase of an encrypted key comes from the
  environment.
- **A KSeF token comes from the environment** (`KSEF_TOKEN`), like other providers' API keys.
  KSeF enrols certificates only for a context authenticated with a certificate, so
  `einvoicing:ksef-certificate` needs certificate credentials.
- Errors name the setting and the file path, never the file contents, the passphrase or the
  token. The KSeF client is built on first use and cached for the process: restart queue workers
  and Octane after a configuration change. `EInvoicing::fake()` does not replace it.
- `php artisan migrate` adds the columns `ksef_number`, `invoicing_mode`, `offline_deadline` and
  `upo` to `einvoicing_outbound_documents` and the table `einvoicing_ksef_cursors`, where the
  inbox position is kept (`DatabaseKsefCursorStore`; bind your own
  `Transport\Ksef\Inbound\KsefCursorStore` to keep it elsewhere).

### KSeF certificates

```bash
php artisan einvoicing:ksef-certificate                         # an Offline certificate, EC P-256
php artisan einvoicing:ksef-certificate --type=authentication --algorithm=rsa --name="Billing" \
    --certificate=/run/secrets/ksef-auth.crt.pem --private-key=/run/secrets/ksef-auth.key.pem
```

The command enrols a certificate for the configured context and writes the certificate and its
private key, each with mode 0600, to the configured paths: `offline_certificate` for an Offline
certificate, `credentials` for an Authentication certificate (`--certificate=` and
`--private-key=` name other paths). It checks the paths before it enrols and refuses to overwrite
an existing file. The key is generated locally, exists only in memory until it is written, and is
never printed; when the type's passphrase is set, the key is written encrypted with it. KSeF
allows 100 certificates per subject.

An Authentication certificate needs `--certificate=` and `--private-key=`: its default paths are
the `credentials` files the command itself authenticates with, which already exist and are never
overwritten. Point `credentials` at the new files afterwards to log in with the new certificate.

### Sending

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;

$message = EInvoicing::ksef()->queue($invoice);                    // an Invoice, or an FA(3) file
$message = EInvoicing::ksef()->queue($fa3Xml, offline: true);       // issued in offline mode
$message = EInvoicing::ksef()->queue($invoice, reference: 'FV/2026/11/0042');
```

`queue()` stores the exact bytes in `einvoicing_outbound_documents` (`transport` and `process`
`ksef`, `document_type` `FA (3)`, `sender` the seller NIP, `receiver` the buyer) and dispatches
`SendKsefInvoice` once the open transaction commits. The job validates the invoice against the
FA(3) schema, sends it, and starts `PollKsefStatus`, which follows `status.delays` and
`status.max_attempts`:

- **Status 200** delivers the row with `ksef_number` and the `invoicing_mode` KSeF reports
  (`Online` or `Offline`; KSeF classifies an online send dated before today as offline), then
  fetches the receipt into `upo`. A receipt KSeF has not issued yet is fetched by a later run.
- **A rejection** fails the row with the KSeF code and message and fires `DocumentFailed`.
- **A send whose outcome is unknown is repeated** on an asynchronous queue, unlike Peppol: KSeF
  answers a second copy as a duplicate, so it cannot be accepted twice. The row is marked with
  `provider_code` `outcome_unknown` meanwhile. On a synchronous connection (`sync`, `deferred`,
  `background`) it is not repeated: the row becomes **unconfirmed** at once; resolve it with
  `einvoicing:resolve`.
- **After an unknown outcome**, a refusal of the repeated copy that says nothing about the first
  send (at send time, or a final status 405, 435, 500 or 550) makes the row **unconfirmed** instead
  of failed and keeps the `outcome_unknown` marker: the first send may have been accepted, so look
  for the invoice in KSeF before resending or issuing it again. A validation failure (430, 450) is
  decided by the bytes, so it still fails the row.
- **Duplicates (status 440).** KSeF reports a duplicate when an invoice with the same seller NIP,
  invoice type and number (`P_2`) is already accepted, whatever its content. The row is
  **delivered** under the original KSeF number only when the original's receipt proves that the
  original is this exact file (same document hash); its `provider_code` is then `440` and the
  original's receipt is stored when it can be read. When the original is a different file the row
  **fails** with category `duplicate`. When it cannot be decided (the receipt is refused or not issued yet) the
  next run asks again, and after the last run the row becomes **unconfirmed** for an operator
  (`einvoicing:resolve`). It is never delivered on a guess.

> **On a `sync` connection `queue()` blocks.** The send and the status poll run inside the call:
> it waits for the final status within the core client's polling bounds, about 60 seconds, and
> tries the receipt once. Use an asynchronous queue in production (see [Queues](#queues)).

- **Status tracking needs `einvoicing.status.poll`.** KSeF has no webhooks, so with polling turned
  off KSeF rows stay pending and get no KSeF number or receipt.
- **When the status checks run out** (`status.max_attempts`) while KSeF is still processing, or the
  synchronous wait ends without a final status, the row stays pending and no event fires. Look for
  KSeF rows left pending with `status_checks` at the maximum.

### Receiving

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('einvoicing:poll-inbound --transport=ksef')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('einvoicing:ksef-deadlines')->hourly();
```

The poll reads the buyer inbox of the configured NIP from the stored position and records each new
invoice as an inbound row; the existing jobs fetch, store and acknowledge it, and
`DocumentReceived` fires once, as for Peppol. The first poll reads the last 30 days.

- **The poll is guarded.** KSeF allows 20 metadata queries per hour per context and IP, and the Ministry
  of Finance recommends polling a subject no more often than every 15 minutes. A run sooner than
  `inbound_min_interval_minutes` after the start of the previous one, less a minute of slack for
  scheduler jitter, is skipped and says so. The start is kept in the default cache store. Keep the
  interval at 4 minutes or more: with the fixed minute of slack a smaller one lets runs through
  more often than configured (every 2 minutes for an interval of 3, above KSeF's 20 queries an
  hour).
- **Inbound ids** are `<KSeF number>@<storage date>`, so the fetch needs no metadata query; an
  invoice the stored window lists without metadata keeps its plain KSeF number. Both forms match
  the same row. When you deduplicate on your side, use
  `KsefTransport::ksefNumberOf($message->provider_document_id)`.
- **Acknowledgements move the stored window.** The window moves on only once every invoice in it
  is acknowledged, so keep `einvoicing.inbound.acknowledge` on for KSeF: without it the poll reads
  the same window again and again. Two workers acknowledging at once can overwrite each other's
  write; a poll that lists an invoice whose row is already acknowledged acknowledges it again, so
  the next poll repairs such a lost acknowledgement.
- The buyer inbox lists an invoice a few minutes after KSeF accepted it (about two minutes on TE).

### Events

The document events fire for KSeF rows as for Peppol: `DocumentSent` when KSeF accepted the
invoice for processing, `DocumentDelivered` with the KSeF number, `DocumentFailed`,
`DocumentUnconfirmed`, and `DocumentReceived` for an inbound invoice. One more:

| Event | When |
|---|---|
| `KsefOfflineDeadlineApproaching` | an offline invoice still on its way to KSeF (queued, sending, pending or unconfirmed, or failed for a reason that does not settle it, such as a transport error) must reach KSeF by `$deadline` (`DateTimeImmutable`, the end of the day in Europe/Warsaw), which is within `deadline_warning_hours` or has passed; `$message` is the row. Fired by `einvoicing:ksef-deadlines` on every run while that holds, so remember what you have already notified |

### Offline mode and deadlines

An invoice issued while KSeF cannot be used is queued with `offline: true`; KSeF records it as
offline, and it must reach KSeF by a deadline. The row gets its offline24 deadline at once (the
next working day after `P_1`). `einvoicing:ksef-deadlines` lists the offline rows not yet
delivered, recomputes each deadline with the failures announced in Latarnia, the KSeF
availability service (a failure moves it to the 7th working day after the failure ends; a total
failure removes the duty, and the deadline is empty while a failure lasts), stores it on the row
and fires `KsefOfflineDeadlineApproaching`. When Latarnia cannot be reached, or for DEMO, which has
none, it computes without outages and says so. Working days skip weekends and the Polish statutory
holidays (years 2025 to 2099).

A row that failed for a reason that does not settle the invoice, such as a transport error during
a KSeF outage, stays in the list, marked "failed, resend with einvoicing:resolve --resend", and
still fires the event: the invoice must reach KSeF by its deadline all the same. Rows that failed
on validation, as a duplicate (category `duplicate`) or as an unsupported document are left out.

**The deadlines are a calculation aid, not legal advice.** Every offline row is taken as an
offline24 invoice; check the rules of the mode you actually used.

### QR codes

The visualisation of an FA(3) invoice carries KOD I, and an offline invoice also KOD II, signed
with the Offline certificate. The core builds both links; rendering an image is optional
(`ChillerlanQrRenderer` needs `chillerlan/php-qrcode` ^5):

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Transport\Ksef\Qr\KsefQrCodes;

$ksef = EInvoicing::ksef();
$environment = $ksef->settings()->environment;

$kod1 = KsefQrCodes::online($environment, $message->xml, $message->ksef_number);   // ->url, ->label

[$kod1, $kod2] = KsefQrCodes::offline(
    $environment,
    $ksef->ksefConfig()->context,
    $message->xml,
    $ksef->offlineCertificate(),        // einvoicing.ksef.offline_certificate
    $message->ksef_number,              // null until KSeF assigns one: caption "OFFLINE"
);
```

Use the exact bytes stored on the row: the links carry their hash.

### Doctor

With `einvoicing.ksef` in use, `einvoicing:doctor` adds `ksef` rows: the configuration and
credentials are read and checked, the certificates must parse and not be expired (an Offline
certificate expiring within 30 days is a warning), the cursor table must exist, and a transport
named `ksef` in `einvoicing.transports` is reported as shadowed (with `--live` its inbox is not
read). **By default it reads the public Latarnia status** of the environment, an unauthenticated
request with a 5-second timeout; a failure is only a warning. KSeF itself is never called, not
even with `--live`. The `retry_after` check includes the KSeF jobs' timeouts (see
[Queues](#queues)).

### Limits

- **FA(3) only.** FA_PEF (PEF (3)) invoices can be sent only by Peppol providers and are not
  supported; batch sending, technical corrections and exports are available on the core client
  (`EInvoicing::ksef()->transport()`), not through the queue.
- **One context per application:** `einvoicing.ksef` names one NIP.
- **Read quota:** 20 metadata queries per hour per context and IP; keep the 15-minute poll.
- **Download quota:** 64 invoice downloads per hour per context and IP. Every fetch spends one, and
  a rate-limited fetch counts towards `inbound.max_fetch_attempts`, so a burst far above 64
  invoices an hour can bring rows to the cap; retry them with
  `einvoicing:poll-inbound --transport=ksef --include-capped`.
- **Online sessions:** the core opens one on the first send of a process and replaces it before it
  expires; the bridge does not close it. The receipt is fetched while the session is open (as the
  live run on TE confirms).
- **The offline deadline helper** treats every offline row as offline24 and knows only the outages
  Latarnia announces.

## Spanish VERI\*FACTU

`EInvoicing::verifactu()` keeps the VERI\*FACTU billing records of Spanish invoices for the Spanish
tax authority (AEAT): it generates each record (`RegistroAlta`, `RegistroAnulacion`) from an
EN 16931 invoice of the core model, chains it with its SHA-256 `Huella` in the database, returns
its QR URL at once so the invoice can be printed, and submits it through the queue under the AEAT
flow control. B2Brouter can take the AEAT side instead, asynchronously. Requires
`intragile/php-einvoicing` 1.3 or later; the core's
[SPAIN.md](https://github.com/intragile/php-einvoicing/blob/main/docs/countries/SPAIN.md)
describes the mapping, the chain and the transports in detail.

> **Release label:** Verifactu live-verified on the B2Brouter sandbox (registered; hash and QR cross-checked); direct AEAT submission verified offline only — it needs a Spanish NIF and a qualified certificate

**Scope: VERI\*FACTU mode only.** Not covered, each deferred to a later release or connector:

- **non-VERI\*FACTU mode** (XAdES-signed local records, the event log and its chain, the 6-hour
  summary event, the chain self-check and the requerimiento endpoint);
- **TicketBAI** (the Basque foral territories) and **Navarra**, which run their own regimes;
- **Facturae 3.2.x / FACe** (B2G invoices);
- **SII**: SII taxpayers are outside VERI\*FACTU;
- **the Crea y Crece B2B e-invoicing mandate** (RD 238/2026, Orden HAC/1028/2026);
- **the paginated `ConsultaFactuSistemaFacturacion` export**: only a single-invoice lookup is
  available (`einvoicing:verifactu-status --lookup`).

**Dates.** Under current law (RDL 15/2025) VERI\*FACTU applies from 1 January 2027 to corporate-tax
payers and from 1 July 2027 to everyone else. On 5 October 2026 the Ministry announced a move of
both to October 2028 (**announced, not enacted**: nothing had been published in the BOE as of
23 November 2026). Nothing in this package depends on which date applies.

**No certification, no declaration.** This package is a component, not a certified billing
system (SIF). IntrAgile does not act as the producer of your billing software and signs no
*declaración responsable*; see [Your declaration](#your-declaration-declaración-responsable).

### Installation and the AEAT schemas

```bash
php artisan migrate                              # the chain, throttle and record tables
php artisan einvoicing:verifactu-install-schemas # downloads the AEAT schemas into schemas_dir
```

The AEAT XSDs (and the W3C xmldsig schema they import) are **downloaded, never bundled**: they
carry no licence that allows redistribution. The command downloads the pinned files and checks
their checksums into `einvoicing.verifactu.schemas_dir` (default `storage/einvoicing/verifactu`).
For an offline install, download them elsewhere and pass `--from=DIR`, the folder holding the eight
schema files by name; the checksums are checked the same way. `register()`, `cancel()` and
`correct()` refuse to run until the schemas are installed.

Billing records must be kept (legal retention), so rolling back the VERI\*FACTU migration refuses
while `einvoicing_verifactu_records` holds any row: export or move the records first and delete
them deliberately.

### Configuration

```dotenv
VERIFACTU_ENVIRONMENT=test               # test (AEAT pre-production) or production; production only when set
VERIFACTU_DRIVER=aeat                    # aeat (direct submission) or b2brouter
VERIFACTU_ISSUER_NIF=B12345674           # a single issuer; see "issuers" for several
VERIFACTU_ISSUER_NAME="Mi Empresa S.L."

# The billing software that produces the records (SistemaInformatico): yours, never IntrAgile.
VERIFACTU_PRODUCER_NAME="Your Software S.L."
VERIFACTU_PRODUCER_NIF=A39200019
VERIFACTU_SYSTEM_NAME=YourInvoicing
VERIFACTU_SYSTEM_ID=01
VERIFACTU_SYSTEM_VERSION=2.4.1
VERIFACTU_INSTALLATION_NUMBER=0001
VERIFACTU_SYSTEM_VERIFACTU_ONLY=true
VERIFACTU_SYSTEM_MULTIPLE_TAXPAYERS_POSSIBLE=false
VERIFACTU_SYSTEM_MULTIPLE_TAXPAYERS_INDICATOR=false

# Direct AEAT submission (driver aeat)
VERIFACTU_AEAT_CERTIFICATE=/run/secrets/aeat.p12   # a PKCS#12 file, or a PEM certificate ...
# VERIFACTU_AEAT_PRIVATE_KEY=/run/secrets/aeat.key.pem  # ... with its private key
VERIFACTU_AEAT_PASSWORD=...
# VERIFACTU_CA_BUNDLE=/etc/ssl/aeat-ca.pem          # must contain the FNMT root (see below)

# B2Brouter (driver b2brouter): the name of a b2brouter transport of einvoicing.transports
# VERIFACTU_B2BROUTER_TRANSPORT=b2brouter

# VERIFACTU_SCHEMAS_DIR=/var/lib/verifactu
```

The `einvoicing.verifactu` block also holds `timezone` (`Europe/Madrid`, the zone of the
generation timestamp), `system.foreign_id` (for a producer without a Spanish NIF: give either `nif`
or `foreign_id`), `aeat.port` (`personal` or `seal`, which picks the AEAT host), `aeat.timeout`
(30 s) and `aeat.connect_timeout` (10 s), `b2brouter.poll_attempts` (10) and
`b2brouter.poll_delay` (30 s), `submission` (`queue`, `connection`, `max_batch` 1 to 1000,
`initial_wait` 60 s, `throttle_scope`), `cross_check` (`strict` or `report`) and
`stuck_after_minutes` (60; at least the submission job's timeout in minutes plus one, that is
`aeat.timeout` + 60 s, so a running submission is never taken as stuck). The `system` block has no defaults: the producer of the installation
declares itself. It is needed for the AEAT driver; while every issuer uses B2Brouter, which writes
its own `SistemaInformatico`, it may stay empty.

**Issuers.** `einvoicing.verifactu.issuers` maps each obligated issuer's NIF to its `name` and,
optionally, its own `driver`, a `representative` (`name`, `nif`) and an `aeat` override of the
certificate settings:

```php
// config/einvoicing.php
return [
    // ...
    'verifactu' => [
        // ...
        'issuers' => [
            'B12345674' => ['name' => 'Mi Empresa S.L.'],
            'A58818501' => [
                'name' => 'Cliente Prueba S.A.',
                'driver' => 'aeat',
                'aeat' => ['certificate' => '/run/secrets/a58818501.p12', 'password' => env('AEAT_A58818501_PASSWORD')],
            ],
        ],
    ],
];
```

Issuers are resolved at run time through the core's `VerifactuIssuerResolver`; the config-backed
`ConfigIssuerResolver` is the default. An application that keeps its tenants in the database binds
its own, which returns an `IssuerContext` (name, driver, environment, credentials, representative)
per NIF and lists every issuer for the doctor:

```php
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\AeatCredentials;
use IntrAgile\EInvoicing\Spain\CertificateKind;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerContext;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\UnknownIssuer;
use IntrAgile\EInvoicing\Spain\Verifactu\VerifactuIssuerResolver;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;

final class TenantIssuerResolver implements VerifactuIssuerResolver
{
    public function resolve(Nif $issuer): IssuerContext
    {
        $tenant = Tenant::query()->where('nif', $issuer->value)->first()
            ?? throw new UnknownIssuer("No tenant for {$issuer->value}.");

        return new IssuerContext(
            $issuer,
            $tenant->legal_name,
            IssuerDriver::Aeat,
            VerifactuEnvironment::from(config('einvoicing.verifactu.environment')),
            new AeatCredentials($tenant->certificate_path, $tenant->certificatePassword(), null, CertificateKind::Personal),
        );
    }

    public function issuers(): array
    {
        return Tenant::query()->pluck('nif')->map(fn (string $nif): Nif => Nif::parse($nif))->all();
    }
}

// AppServiceProvider::register()
$this->app->singleton(VerifactuIssuerResolver::class, TenantIssuerResolver::class);
```

The context's environment must be the configured `einvoicing.verifactu.environment`; another one
is refused.

### The AEAT certificate and TLS

Direct submission authenticates with the issuer's (or its representative's) qualified certificate
over mutual TLS. `aeat.certificate` is a PKCS#12 file with its password, or a PEM certificate with
`aeat.private_key`. The bridge writes the certificate and key as PEM files (mode 0600) into a 0700
folder under `storage/einvoicing/verifactu/tls`, hands them to its Guzzle client (`cert`,
`ssl_key`, `verify` with the CA bundle) and deletes them when the client is dropped; files a killed
worker left behind are removed after a day. One client is kept per credential set, and a
certificate renewed in place is picked up on the next use. TLS verification is never disabled.

AEAT's servers chain to the FNMT root **AC RAIZ FNMT-RCM SERVIDORES SEGUROS**, which many system CA
bundles lack. `aeat.ca_bundle` (`VERIFACTU_CA_BUNDLE`) points to a bundle that contains it; `null`
uses the OpenSSL default. The doctor warns when the bundle has no FNMT root, shows the certificate
holder and its days to expiry, and fails on an expired one.

### Registering, cancelling and correcting

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\InvoiceId;

$record = EInvoicing::verifactu()->register($invoice, reference: 'order-1042');
$record->qr_url;   // print it on the invoice now (AEAT driver)
$record->hash;     // the Huella; $record->state is "generated" until AEAT answers

$id = new InvoiceId(Nif::parse('B12345674'), 'F-2027-0042', new \DateTimeImmutable('2027-01-15'));
EInvoicing::verifactu()->cancel($id, reference: 'void-17');
EInvoicing::verifactu()->correct($rectifiedInvoice);       // SpanishInvoice::$operation: not Initial
EInvoicing::verifactu()->record($id);                      // the latest record row, or null
```

- **`register(Invoice $invoice, ?string $reference = null)`** generates the `RegistroAlta`
  synchronously under the chain lock, stores the exact XML and the literal timestamp text, and
  returns the `VerifactuRecord` row with its `qr_url`, so the invoice can be printed at once.
  Spanish data the EN 16931 model does not carry (invoice type, correction type, exemption
  article, recipients without a VAT number, the operation flags) comes from a core
  `SpanishInvoice` extension on the invoice. An invoice that already has a registration record in
  its chain is refused.
- **`cancel(InvoiceId $invoice, ?string $reference = null, AnulacionOperation $operation = Normal, bool $registeredByPreviousSystem = false)`**
  appends a `RegistroAnulacion`. Use `AnulacionOperation::WithoutPriorRecord` for an invoice AEAT
  never received. An invoice registered by the software used before the chain was seeded has no
  local record: pass `registeredByPreviousSystem: true`, which only a seeded chain accepts.
- **`correct(Invoice $invoice, ?string $reference = null)`** appends a new registration of an
  invoice that already has one, for a correction (`Subsanacion`) or a new submission after a
  rejection; the operation comes from `SpanishInvoice::$operation`.
- **`record(InvoiceId $invoice)`** returns the latest record row of the invoice.

A record that AEAT rejects stays in the chain as generated; the chain is never rewritten. Fix the
invoice and submit it again with `correct()`.

**Transactions.** On the AEAT driver, `register()`, `cancel()` and `correct()` may run inside your
database transaction: the job and the event follow the commit, and nothing is queued on a
rollback. The chain row stays locked until that transaction commits, so other writers of the same
chain wait for it; keep the transaction short. On the B2Brouter driver, `register()` refuses with
a `LogicException` inside an open transaction: B2Brouter keeps an import even if your transaction
rolls back, and the row that knows of it would be lost. Call it after the commit.

### Queue and AEAT flow control

Records are sent by `SubmitVerifactuRecords` on `submission.connection` / `submission.queue`
(`null` falls back to `einvoicing.queue`). AEAT binds its wait between submissions
(`TiempoEsperaEnvio`) to the billing system, so by default there is **one throttle per
installation** (`NumeroInstalacion` and environment), shared by every issuer of the installation:
each run sends at most one request, of one issuer, with up to `max_batch` records in chain order,
and waits `initial_wait` seconds until AEAT names its own wait. The wait is skipped only for a
submission of 1,000 records, as Orden HAC/1177/2024 art. 16.2 allows. With written confirmation
from AEAT that your issuers may be throttled separately, set `throttle_scope` to `issuer`.

The bytes sent are the bytes stored. A send without an answer leaves its records `unconfirmed`;
they are resent with the same bytes after the `einvoicing.sending.backoff` delays, up to
`sending.max_attempts`, and an AEAT duplicate answer then confirms them. A submission refused as a
whole (a non-transient SOAP `Client` fault or an authentication fault) registered nothing: its
records keep their state, the throttle row is blocked and `VerifactuSubmissionBlocked` fires;
`einvoicing:verifactu-submit` clears the block once the cause is fixed. The job's timeout is set,
and the doctor checks it against `retry_after` (of `submission.connection` too, when it differs).

A request carries at most one record per invoice and kind: a correction written before the first
record of its invoice left waits for the next submission, so each record gets its own answer. A
submission job killed between its claim and AEAT's answer (a worker crash, a deploy) leaves its
records in `sending`; they are not resent automatically. After `stuck_after_minutes`, the doctor
reports them and `einvoicing:verifactu-submit --retry-unconfirmed` resends them with the same
bytes.

### B2Brouter mode

With the driver `b2brouter` (globally or per issuer), B2Brouter is the billing system: it
generates, chains and submits the record on its own installation, and also delivers the invoice
through the contact's transport. `register()` imports the invoice (UBL) synchronously and returns
a **`pending`** row without QR or hash; `PollVerifactuTaxReport` then polls the tax report (15 reads
3 s apart per run, then again after `poll_delay`, `poll_attempts` runs in all). Once AEAT registered
the record, the bridge stores B2Brouter's XML, its `Huella` and the QR URL B2Brouter prints (the
production `www2.agenciatributaria.gob.es` host, in B2Brouter's sandbox too), attaches it to a
B2Brouter chain of its own, and fires `VerifactuRecordRegistered`. `registered_with_errors` (AEAT's
*AceptadoConErrores*) is stored as `accepted_with_errors` and attached. `cancel()` and `correct()`
are refused in this mode; manage cancellations in B2Brouter. An import whose outcome is unknown is
looked up by number before it is ever imported again (`einvoicing:verifactu-submit
--retry-unconfirmed`, or `VerifactuManager::retryB2BrouterImport()`); when B2Brouter refuses that
new import (a taken number, for instance), the row stays `unconfirmed` with the reason, since
B2Brouter may hold the invoice after all.

When an import stays `unconfirmed` after `--retry-unconfirmed`:

1. Look the invoice up in B2Brouter by its number and issue date (the command prints both, with the
   row id).
2. If B2Brouter holds it, or the reason can be fixed there (a number taken by a draft, for
   instance), fix it and run `php artisan einvoicing:verifactu-submit --retry-unconfirmed` again:
   the row is looked up by number first, so an invoice B2Brouter already holds is adopted, never
   imported twice.
3. If B2Brouter truly refuses the invoice and does not hold it, no command marks the row failed.
   Mark it failed in the database yourself (add your connection's table prefix, if any, and put
   the row id in place of `42`):

   ```sql
   UPDATE einvoicing_verifactu_records
      SET state = 'failed', error_code = 'import_abandoned',
          error_message = 'Refused by B2Brouter; abandoned by an operator.', updated_at = CURRENT_TIMESTAMP
    WHERE id = 42 AND driver = 'b2brouter' AND state = 'unconfirmed' AND b2brouter_invoice_id IS NULL;
   ```

   Exactly one row must change. Then run `php artisan einvoicing:verifactu-submit
   --retry-unconfirmed` once more, so the records that waited for this import (`pending` with
   `awaiting_predecessor`) are polled again: they attach if they link to the chain head (an empty
   chain, for instance); otherwise they are failed (`chain_conflict`) and need an operator. The
   invoice itself is then not registered with AEAT: issue it again, or handle it in B2Brouter.

Every registered record is **cross-checked** against our own engine: the XML against the schema,
the `Huella` recomputed from its text, its link to the previous stored record, a semantic
comparison with our own record of the same invoice, and the URL in B2Brouter's QR code. In `strict`
mode a failed check fails the row (it stays attached, since AEAT holds it); in `report` mode it is
only reported. Either way `VerifactuCrossCheckFailed` fires. Two differences are **known
divergences**, never failures: the exemption article follows the B2Brouter account's default
(`OperacionExenta` E1 for any exempt line), and a credit note by difference (R1, I) carries
positive amounts, where AEAT expects the signed difference. Reading the QR image needs
`chillerlan/php-qrcode` with `ext-gd` or `ext-imagick`; without it the URL is compared by its
parameters.

Records attach in the order the records themselves state: a record whose predecessor is still
pending or unconfirmed (an import whose outcome is unknown) waits for it, and is polled again once
that predecessor settles, including when a retry adopts or imports it again. A record that does
not link is failed and left out of the chain, which the doctor then reports as blocked.

### Seeding a chain and switching drivers

A chain is keyed by issuer NIF, installation number and environment, so test and production
records never share one. To continue a chain that another implementation started, seed it with
the last record that software registered; its `Huella` is trusted and verification starts after
it:

```bash
php artisan einvoicing:verifactu-seed-chain B12345674 F-2026-0999 2026-12-31 "$HUELLA"   # the 64-character Huella of that record
```

The chain is seeded under `system.installation_number`, the only installation this system writes
to; `--installation` merely confirms that number and refuses any other.

Switching an issuer between the AEAT and B2Brouter drivers starts a new chain on a different
billing system, so `register()` and the doctor refuse while an open chain of the other driver
exists (moving to AEAT, also while B2Brouter imports of the issuer are not attached to a chain
yet). The cut-over is **drain → switch → close**:

1. **Drain** the old chain: let its records settle (`einvoicing:verifactu-submit`,
   `einvoicing:verifactu-status --lookup`, `--retry-unconfirmed` for B2Brouter imports).
2. **Switch** the issuer's `driver`.
3. **Close** the old chain with `einvoicing:verifactu-close-chain --issuer=B12345674`, which refuses
   while any of its records is `generated`, `sending`, `unconfirmed` or `pending`.

Records still left on the AEAT chain after a switch to B2Brouter are sent and looked up with the
issuer's AEAT credentials, as long as they stay configured: keep them until the chain has drained.
Without them, that issuer's records are held (`error_code` `aeat_credentials`) and the other
issuers on the same throttle key go on sending; restore the credentials and run
`einvoicing:verifactu-submit`.

### Events

| Event | When |
|---|---|
| `VerifactuRecordGenerated(record)` | a record was generated and queued (AEAT driver) |
| `VerifactuRecordAccepted(record, withErrors, errorCode)` | AEAT accepted it, possibly with errors (*AceptadoConErrores*) |
| `VerifactuRecordRejected(record, errorCode, errorMessage)` | AEAT rejected it, or the B2Brouter tax report failed |
| `VerifactuRecordUnconfirmed(record)` | its outcome is unknown after the automatic resends or polls; at once for a first send AEAT answered as a duplicate (held for a lookup) and for a B2Brouter poll that failed unexpectedly. A B2Brouter import whose outcome is unknown fires nothing: `register()` throws `OutcomeUnknown` and the row stays `unconfirmed` |
| `VerifactuRecordRegistered(record)` | B2Brouter mode: AEAT registered B2Brouter's record; QR URL and hash are on the row |
| `VerifactuCrossCheckFailed(record, result)` | B2Brouter's record differs from ours beyond the known divergences |
| `VerifactuSubmissionBlocked(throttleKey, code, message)` | AEAT refused a whole submission; sending waits for `einvoicing:verifactu-submit` |

### Commands

| Command | Purpose |
|---|---|
| `einvoicing:verifactu-install-schemas [--from=DIR]` | download (or copy) and check the AEAT schemas |
| `einvoicing:verifactu-submit [--issuer=] [--retry-unconfirmed]` | clear a blocked submission and schedule a submission run for the due records (the run applies the AEAT wait); `--retry-unconfirmed` takes again the records no longer resent or polled automatically, including `sending` rows left by a killed worker |
| `einvoicing:verifactu-status NUMBER [--date=] [--issuer=] [--lookup]` | the stored state, CSV and errors; `--lookup` asks AEAT (or B2Brouter) about the latest record, never submits, and marks an `unconfirmed` record accepted when AEAT holds the same `Huella` (without a CSV, which AEAT cannot return later) |
| `einvoicing:verifactu-verify-chain [--issuer=]` | recompute every stored hash and link, and compare each head with its last record; fails only on integrity breaks |
| `einvoicing:verifactu-seed-chain ISSUER NUMBER DATE HASH [--installation=]` | continue a chain from another implementation (`--installation` only confirms `system.installation_number`) |
| `einvoicing:verifactu-close-chain [--issuer=] [--driver=]` | close the chains of the driver an issuer no longer uses |

### Doctor

`einvoicing:doctor` adds `verifactu` rows once an issuer is configured: the configuration, the
installed schemas and their checksums, the declared system, every issuer the resolver lists, each
AEAT certificate (holder, days to expiry; expired fails), the CA bundle's FNMT root (a warning), the
B2Brouter key against the environment (`test_` outside production), the tables, driver switches,
chain heads against their last records, seeded chains, records stuck beyond `stuck_after_minutes`,
blocked or held-back B2Brouter chains, failed cross-checks and blocked submissions. `--live` adds a
TLS handshake with the configured AEAT endpoint, never a submission; run it yourself, with your
certificate.

### Your declaration (declaración responsable)

The producer of a billing system signs a *declaración responsable* that the system meets the
RRSIF (RD 1007/2023, Orden HAC/1177/2024). **IntrAgile does not act as producer, signs no
declaration and ships no template for one.** This package implements record generation, the
`Huella` and the chain, the record XML, the QR URL and the AEAT SOAP client. Your application must
still guarantee:

- the indefectible coupling of every invoice and its record (no invoice without a record);
- that records are never deleted or altered;
- that records are sent immediately;
- the correction and cancellation flows;
- its own `SistemaInformatico` identity (the `system` block);
- the declaration itself, naming this library and its version as a component.

### Limits

- **Direct AEAT submission is verified offline only**, against the AEAT schemas, the published
  examples and a local TLS endpoint; a live AEAT run needs a Spanish NIF and a qualified
  certificate.
- **B2Brouter mode:** no cancellation or correction through the bridge; the exemption article and
  the credit-note sign follow the B2Brouter account settings.
- **Lookups:** one invoice at a time; no paginated export.
- **Non-VERI\*FACTU mode** and the other Spanish regimes listed above are not covered.

## Queues

Configure the connection and queue name with `einvoicing.queue.connection` and
`einvoicing.queue.name` (`null` uses the application defaults). **Use a real, asynchronous
queue in production.**

- **`sync`, `deferred` and `background` run jobs in the calling process and ignore delays.**
  On these drivers a retryable send failure goes straight to `failed` (the message says
  retries need an asynchronous queue), status polling and the expiry of a stuck `sending` claim
  are not scheduled, and on `sync` the fetch and acknowledgement of a webhook's document run
  inside the webhook request, so an error there answers the webhook with `500` (and, with
  `APP_DEBUG`, a debug page). The doctor warns about these drivers.
- **`retry_after` must be longer than the job timeouts.** With the default `http` settings the
  send job's timeout is 100 seconds and the inbound job's 170; with `einvoicing.ksef` in use, the
  KSeF send job's is 240 and the KSeF status job's 310. If a job outlives the
  connection's `retry_after`, another worker reserves it again, and since these jobs are never
  retried it is marked failed while the first run may still succeed. The doctor checks this.
- **Run workers with `pcntl`** (usually available in Linux CLI builds; it needs PHP built with
  `--enable-pcntl`), so a worker stops a job at its timeout. `einvoicing:resolve` treats a
  long-`sending` row as abandoned on that assumption. The doctor checks `pcntl` in the PHP that
  runs the doctor, which is not necessarily the PHP your workers run; it warns when `pcntl` is
  missing there, and otherwise reminds you to check the workers.
- **Amazon SQS accepts delays of at most 900 seconds.** On an `sqs` connection every delay is
  capped at 900 seconds, so a longer backoff, status poll delay or `Retry-After` simply waits
  less: the default `sending.backoff` (up to 1800) and `status.delays` (up to 21600) then poll
  and retry earlier than configured. Keep the claim expiry (send job timeout plus 30 seconds,
  130 with the default `http` settings, 270 for a KSeF send) below 900, or a slow send is marked `unconfirmed` while
  it still runs. Amazon SQS does not allow per-message delays on FIFO queues (`DelaySeconds`
  can only be set on the queue), so retries and status polls would not be spaced out. **Do not
  use a FIFO queue.** (See `DelaySeconds` in the
  [SendMessage API reference](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/APIReference/API_SendMessage.html).) The doctor warns about delays over
  900 seconds and fails on a FIFO queue, recognised by a queue name, the connection's `prefix`,
  name and `suffix`, or a full queue URL that ends in `.fifo`.

## Custom drivers

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;

EInvoicing::extend('my-ap', fn ($app, #[\SensitiveParameter] array $config) => new MyAccessPointTransport($config['token']));
```

```php
// config/einvoicing.php
return [
    // ...
    'transports' => [
        'other' => ['driver' => 'my-ap', 'token' => env('MY_AP_TOKEN')],
    ],
];
```

The factory returns a core `Transport` (and an `InboundSource` if it receives documents). With
validation on, its documents are validated like those of the built-in driver: by the send job
before the claim, and by the `ValidatingTransport` that `EInvoicing::transport()` wraps it in. Its
contract with this package:

- **After the request has been sent, throw only `TransportException` subclasses** of the core,
  chosen by what you know: `OutcomeUnknown` when the access point may have accepted the document,
  `TransportUnavailable` or `RateLimited` only when a retry cannot duplicate it. An
  `InvalidArgumentException` is taken to mean "nothing was sent" and marks the document
  `failed`, so throwing it after the request risks a duplicate when the operator resends.
- **Exception messages must not quote credentials**, in any form (encoded, inside a URL). They
  are stored in `failure_message` and `last_error`, and the doctor redacts only the built-in key
  names. Mark the factory's `$config` parameter `#[\SensitiveParameter]` so the credentials stay
  out of stack traces.
- **`status()` must answer for the exact document id it was given**: the returned status's
  `documentId` must equal it (strict string equality). A driver that normalises ids (case,
  prefixes, whitespace) has its answers ignored, and the document never settles.

Transports are cached per name once built. Changing `einvoicing.validation.enabled` at runtime
does not rewrap a transport that was already built; set it in the configuration, not in code.

To receive webhooks for a custom driver, register its parser, a core `WebhookParser`:

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;

EInvoicing::extendWebhook('my-ap', fn ($app, #[\SensitiveParameter] array $config) => new MyAccessPointWebhook($config['webhook_secret']));
```

The factory gets the application and the transport's configuration, whose `webhook_secret` is
always set (the route refuses a transport without one). The route then accepts notifications for
every transport of that driver. A custom driver has no configured account, so no notification is
ignored as foreign; check the account in your parser if you need to. A parser registered for a
built-in driver replaces the built-in one.

## Testing

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Transport\DeliveryState;
use IntrAgile\EInvoicing\Transport\Exception\OutcomeUnknown;
use IntrAgile\EInvoicing\Transport\InboundDocument;
use IntrAgile\EInvoicing\Transport\InboundReference;
use IntrAgile\EInvoicing\Transport\OutboundDocument;
use IntrAgile\EInvoicing\Transport\SendResult;

$fake = EInvoicing::fake();

$fake->willReturn(
    new SendResult('doc-1', DeliveryState::Pending),   // the next send
    new OutcomeUnknown('no answer'),                   // the one after
);

// ... run your code ...

$fake->assertSent(fn (OutboundDocument $d): bool => $d->receiver->toString() === '0208:0123456789');
$fake->sent();                                         // list<OutboundDocument>

$fake->receive(new InboundDocument(
    new InboundReference('in-1', null, null, null, new \DateTimeImmutable),
    $ublXml,
));
$this->artisan('einvoicing:poll-inbound');
$fake->acknowledged();                                 // ['in-1']
```

`fake()` replaces every transport with one in-memory `FakeTransport` and skips validation.
Without queued answers each send returns a `delivered` result with ids `fake-1`, `fake-2`, and
so on. With the `sync` queue connection in tests, the whole pipeline runs at once; refresh the
returned message to see its final state. Webhook signatures are still checked against the
configured secret while faked.

## Artisan commands

| Command | Purpose |
|---|---|
| `einvoicing:doctor [--live]` | check transports, webhook URLs, validation rules, the installed rule packages, tables, inbound documents at the attempt cap, numeric job settings, the queue driver, `pcntl`, `retry_after` and SQS delays; `--live` also calls each inbox once to check the credentials, except the KSeF inbox |
| `einvoicing:install-rules [--from=DIR]` | install the Peppol BIS Billing validation rules |
| `einvoicing:ksef-certificate [--type=offline\|authentication] [--algorithm=ec\|rsa] [--name=] [--certificate=] [--private-key=]` | enrol a KSeF Offline or Authentication certificate and write it with its key (mode 0600); never overwrites, never prints the key |
| `einvoicing:ksef-deadlines` | list offline KSeF invoices not yet delivered with their deadlines and fire `KsefOfflineDeadlineApproaching`; schedule it hourly |
| `einvoicing:poll-inbound [--transport=] [--include-capped]` | record waiting inbound documents and queue their intake; schedule it. `--include-capped` gives each document at `inbound.max_fetch_attempts` one more attempt. `--transport=ksef` reads the KSeF inbox, at most once per `einvoicing.ksef.inbound_min_interval_minutes` |
| `einvoicing:resolve ID (--sent=DOCUMENT-ID \| --resend) [--force]` | resolve an `unconfirmed` send, or queue a document again |
| `einvoicing:verifactu-*` | install the AEAT schemas, submit, status and lookup, verify, seed and close VERI\*FACTU chains; see [Commands](#commands) |

## What is verified

| Area | How | Status |
|---|---|---|
| State machine, send policy, retries, claim expiry, status polling, inbound intake, webhooks, commands | Offline feature tests with `FakeTransport` and stub transports (CI runs PHP 8.3, 8.4 and 8.5 × Laravel 12 and 13) | Run on every test run |
| Peppol validation through the manager | Opt-in group `peppol-rules`: installs the official rules from a local copy and sends the Peppol base example through a validating transport | Run before releases |
| Recommand driver | Core offline tests with fixtures written from Recommand's published API description; core live group `recommand-live`; bridge live smoke run `RecommandSmokeTest`, which sends an invoice through `EInvoicing::send()` | **Live-tested on the Peppol test network** (2026-11-15: core run and bridge run) |
| B2Brouter and e-invoice.be drivers | Core offline contract tests with fixtures written from each provider's public API reference, core live groups `b2brouter-live` and `einvoicebe-live`; manager, config, doctor and webhook routing tested here | **Live-tested against the provider sandbox (simulated network)** (2026-11-15, core) |
| French flow (`EInvoicing::france()`, `EInvoicing::annuaire()`, `Annuaire` binding) | Core offline contract tests and core live group `b2brouter-france-live`; bridge live smoke run `B2BrouterFranceSmokeTest`: the `Annuaire` binding, a send that reaches `Deposited` and a full payment that reads `Collected` | **Live-tested against the B2Brouter sandbox** (2026-11-18: core run and bridge run, French sandbox account); an accepted Flux 10 deposit is not yet live-tested |
| Polish KSeF (`EInvoicing::ksef()`, `SendKsefInvoice`, `PollKsefStatus`, KSeF inbox, deadlines, certificate command, doctor row) | Offline feature tests against canned KSeF answers; bridge live run `KsefLiveTest` (group `ksef-live`) on KSeF TE: a queued send on the `sync` connection that ends delivered with its KSeF number and receipt, and the buyer's `einvoicing:poll-inbound --transport=ksef` that fires `DocumentReceived` within 15 metadata queries; the core live group `ksef-live` covers offline sending, KSeF certificates and the QR links | **Live-tested on KSeF TE** (2026-11-22, bridge run on PHP 8.5 and 8.3); offline deadlines, the certificate command and the doctor row are tested offline here |
| Spanish VERI\*FACTU (`EInvoicing::verifactu()`, chain store, `SubmitVerifactuRecords`, `PollVerifactuTaxReport`, commands, doctor rows) | Offline feature tests against the AEAT schemas, canned AEAT SOAP answers, B2Brouter answers copied from the core's live captures and a local TLS endpoint; chain concurrency with forked writers on SQLite (MySQL and PostgreSQL in the `databases` CI job); bridge live run `B2BrouterVerifactuLiveTest` (group `b2brouter-spain-live`) on the B2Brouter Spanish sandbox, `sync` queue: two chained F1 invoices, an exempt invoice and an R1/I credit note, each `registered`, attached to one B2Brouter chain and cross-checked (exemption article and credit-note sign as known divergences), then `einvoicing:verifactu-verify-chain` | **Verifactu live-verified on the B2Brouter sandbox** (2026-11-24, bridge run on PHP 8.5); direct AEAT submission verified offline only |
| Webhook signatures | Offline tests against each provider's documented scheme (e-invoice.be also against its published test vector) | Verified offline; **B2Brouter webhooks live-tested** (2026-11-18: real signed sandbox deliveries replayed through the bridge); Recommand and e-invoice.be webhooks not live-tested |

The core requires `brick/math` ^0.14.8, which Laravel 13 also accepts.

The run results, with dates and commits, are in the core's
[VERIFICATION.md](https://github.com/intragile/php-einvoicing/blob/main/docs/VERIFICATION.md). Webhook signatures are verified offline against each provider's documented scheme (e-invoice.be also against its published test vector); only B2Brouter's webhooks were live-tested (2026-11-18, sandbox deliveries replayed through the Laravel bridge).

The default test run of this package makes no network call; the live smoke run is excluded from
it (groups `recommand-live`, `b2brouter-france-live`, `ksef-live` and `b2brouter-spain-live`,
testsuite `Live`). The KSeF
run needs no account: it reaches only KSeF TE (`https://api-test.ksef.mf.gov.pl/v2`, every other
KSeF host is refused), uses random NIPs with self-signed seals written to a temporary directory
(mode 0600), every request has a timeout, and it does not start between 15:40 and 18:00
Europe/Warsaw, around TE's daily maintenance. The Recommand run
refuses unless the key belongs to a Recommand playground on the Peppol test network; the French
run (`B2BROUTER_API_KEY` and `B2BROUTER_ACCOUNT_ID` of a French B2Brouter account) refuses any key
but a `test_` key on the sandbox host or a `stag_` key on the staging host. The Spanish run
(`B2BROUTER_ES_API_KEY` and `B2BROUTER_ES_ACCOUNT_ID` of a Spanish B2Brouter sandbox account) refuses
any key but a `test_` key on `https://api.b2brouter.net` before its first request, never calls
AEAT, and creates four sandbox invoices per run.
`B2BrouterWebhookReplayTest` in the same group replays webhook deliveries captured from B2Brouter
(`B2BROUTER_WEBHOOK_CAPTURE_DIR`, `B2BROUTER_WEBHOOK_SECRET`) through the webhook route without
any network call. The core has opt-in live groups:
`recommand-live` for a Recommand playground team, `b2brouter-live` for a B2Brouter sandbox and
`einvoicebe-live` for an e-invoice.be sandbox company; see its
[TRANSPORT.md](https://github.com/intragile/php-einvoicing/blob/main/docs/TRANSPORT.md).

### Known limits (deferred to 1.x)

- No operator command yet for a `pending` row whose status polls are used up; check such a row at
  the access point (`einvoicing:resolve` handles `unconfirmed` and abandoned `sending` rows).
- e-invoice.be: the inbox is cut at the look-back on our side; the provider's `date_from` filter
  is not used yet.
- No guard against a second status-poll chain started by a stateless webhook; the extra chain
  shares the document's `status_checks` budget, so the total stays within `status.max_attempts`.

None of them is a correctness issue.

## Versioning

The eight packages are released together with the same version number and follow Semantic
Versioning: the five engine packages, `intragile/php-einvoicing`, the rule packages
`intragile/einvoicing-rules-en16931`, `intragile/einvoicing-rules-xrechnung` and
`intragile/einvoicing-rules-facturx`, and `intragile/laravel-einvoicing`, and the three entry
packages `intragile/laravel-xrechnung`, `intragile/laravel-zugferd` and `intragile/laravel-peppol`.
Use the same minor version of each.
A rule package declares a Composer `conflict` with cores older than its own minor version (and
with the next major), so new rules are never installed next to an engine that cannot run them.

## Development

```bash
composer test        # PHPUnit
composer analyse     # PHPStan, max level
composer format      # Pint
EINVOICING_PEPPOL_FROM=/path/to/peppol/sch vendor/bin/phpunit --group peppol-rules
vendor/bin/einvoicing-rules install spain --dir=build/verifactu
vendor/bin/phpunit --group verifactu-official --fail-on-skipped
EINVOICING_TEST_DB_URL=mysql://root:secret@127.0.0.1:3306/einvoicing_test vendor/bin/phpunit --group verifactu-concurrency --group verifactu-databases --fail-on-skipped
```

**Any test run with `EINVOICING_TEST_DB_URL` set wipes that database:** the tests that read it
(the chain concurrency test and the doctor's database queries, groups `verifactu-concurrency` and
`verifactu-databases`) drop and re-create every table of that MySQL or PostgreSQL database. Give it
a throwaway database, never one that holds data; without the variable they run on SQLite.

## License

MIT. See [LICENSE](LICENSE).

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
