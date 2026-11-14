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
use IntrAgile\EInvoicing\Laravel\Jobs\SendDocument;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Support\ConfigValue;
use IntrAgile\EInvoicing\Model\Invoice;
use IntrAgile\EInvoicing\Transport\FakeTransport;
use IntrAgile\EInvoicing\Transport\Http\HttpClient;
use IntrAgile\EInvoicing\Transport\InboundSource;
use IntrAgile\EInvoicing\Transport\OutboundDocument;
use IntrAgile\EInvoicing\Transport\Recommand\RecommandConfig;
use IntrAgile\EInvoicing\Transport\Recommand\RecommandTransport;
use IntrAgile\EInvoicing\Transport\Recommand\RecommandWebhook;
use IntrAgile\EInvoicing\Transport\Transport;
use IntrAgile\EInvoicing\Transport\ValidatingTransport;
use IntrAgile\EInvoicing\Validation\Exception\RulesNotInstalled;
use IntrAgile\EInvoicing\Validation\Validator;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Builds the transports named in the einvoicing configuration.
 *
 * Transports are built on first use and cached per name. When validation is enabled, every
 * transport, custom drivers included, is wrapped in a ValidatingTransport with the Peppol rules,
 * so a missing rule set surfaces when a transport is requested, never at boot. The cache keeps the
 * validation setting a transport was built with: changing einvoicing.validation.enabled at run
 * time affects only transports built afterwards, so a long-running worker needs a restart (or
 * extend(), which empties the cache) to pick it up. The application
 * is held inside a closure, so the configuration and its secrets stay out of var_export(),
 * print_r() and var_dump() output of the manager.
 */
final class EInvoicingManager
{
    private const BUILT_IN_DRIVER = 'recommand';

    /** @var Closure(): Application */
    private readonly Closure $app;

    /** @var array<string, Closure> registered factories; their return type is checked when called */
    private array $drivers = [];

    /** @var array<string, Transport> unwrapped drivers by transport name */
    private array $built = [];

    /** @var array<string, Transport> transports as returned by transport(), by name */
    private array $transports = [];

    private ?FakeTransport $fake = null;

    public function __construct(Application $app)
    {
        $this->app = static fn (): Application => $app;
    }

    /**
     * The transport with this name, or the default one, validated when validation is enabled.
     *
     * @throws ConfigurationException when the transport is not configured or its configuration is invalid
     * @throws RulesNotInstalled when validation is enabled and the Peppol rules are not installed
     */
    public function transport(?string $name = null): Transport
    {
        if ($this->fake !== null) {
            return $this->fake;
        }
        $name ??= $this->defaultName();

        if (! isset($this->transports[$name])) {
            $driver = $this->driver($name);
            $this->transports[$name] = $this->validationEnabled()
                ? new ValidatingTransport($driver, Validator::peppol($this->rulesDir()))
                : $driver;
        }

        return $this->transports[$name];
    }

    /**
     * Stores the document as queued and dispatches the job that sends it once the open database
     * transaction, if any, commits. The returned message reflects the row as stored here; on the
     * sync queue connection the send has already run, so refresh() it to see the outcome.
     *
     * @param  OutboundDocument|Invoice|string  $document  a routed document, an invoice written as UBL, or UBL XML
     * @param  string|null  $reference  the application's own reference, e.g. the invoice number
     * @param  string|null  $transport  the transport name; the default one when null
     *
     * @throws UnsafeXml when the XML carries a DOCTYPE declaration
     * @throws UnsupportedDocument when the document is not a UBL Invoice or CreditNote or lacks an electronic address
     * @throws InvalidArgumentException when the invoice cannot be written or an identifier is malformed
     * @throws ConfigurationException when the transport is not configured
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
     * The webhook verifier of a Recommand transport. It reads the configuration even while the
     * transports are faked, so signatures are checked as in production.
     *
     * @throws ConfigurationException when the transport is not a Recommand transport with a webhook secret
     */
    public function webhook(string $name): RecommandWebhook
    {
        $config = $this->transportConfig($name);
        if (($config['driver'] ?? null) !== self::BUILT_IN_DRIVER) {
            throw new ConfigurationException(sprintf(
                'Webhooks need the "%s" driver in einvoicing.transports.%s.driver.',
                self::BUILT_IN_DRIVER,
                $name,
            ));
        }

        return new RecommandWebhook($this->requiredString($config, $name, 'webhook_secret'));
    }

    /**
     * The company id configured for the transport with this name, or null when there is none.
     */
    public function companyId(string $name): ?string
    {
        try {
            $companyId = $this->transportConfig($name)['company_id'] ?? null;
        } catch (ConfigurationException) {
            return null;
        }

        return is_string($companyId) && trim($companyId) !== '' ? $companyId : null;
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
     * Whether a transport can be built with this driver: the built-in one or a registered one.
     */
    public function hasDriver(string $driver): bool
    {
        return $driver === self::BUILT_IN_DRIVER || isset($this->drivers[$driver]);
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
        } elseif ($driver === self::BUILT_IN_DRIVER) {
            $transport = $this->recommand($name, $config);
        } else {
            throw new ConfigurationException(sprintf(
                'einvoicing.transports.%s.driver names no known driver; use "%s" or register one with extend().',
                $name,
                self::BUILT_IN_DRIVER,
            ));
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
     * @param  array<string, mixed>  $config
     *
     * @throws ConfigurationException
     */
    private function recommand(string $name, #[SensitiveParameter] array $config): RecommandTransport
    {
        $apiKeyId = $this->credential($config, $name, 'api_key_id');
        $secret = $this->credential($config, $name, 'secret');
        $companyId = $this->requiredString($config, $name, 'company_id');

        $baseUrl = $config['base_url'] ?? null;
        if ($baseUrl === null || $baseUrl === '') {
            $baseUrl = RecommandConfig::DEFAULT_BASE_URL;
        }
        if (! is_string($baseUrl)) {
            throw self::invalidBaseUrl($name);
        }

        try {
            $recommandConfig = new RecommandConfig($apiKeyId, $secret, $companyId, $baseUrl);
        } catch (InvalidArgumentException) {
            // The core message repeats the URL, which may carry credentials; name the key only.
            throw self::invalidBaseUrl($name);
        }

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

        return new RecommandTransport($recommandConfig, new HttpClient($client, $factory, $factory, $maxBytes));
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
            throw new ConfigurationException(sprintf(
                'einvoicing.transports.%s.%s is missing or empty.',
                $name,
                $key,
            ));
        }

        return $value;
    }

    /**
     * A credential of the built-in driver, required and at least as long as the driver accepts.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws ConfigurationException when the value is missing, blank or too short; never quoting it
     */
    private function credential(#[SensitiveParameter] array $config, string $name, string $key): string
    {
        $value = $this->requiredString($config, $name, $key);
        if (strlen(trim($value)) < RecommandConfig::MIN_CREDENTIAL_LENGTH) {
            throw new ConfigurationException(sprintf(
                'einvoicing.transports.%s.%s must be at least %d characters long.',
                $name,
                $key,
                RecommandConfig::MIN_CREDENTIAL_LENGTH,
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
        return (bool) $this->config()->get('einvoicing.validation.enabled', true);
    }

    private function config(): Repository
    {
        return ($this->app)()->make(Repository::class);
    }

    private static function invalidBaseUrl(string $name): ConfigurationException
    {
        return new ConfigurationException(sprintf(
            'einvoicing.transports.%s.base_url must be an https:// URL without user info, query or fragment.',
            $name,
        ));
    }
}
