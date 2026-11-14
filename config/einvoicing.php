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
    | Named connections to a network. The built-in "recommand" driver talks to
    | the Recommand Peppol access point; further drivers can be registered with
    | EInvoicing::extend(). A transport is built on first use, so missing
    | credentials never break application boot.
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
    | transports that have a webhook secret. It never runs the "web"
    | middleware group, so no session or CSRF token is involved. "enabled"
    | also accepts the strings "true"/"false" and "1"/"0" from the environment.
    |
    */

    'webhooks' => [
        'enabled' => true,
        'prefix' => 'einvoicing/webhooks',
        'middleware' => [],
    ],

];
