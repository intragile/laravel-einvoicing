<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\France\FranceManager;
use IntrAgile\EInvoicing\Laravel\Jobs\SendDocument;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Support\ConfigFlag;
use IntrAgile\EInvoicing\Laravel\Support\ConfigValue;
use IntrAgile\EInvoicing\Model\Invoice;
use IntrAgile\EInvoicing\Transport\B2Brouter\B2BrouterConfig;
use IntrAgile\EInvoicing\Transport\B2Brouter\B2BrouterTransport;
use IntrAgile\EInvoicing\Transport\B2Brouter\B2BrouterWebhook;
use IntrAgile\EInvoicing\Transport\B2Brouter\France\B2BrouterAnnuaire;
use IntrAgile\EInvoicing\Transport\B2Brouter\France\B2BrouterFrance;
use IntrAgile\EInvoicing\Transport\EInvoiceBe\EInvoiceBeConfig;
use IntrAgile\EInvoicing\Transport\EInvoiceBe\EInvoiceBeTransport;
use IntrAgile\EInvoicing\Transport\EInvoiceBe\EInvoiceBeWebhook;
use IntrAgile\EInvoicing\Transport\FakeTransport;
use IntrAgile\EInvoicing\Transport\Http\ApiKey;
use IntrAgile\EInvoicing\Transport\Http\HttpClient;
use IntrAgile\EInvoicing\Transport\InboundSource;
use IntrAgile\EInvoicing\Transport\OutboundDocument;
use IntrAgile\EInvoicing\Transport\Recommand\RecommandConfig;
use IntrAgile\EInvoicing\Transport\Recommand\RecommandTransport;
use IntrAgile\EInvoicing\Transport\Recommand\RecommandWebhook;
use IntrAgile\EInvoicing\Transport\Transport;
use IntrAgile\EInvoicing\Transport\ValidatingTransport;
use IntrAgile\EInvoicing\Transport\Webhook\WebhookParser;
use IntrAgile\EInvoicing\Validation\DocumentValidator;
use IntrAgile\EInvoicing\Validation\Exception\RulesNotInstalled;
use IntrAgile\EInvoicing\Validation\FacturX\FacturXValidator;
use IntrAgile\EInvoicing\Validation\PeppolPayloadValidator;
use IntrAgile\EInvoicing\Validation\XRechnung\XRechnungValidator;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Builds the transports named in the einvoicing configuration, with the built-in Recommand,
 * B2Brouter and e-invoice.be drivers or with drivers registered with extend(), and their webhook
 * parsers.
 *
 * Transports are built on first use and cached per name. When validation is enabled, every
 * transport, custom drivers included, is wrapped in a ValidatingTransport with the document
 * validator: a DocumentValidator bound in the container, or else the Peppol payload validator,
 * which checks Peppol BIS documents with the installed Peppol rules and XRechnung documents with
 * the KoSIT rules. A missing rule set surfaces when a transport is requested, never at boot. The cache keeps the
 * validation setting a transport was built with: changing einvoicing.validation.enabled at run
 * time affects only transports built afterwards, so a long-running worker needs a restart (or
 * extend(), which empties the cache) to pick it up. The application
 * is held inside a closure, so the configuration and its secrets stay out of var_export(),
 * print_r() and var_dump() output of the manager.
 */
final class EInvoicingManager
{
    /** The drivers that come with the package. */
    public const BUILT_IN_DRIVERS = ['recommand', 'b2brouter', 'einvoicebe'];

    /**
     * Settings each built-in driver cannot be built without.
     *
     * @var array<string, list<string>>
     */
    public const REQUIRED_KEYS = [
        'recommand' => ['api_key_id', 'secret', 'company_id'],
        'b2brouter' => ['api_key', 'account_id'],
        'einvoicebe' => ['api_key'],
    ];

    /**
     * The setting of each built-in driver that names the provider account webhook events are
     * matched against.
     *
     * @var array<string, string>
     */
    public const ACCOUNT_KEYS = [
        'recommand' => 'company_id',
        'b2brouter' => 'account_id',
        'einvoicebe' => 'tenant_id',
    ];

