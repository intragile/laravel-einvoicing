<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Live;

use DateTimeImmutable;
use DateTimeZone;
use IntrAgile\EInvoicing\France\Lifecycle\LifecycleStatus;
use IntrAgile\EInvoicing\France\Routing\Annuaire;
use IntrAgile\EInvoicing\France\Routing\AnnuaireStatus;
use IntrAgile\EInvoicing\France\Siren;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\France\FranceManager;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\B2Brouter\B2BrouterConfig;
use IntrAgile\EInvoicing\Transport\B2Brouter\France\FrenchDocument;
use IntrAgile\EInvoicing\Transport\B2Brouter\France\PlatformStatus;
use IntrAgile\EInvoicing\Transport\DeliveryState;
use IntrAgile\EInvoicing\Transport\Http\Redactor;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\IncompleteTest;
use PHPUnit\Framework\SkippedTest;
use Throwable;

/**
 * Runs the French flow through the installed bridge against a B2Brouter sandbox or staging
 * account of a French company: the Annuaire binding, a send that must reach Deposited and a full
 * payment that must read back as Collected.
 *
 * Needs B2BROUTER_API_KEY and B2BROUTER_ACCOUNT_ID of that French account, optionally
 * B2BROUTER_BASE_URL, and skips without them. Only a "test_" key on https://api.b2brouter.net or a
 * "stag_" key on https://api-staging.b2brouter.net is accepted; any other key or host is refused
 * before any request, so the test can never reach production. The invoice is the services
 * invoice in tests/fixtures, sold by the account company: its fictitious SIREN is replaced by the
 * one B2Brouter holds for the account, and it gets a fresh number and today's date.
 *
 * Output never contains the API key, the account id or the account company's tax identifiers.
 */
#[Group('b2brouter-france-live')]
final class B2BrouterFranceSmokeTest extends TestCase
{
    private const TAG = 'b2brouter-france-live';

    /** A SIREN with a valid check digit that belongs to no company; the fixture's seller. */
    private const FICTITIOUS_SIREN = '842917361';

    private const POLLS = 10;

    private const POLL_WAIT_SECONDS = 5;

    private Redactor $redactor;

    protected function defineEnvironment($app): void
    {
        $baseUrl = (string) getenv('B2BROUTER_BASE_URL');
        $app['config']->set('einvoicing.transports.b2brouter-fr', [
            'driver' => 'b2brouter',
            'api_key' => (string) getenv('B2BROUTER_API_KEY'),
            'account_id' => (string) getenv('B2BROUTER_ACCOUNT_ID'),
            'base_url' => $baseUrl !== '' ? $baseUrl : B2BrouterConfig::DEFAULT_BASE_URL,
        ]);
        $app['config']->set('einvoicing.france.transport', 'b2brouter-fr');
    }

    protected function setUp(): void
    {
        if ((string) getenv('B2BROUTER_API_KEY') === '' || (string) getenv('B2BROUTER_ACCOUNT_ID') === '') {
            self::markTestSkipped('Set B2BROUTER_API_KEY and B2BROUTER_ACCOUNT_ID of a B2Brouter sandbox or staging account of a French company.');
        }

        parent::setUp();
    }

