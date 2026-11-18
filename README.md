# Laravel E-Invoicing

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

[![Tests](https://github.com/intragile/laravel-einvoicing/actions/workflows/tests.yml/badge.svg)](https://github.com/intragile/laravel-einvoicing/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/intragile/laravel-einvoicing.svg)](https://packagist.org/packages/intragile/laravel-einvoicing)
[![PHP](https://img.shields.io/packagist/php-v/intragile/laravel-einvoicing.svg)](https://packagist.org/packages/intragile/laravel-einvoicing)
[![License](https://img.shields.io/packagist/l/intragile/laravel-einvoicing.svg)](LICENSE)

The Laravel bridge for [`intragile/php-einvoicing`](https://github.com/intragile/php-einvoicing):
send and receive EN 16931 invoices over **Peppol** from a Laravel application.

[Magyar nyelvű leírás → README.hu.md](README.hu.md)

> **Status:** version 1.0.0, released 2026-11-16. What is verified, and how, is in
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
model, so the delivered bytes may differ from your XML. Webhook signatures are verified offline against each provider's documented scheme (e-invoice.be also against its published test vector); webhooks were not live-tested. What each driver sends, its status
mapping, inbound behaviour and webhook details are in the core's
[TRANSPORT.md](https://github.com/intragile/php-einvoicing/blob/main/docs/TRANSPORT.md).

## Installation

```bash
composer require intragile/laravel-einvoicing
php artisan vendor:publish --tag=einvoicing-config     # optional: config/einvoicing.php
php artisan einvoicing:install-rules                   # the Peppol BIS validation rules
php artisan migrate                                    # two tables: einvoicing_outbound_documents, einvoicing_inbound_documents
php artisan einvoicing:doctor                          # checks everything below
```

> **Before the first release** neither this package nor `intragile/php-einvoicing` is on
> Packagist. Until then, clone both repositories next to your application and add them as
> path repositories in its `composer.json`:
>
> ```json
> "repositories": [
>     { "type": "path", "url": "../php-einvoicing", "options": { "versions": { "intragile/php-einvoicing": "1.1.0" } } },
>     { "type": "path", "url": "../php-einvoicing/packages/rules-en16931", "options": { "versions": { "intragile/einvoicing-rules-en16931": "1.0.0" } } },
>     { "type": "path", "url": "../php-einvoicing/packages/rules-xrechnung", "options": { "versions": { "intragile/einvoicing-rules-xrechnung": "1.0.0" } } },
>     { "type": "path", "url": "../php-einvoicing/packages/rules-facturx", "options": { "versions": { "intragile/einvoicing-rules-facturx": "1.0.0" } } },
>     { "type": "path", "url": "../laravel-einvoicing", "options": { "versions": { "intragile/laravel-einvoicing": "1.0.0" } } }
> ]
> ```
>
> and require `"intragile/laravel-einvoicing": "^1.0"`. The `versions` option gives each path
> package the version the constraints between the packages expect.

The migration is loaded from the package. To keep a copy in your application, publish it with
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

**Doctor.** With `einvoicing.france.transport` set, `einvoicing:doctor` adds a `france` row: the
transport must be a valid B2Brouter transport, and the row says whether its key is a sandbox
(`test_`), staging (`stag_`) or production key. It makes no request.

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
  send job's timeout is 100 seconds and the inbound job's 170. If a job outlives the
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
  130 with the default `http` settings) below 900, or a slow send is marked `unconfirmed` while
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
| `einvoicing:doctor [--live]` | check transports, webhook URLs, validation rules, the installed rule packages, tables, inbound documents at the attempt cap, numeric job settings, the queue driver, `pcntl`, `retry_after` and SQS delays; `--live` also calls each inbox once to check the credentials |
| `einvoicing:install-rules [--from=DIR]` | install the Peppol BIS Billing validation rules |
| `einvoicing:poll-inbound [--transport=] [--include-capped]` | record waiting inbound documents and queue their intake; schedule it. `--include-capped` gives each document at `inbound.max_fetch_attempts` one more attempt |
| `einvoicing:resolve ID (--sent=DOCUMENT-ID \| --resend) [--force]` | resolve an `unconfirmed` send, or queue a document again |

## What is verified

| Area | How | Status |
|---|---|---|
| State machine, send policy, retries, claim expiry, status polling, inbound intake, webhooks, commands | Offline feature tests with `FakeTransport` and stub transports: 338 tests, 1824 assertions on PHP 8.5 with the dependencies installed here (CI runs PHP 8.3, 8.4 and 8.5 × Laravel 12 and 13) | Run on every test run |
| Peppol validation through the manager | Opt-in group `peppol-rules`: installs the official rules from a local copy and sends the Peppol base example through a validating transport | Run before releases |
| Recommand driver | Core offline tests with fixtures written from Recommand's published API description; core live group `recommand-live`; bridge live smoke run `RecommandSmokeTest`, which sends an invoice through `EInvoicing::send()` | **Live-tested on the Peppol test network** (2026-11-15: core run and bridge run) |
| B2Brouter and e-invoice.be drivers | Core offline contract tests with fixtures written from each provider's public API reference, core live groups `b2brouter-live` and `einvoicebe-live`; manager, config, doctor and webhook routing tested here | **Live-tested against the provider sandbox (simulated network)** (2026-11-15, core) |
| Webhook signatures | Offline tests against each provider's documented scheme (e-invoice.be also against its published test vector) | Verified offline; **webhooks were not live-tested** (needs a public HTTPS endpoint) |

The core requires `brick/math` ^0.14.8, which Laravel 13 also accepts.

The run results, with dates and commits, are in the core's
[VERIFICATION.md](https://github.com/intragile/php-einvoicing/blob/main/docs/VERIFICATION.md). Webhook signatures are verified offline against each provider's documented scheme (e-invoice.be also against its published test vector); webhooks were not live-tested.

The default test run of this package makes no network call; the live smoke run is excluded from
it (group `recommand-live`, testsuite `Live`) and refuses unless the key belongs to a Recommand
playground on the Peppol test network. The core has opt-in live groups:
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
```

## License

MIT. See [LICENSE](LICENSE).

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