    /** Longest webhook timestamp tolerance accepted, one year in seconds. */
    private const MAX_WEBHOOK_TOLERANCE = 31_536_000;

    /** @var Closure(): Application */
    private readonly Closure $app;

    /** @var array<string, Closure> registered factories; their return type is checked when called */
    private array $drivers = [];

    /** @var array<string, Closure> registered webhook parser factories; their return type is checked when called */
    private array $webhookParsers = [];

    /** @var array<string, Transport> unwrapped drivers by transport name */
    private array $built = [];

    /** @var array<string, Transport> transports as returned by transport(), by name */
    private array $transports = [];

    private ?FakeTransport $fake = null;

    private ?PeppolPayloadValidator $peppolValidator = null;

    public function __construct(Application $app)
    {
        $this->app = static fn (): Application => $app;
    }

    /**
     * The transport with this name, or the default one. It validates every document before
     * sending when validation is enabled, unless $validated is false: then it is the bare driver,
     * for a caller that has validated the document itself.
     *
     * @throws ConfigurationException when the transport is not configured or its configuration is invalid
     * @throws RulesNotInstalled when the document is validated and the Peppol rules are not installed
     */
    public function transport(?string $name = null, bool $validated = true): Transport
    {
        if ($this->fake !== null) {
            return $this->fake;
        }
        $name ??= $this->defaultName();

        if (! $validated) {
            return $this->driver($name);
        }

        if (! isset($this->transports[$name])) {
            $driver = $this->driver($name);
            $validator = $this->validator();
            $this->transports[$name] = $validator !== null ? new ValidatingTransport($driver, $validator) : $driver;
        }

        return $this->transports[$name];
    }

    /**
     * The validator documents are checked with before sending, or null when validation is
     * disabled or the transports are faked. A DocumentValidator bound in the container replaces
     * the default one, the Peppol payload validator of the installed rules.
     *
     * @throws RulesNotInstalled when the default validator is used and the Peppol rules are not installed
     */
    public function validator(): ?DocumentValidator
    {
        if ($this->fake !== null || ! $this->validationEnabled()) {
            return null;
        }

        $app = ($this->app)();
        if ($app->bound(DocumentValidator::class)) {
            return $app->make(DocumentValidator::class);
        }

        return $this->peppolValidator ??= PeppolPayloadValidator::create($this->rulesDir());
    }

    /**
     * The XRechnung validator of the KoSIT rules in intragile/einvoicing-rules-xrechnung.
     *
     * @throws RulesNotInstalled when that package is not installed
     */
    public function xrechnung(): XRechnungValidator
    {
        return XRechnungValidator::create();
    }

    /**
     * The Factur-X validator of the rules in intragile/einvoicing-rules-facturx.
     *
     * @throws RulesNotInstalled when that package is not installed
     */
    public function facturx(): FacturXValidator
    {
        return FacturXValidator::create();
    }

    /**
     * The French flow client (sending as plateforme agréée, lifecycle statuses, buyer responses,
     * payments and e-reporting) of the B2Brouter transport with this name, or of the one in
     * einvoicing.france.transport. It is never faked.
     *
     * @throws ConfigurationException when the transport is not set, not configured or not B2Brouter,
     *                                or a setting is invalid
     */
    public function france(?string $transport = null): B2BrouterFrance
    {
        return $this->franceManager()->france($transport);
    }

    /**
     * The live French directory (Annuaire) of the B2Brouter transport with this name, or of the
     * one in einvoicing.france.transport. It is never faked.
     *
     * @throws ConfigurationException when the transport is not set, not configured or not B2Brouter,
     *                                or a setting is invalid
     */
    public function annuaire(?string $transport = null): B2BrouterAnnuaire
    {
        return $this->franceManager()->annuaire($transport);
    }

