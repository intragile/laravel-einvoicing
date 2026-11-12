<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Transport\FakeTransport;
use LogicException;

/**
 * @method static \IntrAgile\EInvoicing\Transport\Transport transport(?string $name = null)
 * @method static \IntrAgile\EInvoicing\Laravel\Models\OutboundMessage send(\IntrAgile\EInvoicing\Transport\OutboundDocument|\IntrAgile\EInvoicing\Model\Invoice|string $document, ?string $reference = null, ?string $transport = null)
 * @method static \IntrAgile\EInvoicing\Transport\InboundSource inbound(?string $name = null)
 * @method static \IntrAgile\EInvoicing\Transport\Recommand\RecommandWebhook webhook(string $name)
 * @method static void extend(string $driver, \Closure $factory)
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
