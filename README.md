# Laravel E-Invoicing

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

[![Tests](https://github.com/intragile/laravel-einvoicing/actions/workflows/tests.yml/badge.svg)](https://github.com/intragile/laravel-einvoicing/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/intragile/laravel-einvoicing.svg)](https://packagist.org/packages/intragile/laravel-einvoicing)
[![PHP](https://img.shields.io/packagist/php-v/intragile/laravel-einvoicing.svg)](https://packagist.org/packages/intragile/laravel-einvoicing)
[![License](https://img.shields.io/packagist/l/intragile/laravel-einvoicing.svg)](LICENSE)

The Laravel bridge for [`intragile/php-einvoicing`](https://github.com/intragile/php-einvoicing):
send and receive EN 16931 invoices over **Peppol** from a Laravel application.

[Magyar nyelvű leírás → README.hu.md](README.hu.md)

- **Configured transports.** Named connections in `config/einvoicing.php`; the built-in
  `recommand` driver talks to the [Recommand](https://recommand.eu) access point, and
  `EInvoicing::extend()` adds your own. Recommand is the available driver; Storecove,
  e-invoice.be and B2Brouter are planned for 1.0.0.
- **Validation before every send**, on by default: Peppol BIS documents with the official rules,
  installed by one artisan command, and XRechnung documents with the KoSIT rules.
- **Queued sending with a guarded state machine.** A send is claimed with a conditional update,
  so a duplicate or repeated job never sends twice. A send whose outcome is unknown is
  **never resent automatically**: it waits for an operator.
- **Delivery status** by polling and by webhook, with events at every step.
- **Inbound documents** from webhooks or polling: fetched, stored, announced once, and only
  then acknowledged at the access point.
- **Signed webhooks** verified on the raw request body, without the `web` middleware group.
- `EInvoicing::fake()` for your tests, and `einvoicing:doctor` to check an installation.

> **Live transmission through Recommand is not verified yet.** Everything is tested offline;
> see [What is verified](#what-is-verified).

## Requirements

- PHP 8.3+ with `dom`, `libxml`, `mbstring`
- Laravel 12 or 13
- A queue worker for production use (see [Queues](#queues))

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
>     { "type": "path", "url": "../php-einvoicing", "options": { "versions": { "intragile/php-einvoicing": "1.0.0" } } },
>     { "type": "path", "url": "../php-einvoicing/packages/rules-en16931", "options": { "versions": { "intragile/einvoicing-rules-en16931": "1.0.0" } } },
>     { "type": "path", "url": "../php-einvoicing/packages/rules-xrechnung", "options": { "versions": { "intragile/einvoicing-rules-xrechnung": "1.0.0" } } },
>     { "type": "path", "url": "../php-einvoicing/packages/rules-facturx", "options": { "versions": { "intragile/einvoicing-rules-facturx": "1.0.0" } } },
>     { "type": "path", "url": "../laravel-einvoicing", "options": { "versions": { "intragile/laravel-einvoicing": "1.0.0" } } }
> ]
> ```
>
> and require `"intragile/laravel-einvoicing": "^1.0"`. The `versions` option gives each path
> package the version the constraints between the five packages expect.

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

The rest of `config/einvoicing.php`: HTTP timeouts and the response size cap (`http`),
validation on or off (`validation.enabled`), send retries (`sending.max_attempts`,
`sending.backoff`), status polling (`status.poll`, `status.delays`, `status.max_attempts`),
inbound acknowledgement and the inbound attempt budget (`inbound.acknowledge`,
`inbound.max_fetch_attempts`) and the webhook route (`webhooks`). In every list of delays the
last value repeats. Numbers may be given as numeric strings, as environment variables deliver
them; anything else is refused with an error that names the key. `webhooks.enabled` also takes
the strings `"true"`/`"false"` and `"1"`/`"0"`. The Recommand API key id and secret must each be
at least 8 characters long.

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

Look the document up in the access point (for Recommand: the company's documents in the
dashboard or the API, same receiver and invoice number), then:

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
| `WebhookReceived` | a signed webhook for this company was verified, before it is applied (repeated deliveries fire it again) |

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
with the `recommand` driver and a `webhook_secret`; any other name gets `404`. Setup:

1. Run `php artisan einvoicing:doctor`. The `transport <name>: webhook` row prints the full URL
   when the transport has a webhook secret and webhooks are enabled; otherwise it shows a
   warning that says what is missing. A missing `company_id` fails the `config` row.
2. Register that URL as a webhook in Recommand and put its signing secret into
   `RECOMMAND_WEBHOOK_SECRET`.
3. Nothing else: the route does not run the `web` middleware group, so no CSRF token or
   session is involved, and you need no CSRF exception.

The signature (`X-Signature`, HMAC-SHA256) is checked on the raw body exactly as received.
Answers have an empty body: `401` for a missing or wrong signature, `404` for a transport
without a webhook secret, `204` for everything else, including notifications that are ignored
(another company, an unknown document, a non-document event), so Recommand does not retry
them. Repeated or out-of-order notifications are harmless: inbound documents are stored once
per id, and `delivered` and `failed` never change again.

**Harden the endpoint.** The route has no rate limit and no body size limit of its own. Add a
throttle in `einvoicing.webhooks.middleware`, for example `['throttle:120,1']`, and cap the
request body in your web server (`client_max_body_size` in nginx, `LimitRequestBody` in
Apache). The prefix is `einvoicing.webhooks.prefix`; `einvoicing.webhooks.enabled = false`
removes the route.

A delivery-status webhook that arrives before the send job has stored the access point's
document id finds no row and is dropped. On a real queue the status polls catch up; on a `sync`
connection nothing does, so check such rows by hand.

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
| State machine, send policy, retries, claim expiry, status polling, inbound intake, webhooks, commands | Offline feature tests with `FakeTransport` and stub transports: 231 tests, 1239 assertions on each of PHP 8.3, 8.4, 8.5 × Laravel 12, 13 | Run on every test run |
| Peppol validation through the manager | Opt-in group `peppol-rules`: installs the official rules from a local copy and sends the Peppol base example through a validating transport | Run before releases |
| Recommand driver | Core offline tests with fixtures written from Recommand's published API description | **Live transmission not verified** |

The core requires `brick/math` ^0.14.8, which Laravel 13 also accepts.

No test of this package makes a network call. The core has an opt-in `recommand-live` group
for a Recommand playground team; see its
[TRANSPORT.md](https://github.com/intragile/php-einvoicing/blob/main/docs/TRANSPORT.md).

## Versioning

The five packages, `intragile/php-einvoicing`, the rule packages
`intragile/einvoicing-rules-en16931`, `intragile/einvoicing-rules-xrechnung` and
`intragile/einvoicing-rules-facturx`, and `intragile/laravel-einvoicing`, are released together
with the same version number and follow Semantic Versioning. Use the same minor version of each.
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

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