    /**
     * Stores the document as queued and dispatches the job that sends it once the open database
     * transaction, if any, commits. The returned message reflects the row as stored here; on the
     * sync queue connection the send has already run, so refresh() it to see the outcome.
     *
     * @param  OutboundDocument|Invoice|string  $document  a routed document, an invoice written as UBL, or UBL or CII XML
     * @param  string|null  $reference  the application's own reference, e.g. the invoice number
     * @param  string|null  $transport  the transport name; the default one when null
     *
     * @throws UnsafeXml when the XML carries a DOCTYPE declaration
     * @throws UnsupportedDocument when the document is not a sendable Peppol BIS or XRechnung document (an invoice
     *                             needs the process control of one, see OutboundDocument::fromXml()) or lacks an
     *                             electronic address
     * @throws InvalidArgumentException when the invoice cannot be written or an identifier is malformed
     * @throws ConfigurationException when the transport is not configured or a numeric job setting is invalid
     */
    public function send(OutboundDocument|Invoice|string $document, ?string $reference = null, ?string $transport = null): OutboundMessage
    {
        $document = match (true) {
            is_string($document) => OutboundDocument::fromXml($document),
            $document instanceof Invoice => OutboundDocument::fromInvoice($document),
            default => $document,
        };
        $transport ??= $this->defaultName();
        $this->transportConfig($transport);
        // Before the row is written, so an invalid setting leaves no queued row without a job.
        SendDocument::assertSettings();

        $message = OutboundMessage::fromDocument($document, $transport, $reference);
        $message->state = OutboundState::Queued;
        $message->save();

        SendDocument::schedule($message->id);

        return $message;
    }

    /**
     * The inbound source of the transport with this name, or the default one. Receiving needs no
     * validation rules.
     *
     * @throws ConfigurationException when the transport is not configured or cannot receive documents
     */
    public function inbound(?string $name = null): InboundSource
    {
        if ($this->fake !== null) {
            return $this->fake;
        }
        $name ??= $this->defaultName();

        $driver = $this->driver($name);
        if (! $driver instanceof InboundSource) {
            throw new ConfigurationException(sprintf(
                'The transport configured in einvoicing.transports.%s cannot receive documents.',
                $name,
            ));
        }

        return $driver;
    }

    /**
     * The webhook parser of the transport with this name: the one of its built-in driver, or the
     * one registered for its driver with extendWebhook(). Every driver needs a webhook secret. It
     * reads the configuration even while the transports are faked, so signatures are checked as
     * in production.
     *
     * @throws ConfigurationException when the transport has no webhook parser or no webhook
     *                                secret, or a webhook setting is invalid
     */
    public function webhook(string $name): WebhookParser
    {
        $config = $this->transportConfig($name);
        $driver = $config['driver'] ?? null;
        $factory = is_string($driver) ? ($this->webhookParsers[$driver] ?? null) : null;
        if ($factory === null && ! in_array($driver, self::BUILT_IN_DRIVERS, true)) {
            throw new ConfigurationException(sprintf(
                'Webhooks need a built-in driver (%s) or a parser registered with extendWebhook() for einvoicing.transports.%s.driver.',
                implode(', ', self::BUILT_IN_DRIVERS),
                $name,
            ));
        }
        $secret = $this->requiredString($config, $name, 'webhook_secret');

        if ($factory !== null) {
            return $this->customWebhook($name, $factory, $config);
        }

        try {
            return match ($driver) {
                'b2brouter' => new B2BrouterWebhook($secret, $this->webhookTolerance($config, $name)),
                'einvoicebe' => new EInvoiceBeWebhook($secret),
                default => new RecommandWebhook($secret),
            };
        } catch (InvalidArgumentException) {
            // The settings were checked above; the core message is not repeated in any case.
            throw new ConfigurationException(sprintf('einvoicing.transports.%s.webhook_secret is invalid.', $name));
        }
    }

