<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Support;

use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\Ksef\KsefTransport;

/**
 * A synthetic FA(3) invoice (seller 5265877635, buyer 9657184030, issued on 2026-11-20) and
 * stored KSeF rows, with the KSeF transport bound to canned API answers.
 *
 * @phpstan-require-extends TestCase
 */
trait KsefInvoices
{
    protected const BUYER_NIP = '9657184030';

    protected const DOCUMENT_ID = FakeKsefApi::SESSION_REF.'/'.FakeKsefApi::INVOICE_REF;

    protected FakeKsefApi $api;

    protected static function fa3(?string $issueDate = null, ?string $number = null): string
    {
        $xml = (string) file_get_contents(dirname(__DIR__).'/fixtures/fa3-invoice.xml');
        if ($issueDate !== null) {
            $xml = str_replace('<P_1>2026-11-20</P_1>', '<P_1>'.$issueDate.'</P_1>', $xml);
        }
        if ($number !== null) {
            $xml = str_replace('<P_2>FV/TEST/1/2026</P_2>', '<P_2>'.$number.'</P_2>', $xml);
        }

        return $xml;
    }

    /**
     * Binds a KSeF transport over the canned API, authenticated with a KSeF token.
     */
    protected function bindKsef(?FakeKsefApi $api = null): FakeKsefApi
    {
        $this->api = $api ?? (new FakeKsefApi)->withTokenAuthentication();
        config()->set('einvoicing.ksef.nip', FakeKsefApi::NIP);
        $this->app->instance(KsefTransport::class, $this->api->transport());

        return $this->api;
    }

    /**
     * A KSeF row as a send left it.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function ksefRow(OutboundState $state = OutboundState::Pending, array $attributes = []): OutboundMessage
    {
        $message = new OutboundMessage([
            'transport' => 'ksef',
            'reference' => 'FV/TEST/1/2026',
            'sender' => FakeKsefApi::NIP,
            'receiver' => self::BUYER_NIP,
            'document_type' => 'FA (3)',
            'process' => 'ksef',
            'xml' => self::fa3(),
            'invoicing_mode' => 'Online',
            'attempts' => 1,
            'provider_document_id' => $state === OutboundState::Queued ? null : self::DOCUMENT_ID,
            ...$attributes,
        ]);
        $message->state = $state;
        $message->save();

        return $message;
    }
}
