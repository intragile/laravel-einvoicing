<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default transport
    |--------------------------------------------------------------------------
    |
    | The name of the transport, from the list below, used when none is given.
    |
    */

    'default' => env('EINVOICING_TRANSPORT', 'recommand'),

    /*
    |--------------------------------------------------------------------------
    | Transports
    |--------------------------------------------------------------------------
    |
    | Named connections to a network. The built-in drivers talk to Peppol
    | access points: "recommand" (Recommand), "b2brouter" (B2Brouter) and
    | "einvoicebe" (e-invoice.be). Further drivers can be registered with
    | EInvoicing::extend(). A transport is built on first use, so missing
    | credentials never break application boot. Uncomment the transport of
    | each access point you use; einvoicing:doctor checks every transport
    | listed here.
    |
    */

    'transports' => [
        'recommand' => [
            'driver' => 'recommand',
            'api_key_id' => env('RECOMMAND_API_KEY_ID'),
            'secret' => env('RECOMMAND_API_SECRET'),
            'company_id' => env('RECOMMAND_COMPANY_ID'),
            'base_url' => env('RECOMMAND_BASE_URL', 'https://app.recommand.eu'),
            'webhook_secret' => env('RECOMMAND_WEBHOOK_SECRET'),
        ],

        // B2Brouter. A key starting with "test_" is routed to the B2Brouter
        // sandbox. document_type_codes maps further Peppol document type
        // identifiers to B2Brouter codes; BIS Billing 3.0 and XRechnung 3.0
        // documents need none. webhook_tolerance (seconds, or null) refuses
        // notifications whose signed timestamp is further off; B2Brouter
        // retries for about a week, so leave it null unless replays matter more.
        //
        // 'b2brouter' => [
        //     'driver' => 'b2brouter',
        //     'api_key' => env('B2BROUTER_API_KEY'),
        //     'account_id' => env('B2BROUTER_ACCOUNT_ID'),
        //     'base_url' => env('B2BROUTER_BASE_URL', 'https://api.b2brouter.net'),
        //     'document_type_codes' => [],
        //     'webhook_secret' => env('B2BROUTER_WEBHOOK_SECRET'),
        //     'webhook_tolerance' => null,
        // ],

        // e-invoice.be. It has no acknowledgement call: received documents
        // are listed for inbound_lookback_days (1 to 365), reading at most
        // inbound_max_pages pages of 100 (1 to 100) per poll, and stored once.
        // tenant_id, when set, drops webhook notifications of other tenants.
        //
        // 'einvoicebe' => [
        //     'driver' => 'einvoicebe',
        //     'api_key' => env('EINVOICEBE_API_KEY'),
        //     'base_url' => env('EINVOICEBE_BASE_URL', 'https://api.e-invoice.be'),
        //     'tenant_id' => env('EINVOICEBE_TENANT_ID'),
        //     'inbound_lookback_days' => 7,
        //     'inbound_max_pages' => 10,
        //     'webhook_secret' => env('EINVOICEBE_WEBHOOK_SECRET'),
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | French e-invoicing (B2Brouter)
    |--------------------------------------------------------------------------
    |
    | "transport" names a transport above with the "b2brouter" driver that
    | acts as the French plateforme agréée: EInvoicing::france() and
    | EInvoicing::annuaire() use it when no name is given. Only when it is set
    | is the live B2Brouter Annuaire bound to the core's Annuaire interface,
    | so France\Routing\Router can be resolved from the container. A cold
    | Annuaire lookup is repeated while B2Brouter is still resolving it:
    | annuaire_attempts requests at most (1 to 10), annuaire_retry_delay
    | seconds apart (0 to 30).
    |
    */

    'france' => [
        'transport' => env('EINVOICING_FRANCE_TRANSPORT'),
        'annuaire_attempts' => 4,
        'annuaire_retry_delay' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Polish KSeF
    |--------------------------------------------------------------------------
    |
    | The KSeF API 2.0 connection used by EInvoicing::ksef() and
    | EInvoicing::inbound('ksef'). "environment" is "test" (TE), "demo" or
    | "production"; production is never chosen unless it is set here. "nip"
    | is the context the client authenticates for.
    |
    | "credentials" holds either a certificate (a qualified seal or signature,
    | or a KSeF authentication certificate) or a KSeF token, not both. The
    | certificate and its private key are read from the files at the given
    | paths, never from environment values; the passphrase of an encrypted key
    | may come from the environment. "offline_certificate" is the KSeF Offline
    | certificate that signs the KOD II verification link of invoices issued
    | in offline mode; leave its paths empty when offline invoicing is unused.
    |
    | max_response_bytes caps every KSeF answer read into memory; it must be
    | at least 52,428,816 bytes, the largest encrypted export part KSeF sends.
    | inbound_min_interval_minutes is the shortest interval between two KSeF
    | inbox polls, as KSeF allows 20 metadata queries per hour. Offline
    | invoices whose delivery deadline is within deadline_warning_hours are
    | reported as approaching.
    |
    */

    'ksef' => [
        'environment' => env('KSEF_ENVIRONMENT', 'test'),
        'nip' => env('KSEF_NIP'),
        'credentials' => [
            'certificate' => env('KSEF_CERTIFICATE_PATH'),
            'private_key' => env('KSEF_PRIVATE_KEY_PATH'),
            'passphrase' => env('KSEF_PRIVATE_KEY_PASSPHRASE'),
            'token' => env('KSEF_TOKEN'),
        ],
        'offline_certificate' => [
            'certificate' => env('KSEF_OFFLINE_CERTIFICATE_PATH'),
            'private_key' => env('KSEF_OFFLINE_PRIVATE_KEY_PATH'),
            'passphrase' => env('KSEF_OFFLINE_PRIVATE_KEY_PASSPHRASE'),
        ],
        'max_response_bytes' => 67_108_864,
        'inbound_min_interval_minutes' => 15,
        'deadline_warning_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    |
    | Timeouts in seconds and the largest response body read into memory.
    | Redirects are never followed.
    |
    */

    'http' => [
        'timeout' => 60,
        'connect_timeout' => 10,
        'max_response_bytes' => 20_000_000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    |
    | When enabled, every document is validated before it is sent: a Peppol BIS
    | Billing document against the Peppol rules, an XRechnung document against
    | the KoSIT rules of intragile/einvoicing-rules-xrechnung. The Peppol rules
    | are installed with "php artisan einvoicing:install-rules" into this
    | folder; null means storage/einvoicing/peppol-rules.
    |
    */

    'validation' => [
        'enabled' => true,
        'rules_dir' => env('EINVOICING_RULES_DIR'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The connection and queue for sending, status polling and inbound jobs;
    | null uses the application defaults.
    |
    */

    'queue' => [
        'connection' => env('EINVOICING_QUEUE_CONNECTION'),
        'name' => env('EINVOICING_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    |
    | Attempts for a send that failed in a way that is safe to retry, and the
    | delays in seconds between them (the last delay repeats).
    |
    */

    'sending' => [
        'max_attempts' => 5,
        'backoff' => [30, 120, 600, 1800],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery status
    |--------------------------------------------------------------------------
    |
    | Whether pending deliveries are polled for their status, the delays in
    | seconds between polls (the last delay repeats) and the number of polls.
    |
    */

    'status' => [
        'poll' => true,
        'delays' => [60, 300, 900, 3600, 21600],
        'max_attempts' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound documents
    |--------------------------------------------------------------------------
    |
    | Whether a received document is acknowledged at the access point once it
    | has been stored, and the total number of failed fetches and
    | acknowledgements (1 to 65535) after which a document is no longer tried
    | automatically; einvoicing:poll-inbound --include-capped retries it once.
    |
    */

    'inbound' => [
        'acknowledge' => true,
        'max_fetch_attempts' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | The route POST {prefix}/{transport} receives signed notifications for
    | transports that have a webhook secret, with any built-in driver or a
    | driver whose parser is registered with EInvoicing::extendWebhook(). It
    | never runs the "web" middleware group, so no session or CSRF token is
    | involved. "enabled" also accepts the strings "true"/"false" and "1"/"0"
    | from the environment.
    |
    */

    'webhooks' => [
        'enabled' => true,
        'prefix' => 'einvoicing/webhooks',
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Spain: VERI*FACTU
    |--------------------------------------------------------------------------
    |
    | Billing records for the Spanish tax authority (AEAT), kept in one hash
    | chain per issuer, installation and environment. "environment" is "test"
    | (pre-production) or "production"; production is never chosen unless it is
    | set here. "driver" is "aeat" (direct submission with the issuer's
    | certificate) or "b2brouter".
    |
    | "system" declares the billing software that produces the records
    | (SistemaInformatico). Every value is required for the "aeat" driver and
    | none has a default: the producer of the installation declares itself.
    | While every issuer uses "b2brouter", which writes its own, it may stay
    | empty. Give either "nif" or "foreign_id" (type, id, country), not both.
    | Nothing here makes the library a certified billing system.
    |
    | "issuers" maps each obligated issuer's NIF to its "name" and, optionally,
    | its own "driver", "representative" (name, nif) and "aeat" override of the
    | credentials below. VERIFACTU_ISSUER_NIF and VERIFACTU_ISSUER_NAME fill a
    | single issuer. "aeat.certificate" is a PKCS#12 file, or a PEM file with
    | "private_key"; "port" is "personal" or "seal" and picks the AEAT host.
    | "ca_bundle" null uses the CA bundle of the OpenSSL extension. "timeout"
    | and "connect_timeout" are in seconds.
    |
    | "schemas_dir" is where einvoicing:verifactu-install-schemas puts the AEAT
    | schemas (null: storage/einvoicing/verifactu).
    |
    | "submission" shapes the queue and the send throttle: "max_batch" (1 to
    | 1000) records per request, "initial_wait" seconds between sends until the
    | AEAT answers with its own wait, and "throttle_scope" ("installation" or
    | "issuer"). "cross_check" is "strict" or "report" for B2Brouter results;
    | records not settled after "stuck_after_minutes" are reported as stuck
    | (at least the submission job's timeout, "aeat.timeout" + 60 s, in minutes
    | plus one).
    |
    */

    'verifactu' => [
        'environment' => env('VERIFACTU_ENVIRONMENT', 'test'),
        'driver' => env('VERIFACTU_DRIVER', 'aeat'),
        'timezone' => 'Europe/Madrid',
        'schemas_dir' => env('VERIFACTU_SCHEMAS_DIR'),
        'system' => [
            'name' => env('VERIFACTU_PRODUCER_NAME'),
            'nif' => env('VERIFACTU_PRODUCER_NIF'),
            'foreign_id' => [
                'type' => null,
                'id' => null,
                'country' => null,
            ],
            'system_name' => env('VERIFACTU_SYSTEM_NAME'),
            'system_id' => env('VERIFACTU_SYSTEM_ID'),
            'version' => env('VERIFACTU_SYSTEM_VERSION'),
            'installation_number' => env('VERIFACTU_INSTALLATION_NUMBER'),
            'verifactu_only' => env('VERIFACTU_SYSTEM_VERIFACTU_ONLY'),
            'multiple_taxpayers_possible' => env('VERIFACTU_SYSTEM_MULTIPLE_TAXPAYERS_POSSIBLE'),
            'multiple_taxpayers_indicator' => env('VERIFACTU_SYSTEM_MULTIPLE_TAXPAYERS_INDICATOR'),
        ],
        'issuers' => env('VERIFACTU_ISSUER_NIF') !== null && env('VERIFACTU_ISSUER_NIF') !== ''
            ? [(string) env('VERIFACTU_ISSUER_NIF') => ['name' => env('VERIFACTU_ISSUER_NAME')]]
            : [],
        'aeat' => [
            'certificate' => env('VERIFACTU_AEAT_CERTIFICATE'),
            'password' => env('VERIFACTU_AEAT_PASSWORD'),
            'private_key' => env('VERIFACTU_AEAT_PRIVATE_KEY'),
            'port' => 'personal',
            'ca_bundle' => env('VERIFACTU_CA_BUNDLE'),
            'timeout' => 30,
            'connect_timeout' => 10,
        ],
        'b2brouter' => [
            'transport' => env('VERIFACTU_B2BROUTER_TRANSPORT'),
            'poll_attempts' => 10,
            'poll_delay' => 30,
        ],
        'submission' => [
            'queue' => null,
            'connection' => null,
            'max_batch' => 1000,
            'initial_wait' => 60,
            'throttle_scope' => 'installation',
        ],
        'cross_check' => 'strict',
        'stuck_after_minutes' => 60,
    ],

];