    /**
     * The provider account configured for the transport with this name, which webhook events
     * are matched against: the Recommand company id, the B2Brouter account id or the e-invoice.be
     * tenant id, without surrounding whitespace. Null when the setting is absent, null or the empty
     * string (an unset `KEY=` line in `.env`), when
     * the transport is not configured, or when the driver is not built in.
     *
     * @throws ConfigurationException when the setting is given but is no valid account id, such
     *                                as a blank string or a list; webhook events naming an account
     *                                are then ignored rather than accepted from any account
     */
    public function accountId(string $name): ?string
    {
        try {
            $config = $this->transportConfig($name);
        } catch (ConfigurationException) {
            return null;
        }

        $driver = $config['driver'] ?? null;
        $key = is_string($driver) ? (self::ACCOUNT_KEYS[$driver] ?? null) : null;
        if ($key === null) {
            return null;
        }
        $value = $config[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        $account = match ($driver) {
            'b2brouter' => $this->b2brouterAccount($value),
            default => is_string($value) || is_int($value) ? self::nonBlank(trim((string) $value)) : null,
        };

        return $account ?? throw new ConfigurationException(sprintf(
            'einvoicing.transports.%s.%s is not a valid account id; webhook events are ignored.',
            $name,
            $key,
        ));
    }

    /**
     * The core configuration of a transport with a built-in driver, checked as when the transport
     * is built, but without building it or making a request; null for a custom driver.
     *
     * @internal used by einvoicing:doctor
     *
     * @throws ConfigurationException when a setting is missing or invalid, naming its key and never its value
     */
    public function driverConfig(string $name): RecommandConfig|B2BrouterConfig|EInvoiceBeConfig|null
    {
        $config = $this->transportConfig($name);

        return match ($config['driver'] ?? null) {
            'recommand' => $this->recommandConfig($name, $config),
            'b2brouter' => $this->b2brouterConfig($name, $config),
            'einvoicebe' => $this->einvoicebeConfig($name, $config),
            default => null,
        };
    }

    /**
     * Registers a driver. The factory receives the application and the transport's configuration
     * array and returns the unvalidated transport; validation is added by the manager. Mark the
     * factory's configuration parameter #[\SensitiveParameter] so its credentials stay out of
     * stack traces.
     *
     * @param  Closure(Application, array<string, mixed>): Transport  $factory
     */
    public function extend(string $driver, Closure $factory): void
    {
        $this->drivers[$driver] = $factory;
        $this->built = [];
        $this->transports = [];
    }

    /**
     * Registers the webhook parser of a driver. The factory receives the application and the
     * transport's configuration array, whose webhook_secret is set, and returns the parser. Mark
     * the factory's configuration parameter #[\SensitiveParameter] so its secrets stay out of
     * stack traces. A parser registered for a built-in driver replaces the built-in one.
     *
     * @param  Closure(Application, array<string, mixed>): WebhookParser  $factory
     */
    public function extendWebhook(string $driver, Closure $factory): void
    {
        $this->webhookParsers[$driver] = $factory;
    }

    /**
     * Whether a transport can be built with this driver: a built-in one or a registered one.
     */
    public function hasDriver(string $driver): bool
    {
        return in_array($driver, self::BUILT_IN_DRIVERS, true) || isset($this->drivers[$driver]);
    }

    /**
     * Replaces every transport with one in-memory fake, without validation, and returns it.
     */
    public function fake(): FakeTransport
    {
        return $this->fake = new FakeTransport;
    }

    /**
     * @throws ConfigurationException when einvoicing.default is not a transport name
     */
    public function defaultName(): string
    {
        $name = $this->config()->get('einvoicing.default');
        if (! is_string($name) || trim($name) === '') {
            throw new ConfigurationException('einvoicing.default must name a transport.');
        }

        return $name;
    }

    /**
     * The names of the configured transports.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $transports = $this->config()->get('einvoicing.transports');

        return is_array($transports) ? array_map(strval(...), array_keys($transports)) : [];
    }

    /**
     * The folder holding the installed Peppol rules.
     */
    public function rulesDir(): string
    {
        $dir = $this->config()->get('einvoicing.validation.rules_dir');

        return is_string($dir) && trim($dir) !== ''
            ? $dir
            : ($this->app)()->storagePath('einvoicing/peppol-rules');
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'transports' => $this->names(),
            'built' => array_keys($this->transports),
            'customDrivers' => array_keys($this->drivers),
            'customWebhookParsers' => array_keys($this->webhookParsers),
            'faked' => $this->fake !== null,
        ];
    }