    public function test_french_flow_through_the_bridge(): void
    {
        $config = app(FranceManager::class)->b2brouterConfig();
        $sandbox = $config->isSandboxKey() && $config->baseUrl === B2BrouterConfig::DEFAULT_BASE_URL;
        $staging = $config->isStagingKey() && $config->baseUrl === B2BrouterConfig::STAGING_BASE_URL;
        if (! $sandbox && ! $staging) {
            self::fail('The bridge French live test runs only with a "test_" key on the sandbox host or a "stag_" key on the staging host; it refuses any other key or host, production included.');
        }
        $this->redactor = new Redactor([...$config->secrets(), (string) $config->accountId]);

        $this->redacted(function () use ($config): void {
            $entry = app(Annuaire::class)->lookup(Siren::SCHEME, self::FICTITIOUS_SIREN);
            $this->report('Annuaire binding, fictitious SIREN: '.$entry->status->name.'.');
            self::assertContains($entry->status, [AnnuaireStatus::NotListed, AnnuaireStatus::Unknown]);

            $siren = $this->accountSiren($config);
            $vat = self::frenchVat($siren);
            $this->redactor = $this->redactor->with([$siren, $vat]);

            $france = EInvoicing::france();
            $result = $france->send(FrenchDocument::fromXml($this->invoiceXml($siren, $vat)));
            $status = $france->lifecycle($result->documentId);
            for ($poll = 2; $poll <= self::POLLS && $status->lifecycle === null && $status->delivery->state !== DeliveryState::Failed; $poll++) {
                sleep(self::POLL_WAIT_SECONDS);
                $status = $france->lifecycle($result->documentId);
            }
            $this->report('Send: '.self::describe($status).'.');
            self::assertNotNull($status->lifecycle, 'The invoice has no lifecycle status yet.');
            self::assertNotSame(DeliveryState::Failed, $status->delivery->state);

            $paid = $france->recordPayment($result->documentId);
            for ($poll = 2; $poll <= self::POLLS && $paid->lifecycle !== LifecycleStatus::Collected; $poll++) {
                sleep(self::POLL_WAIT_SECONDS);
                $paid = $france->lifecycle($result->documentId);
            }
            $this->report('Full payment: '.self::describe($paid).'.');
            self::assertSame(LifecycleStatus::Collected, $paid->lifecycle);
        });
    }

    /**
     * The SIREN of the account company, read from its tax identifier (a SIREN, a SIRET or a
     * French VAT number).
     */
    private function accountSiren(B2BrouterConfig $config): string
    {
        $response = app(EInvoicingManager::class)->httpClient()->send(
            'GET',
            sprintf('%s/accounts/%d', $config->baseUrl, $config->accountId),
            $config->headers(),
        );
        // The status only: the account body names the company.
        self::assertSame(200, $response->getStatusCode(), sprintf('GET /accounts/{account} answered HTTP %d.', $response->getStatusCode()));
        $data = json_decode((string) $response->getBody(), true);
        $tin = is_array($data) && is_array($data['account'] ?? null) ? ($data['account']['tin_value'] ?? null) : null;
        $tin = is_string($tin) ? strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $tin)) : '';
        if (preg_match('/\A(?:FR[0-9A-Z]{2})?([0-9]{9})(?:[0-9]{5})?\z/', $tin, $match) !== 1 || ! Siren::isValid($match[1])) {
            self::fail('The account has no SIREN, SIRET or French VAT number as its tax identifier.');
        }

        return $match[1];
    }

    /**
     * The fixture invoice sold by the company with the given SIREN, with a fresh number and dated
     * today in Paris.
     */
    private function invoiceXml(string $siren, string $vat): string
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Paris'));
        $xml = (string) file_get_contents(__DIR__.'/../fixtures/french-services-invoice.xml');

        return strtr($xml, [
            self::frenchVat(self::FICTITIOUS_SIREN) => $vat,
            self::FICTITIOUS_SIREN => $siren,
            'FR-SMOKE-NUMBER' => 'LIVE-'.bin2hex(random_bytes(6)),
            '2026-01-02' => $today->format('Y-m-d'),
            '2026-02-01' => $today->modify('+30 days')->format('Y-m-d'),
        ]);
    }

    private static function frenchVat(string $siren): string
    {
        return sprintf('FR%02d%s', (12 + 3 * ((int) $siren % 97)) % 97, $siren);
    }

    private static function describe(PlatformStatus $status): string
    {
        return sprintf(
            'platform state "%s", lifecycle %s, delivery %s',
            $status->platformState,
            $status->lifecycle === null ? '(none)' : $status->lifecycle->name,
            $status->delivery->state->name,
        );
    }

    private function report(string $line): void
    {
        fwrite(STDERR, sprintf("\n[%s] %s\n", self::TAG, $this->redactor->redact($line)));
    }

    /**
     * Runs the callback and rethrows any failure with its message redacted; a skip passes through.
     */
    private function redacted(callable $callback): void
    {
        try {
            $callback();
        } catch (SkippedTest|IncompleteTest $skip) {
            throw $skip;
        } catch (Throwable $throwable) {
            $message = $this->redactor->redact($throwable::class.': '.$throwable->getMessage());

            throw new AssertionFailedError($message);
        }
    }
}
