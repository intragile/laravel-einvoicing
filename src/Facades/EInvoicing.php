<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Transport\FakeTransport;
use LogicException;

/**
 * @method static \IntrAgile\EInvoicing\Transport\Transport transport(?string $name = null, bool $validated = true)
 * @method static \IntrAgile\EInvoicing\Validation\DocumentValidator|null validator()
 * @method static \IntrAgile\EInvoicing\Validation\XRechnung\XRechnungValidator xrechnung()
 * @method static \IntrAgile\EInvoicing\Validation\FacturX\FacturXValidator facturx()
 * @method static \IntrAgile\EInvoicing\Transport\B2Brouter\France\B2BrouterFrance france(?string $transport = null)
 * @method static \IntrAgile\EInvoicing\Transport\B2Brouter\France\B2BrouterAnnuaire annuaire(?string $transport = null)
 * @method static \IntrAgile\EInvoicing\Laravel\Models\OutboundMessage send(\IntrAgile\EInvoicing\Transport\OutboundDocument|\IntrAgile\EInvoicing\Model\Invoice|string $document, ?string $reference = null, ?string $transport = null)
 * @method static \IntrAgile\EInvoicing\Transport\InboundSource inbound(?string $name = null)
 * @method static \IntrAgile\EInvoicing\Laravel\Ksef\KsefManager ksef()
 * @method static \IntrAgile\EInvoicing\Transport\Webhook\WebhookParser webhook(string $name)
 * @method static string|null accountId(string $name)
 * @method static void extend(string $driver, \Closure $factory)
 * @method static void extendWebhook(string $driver, \Closure $factory)
 * @method static bool hasDriver(string $driver)
 * @method static string defaultName()
 * @method static list<string> names()
 * @method static string rulesDir()
 *
 * @see EInvoicingManager
 */
final class EInvoicing extends Facade
{
    /**
     * Replaces every transport with one in-memory fake that records sends and serves inbound
     * documents; documents are not validated.
     */
    public static function fake(): FakeTransport
    {
        $manager = self::getFacadeRoot();
        if (! $manager instanceof EInvoicingManager) {
            throw new LogicException('EInvoicing::fake() requires a booted Laravel application.');
        }

        return $manager->fake();
    }

    protected static function getFacadeAccessor(): string
    {
        return EInvoicingManager::class;
    }
}