    /**
     * The unvalidated transport with this name.
     *
     * @throws ConfigurationException
     */
    private function driver(string $name): Transport
    {
        if (isset($this->built[$name])) {
            return $this->built[$name];
        }

        $config = $this->transportConfig($name);
        $driver = $config['driver'] ?? null;

        if (is_string($driver) && isset($this->drivers[$driver])) {
            $transport = $this->custom($name, $this->drivers[$driver], $config);
        } else {
            $transport = match ($driver) {
                'recommand' => new RecommandTransport($this->recommandConfig($name, $config), $this->httpClient()),
                'b2brouter' => new B2BrouterTransport($this->b2brouterConfig($name, $config), $this->httpClient()),
                'einvoicebe' => new EInvoiceBeTransport($this->einvoicebeConfig($name, $config), $this->httpClient()),
                default => throw new ConfigurationException(sprintf(
                    'einvoicing.transports.%s.driver names no known driver; use "%s" or register one with extend().',
                    $name,
                    implode('", "', self::BUILT_IN_DRIVERS),
                )),
            };
        }

        return $this->built[$name] = $transport;
    }

    /**
     * Builds a transport with a registered driver factory, whose return value is checked.
     *
     * @param  Closure(Application, array<string, mixed>): mixed  $factory
     * @param  array<string, mixed>  $config
     *
     * @throws ConfigurationException
     */
    private function custom(string $name, Closure $factory, #[SensitiveParameter] array $config): Transport
    {
        $transport = $factory(($this->app)(), $config);
        if (! $transport instanceof Transport) {
            throw new ConfigurationException(sprintf(
                'The factory of the driver in einvoicing.transports.%s.driver must return a %s.',
                $name,
                Transport::class,
            ));
        }

        return $transport;
    }

    /**
     * Builds a webhook parser with a registered factory, whose return value is checked.
     *
     * @param  Closure(Application, array<string, mixed>): mixed  $factory
     * @param  array<string, mixed>  $config
     *
     * @throws ConfigurationException
     */
    private function customWebhook(string $name, Closure $factory, #[SensitiveParameter] array $config): WebhookParser
    {
        $parser = $factory(($this->app)(), $config);
        if (! $parser instanceof WebhookParser) {
            throw new ConfigurationException(sprintf(
                'The webhook parser factory of the driver in einvoicing.transports.%s.driver must return a %s.',
                $name,
                WebhookParser::class,
            ));
        }

        return $parser;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ConfigurationException
     */
    private function recommandConfig(string $name, #[SensitiveParameter] array $config): RecommandConfig
    {
        $apiKeyId = $this->credential($config, $name, 'api_key_id');
        $secret = $this->credential($config, $name, 'secret');
        $companyId = $this->requiredString($config, $name, 'company_id');
        $baseUrl = $this->baseUrl($config, $name, RecommandConfig::DEFAULT_BASE_URL);

        try {
            return new RecommandConfig($apiKeyId, $secret, $companyId, $baseUrl);
        } catch (InvalidArgumentException) {
            // The other settings were checked above. The core message repeats the URL, which may
            // carry credentials; name the key only.
            throw self::invalidBaseUrl($name);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ConfigurationException
     */
    private function b2brouterConfig(string $name, #[SensitiveParameter] array $config): B2BrouterConfig
    {
        $apiKey = $this->credential($config, $name, 'api_key');
        $accountId = $config['account_id'] ?? null;
        if ($accountId === null || (is_string($accountId) && trim($accountId) === '')) {
            throw self::missing($name, 'account_id');
        }
        $accountId = self::accountNumber($accountId) ?? throw new ConfigurationException(sprintf(
            'einvoicing.transports.%s.account_id must be the B2Brouter account id, a positive whole number.',
            $name,
        ));
        $baseUrl = $this->baseUrl($config, $name, B2BrouterConfig::DEFAULT_BASE_URL);
        $codes = $config['document_type_codes'] ?? [];
        if (! is_array($codes)) {
            throw self::invalidDocumentTypeCodes($name);
        }
        $typed = [];
        foreach ($codes as $documentType => $code) {
            if (! is_string($documentType) || ! is_string($code)) {
                throw self::invalidDocumentTypeCodes($name);
            }
            $typed[$documentType] = $code;
        }

        // Built without the codes first, so a refusal is attributed to the right setting.
        try {
            $b2brouterConfig = new B2BrouterConfig($apiKey, $accountId, $baseUrl);
        } catch (InvalidArgumentException) {
            throw self::invalidBaseUrl($name);
        }
        if ($typed === []) {
            return $b2brouterConfig;
        }

        try {
            return new B2BrouterConfig($apiKey, $accountId, $baseUrl, $typed);
        } catch (InvalidArgumentException) {
            throw self::invalidDocumentTypeCodes($name);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ConfigurationException
     */
    private function einvoicebeConfig(string $name, #[SensitiveParameter] array $config): EInvoiceBeConfig
    {
        $apiKey = $this->credential($config, $name, 'api_key');
        $baseUrl = $this->baseUrl($config, $name, EInvoiceBeConfig::DEFAULT_BASE_URL);
        $lookbackDays = ConfigValue::integer($config['inbound_lookback_days'] ?? 7, "einvoicing.transports.{$name}.inbound_lookback_days", 1, 365);
        $maxPages = ConfigValue::integer($config['inbound_max_pages'] ?? 10, "einvoicing.transports.{$name}.inbound_max_pages", 1, 100);

        try {
            return new EInvoiceBeConfig($apiKey, $baseUrl, $lookbackDays, $maxPages);
        } catch (InvalidArgumentException) {
            throw self::invalidBaseUrl($name);
        }
    }

    /**
     * The HTTP client every built-in driver sends through. It never follows a redirect: the
     * drivers follow the ones they trust themselves. It carries no secrets; each transport adds
     * its own.
     *
     * @internal used by the French flow clients
     *
     * @throws ConfigurationException when an einvoicing.http setting is invalid
     */
    public function httpClient(): HttpClient
    {
        $factory = new HttpFactory;
        $client = new Client([
            'timeout' => $this->number('einvoicing.http.timeout', 60),
            'connect_timeout' => $this->number('einvoicing.http.connect_timeout', 10),
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
        $maxBytes = $this->config()->get('einvoicing.http.max_response_bytes') ?? 20_000_000;
        $maxBytes = ConfigValue::number($maxBytes, 'einvoicing.http.max_response_bytes');
        if (! is_int($maxBytes) || $maxBytes < 1) {
            throw new ConfigurationException('einvoicing.http.max_response_bytes must be a positive integer.');
        }

        return new HttpClient($client, $factory, $factory, $maxBytes);
    }

    /**
     * The webhook timestamp tolerance in seconds, or null when none is set.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws ConfigurationException when it is not a whole number of seconds within a year
     */
    private function webhookTolerance(array $config, string $name): ?int
    {
        $tolerance = $config['webhook_tolerance'] ?? null;
        if ($tolerance === null || $tolerance === '') {
            return null;
        }

        return ConfigValue::integer($tolerance, "einvoicing.transports.{$name}.webhook_tolerance", 1, self::MAX_WEBHOOK_TOLERANCE);
    }

    /**
     * The configured B2Brouter account id in the form webhook events carry it, or null.
     */
    private function b2brouterAccount(mixed $accountId): ?string
    {
        $number = self::accountNumber($accountId);

        return $number === null ? null : (string) $number;
    }

    /**
     * A positive whole number, given as a number or a numeric string, or null.
     */
    private static function accountNumber(mixed $value): ?int
    {
        try {
            return ConfigValue::integer($value, 'account_id', 1, PHP_INT_MAX);
        } catch (ConfigurationException) {
            return null;
        }
    }

    /**
     * The base URL, or the driver's default when it is not set.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws ConfigurationException when it is set but not a string
     */
    private function baseUrl(#[SensitiveParameter] array $config, string $name, string $default): string
    {
        $baseUrl = $config['base_url'] ?? null;
        if ($baseUrl === null || $baseUrl === '') {
            return $default;
        }
        if (! is_string($baseUrl)) {
            throw self::invalidBaseUrl($name);
        }

        return $baseUrl;
    }

    /**
     * @return array<string, mixed>
     *
     * The name is looked up as an exact key of einvoicing.transports, never as a dotted path, so
     * a name taken from a request cannot reach a nested configuration value.
     *
     * @throws ConfigurationException when no transport with this name is configured
     */
    private function transportConfig(string $name): array
    {
        $transports = $this->config()->get('einvoicing.transports');
        $config = is_array($transports) && array_key_exists($name, $transports) ? $transports[$name] : null;
        if (! is_array($config)) {
            throw new ConfigurationException(sprintf('No transport is configured in einvoicing.transports.%s.', $name));
        }

        /** @var array<string, mixed> $config */
        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ConfigurationException when the value is missing, not a string or blank
     */
    private function requiredString(#[SensitiveParameter] array $config, string $name, string $key): string
    {
        $value = $config[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw self::missing($name, $key);
        }

        return $value;
    }

    /**
     * A credential of a built-in driver, required and at least as long as the drivers accept.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws ConfigurationException when the value is missing, blank or too short; never quoting it
     */
    private function credential(#[SensitiveParameter] array $config, string $name, string $key): string
    {
        $value = $this->requiredString($config, $name, $key);
        if (strlen(trim($value)) < ApiKey::MIN_LENGTH) {
            throw new ConfigurationException(sprintf(
                'einvoicing.transports.%s.%s must be at least %d characters long.',
                $name,
                $key,
                ApiKey::MIN_LENGTH,
            ));
        }

        return $value;
    }

    /**
     * A positive number of seconds; null uses the default.
     *
     * @throws ConfigurationException when the value is not a positive number or numeric string
     */
    private function number(string $key, int|float $default): int|float
    {
        $value = ConfigValue::number($this->config()->get($key) ?? $default, $key);
        if ($value <= 0) {
            throw new ConfigurationException(sprintf('%s must be a positive number of seconds.', $key));
        }

        return $value;
    }

    private function validationEnabled(): bool
    {
        // A value that cannot be read as a flag keeps validation on; einvoicing:doctor warns about it.
        return ConfigFlag::enabled('validation.enabled');
    }

    private function franceManager(): FranceManager
    {
        return ($this->app)()->make(FranceManager::class);
    }

    private function config(): Repository
    {
        return ($this->app)()->make(Repository::class);
    }

    private static function nonBlank(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function missing(string $name, string $key): ConfigurationException
    {
        return new ConfigurationException(sprintf('einvoicing.transports.%s.%s is missing or empty.', $name, $key));
    }

    private static function invalidDocumentTypeCodes(string $name): ConfigurationException
    {
        return new ConfigurationException(sprintf(
            'einvoicing.transports.%s.document_type_codes must map Peppol document type identifiers to B2Brouter document type codes of 3 to 100 characters of a-z, 0-9, "." and "_".',
            $name,
        ));
    }

    private static function invalidBaseUrl(string $name): ConfigurationException
    {
        return new ConfigurationException(sprintf(
            'einvoicing.transports.%s.base_url must be an https:// URL without user info, query or fragment.',
            $name,
        ));
    }
}
