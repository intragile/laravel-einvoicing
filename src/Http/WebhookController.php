<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Events\WebhookReceived;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Support\DeliveryRecorder;
use IntrAgile\EInvoicing\Laravel\Support\InboundRecorder;
use IntrAgile\EInvoicing\Transport\Exception\AuthenticationFailed;
use IntrAgile\EInvoicing\Transport\Exception\UnexpectedResponse;

/**
 * Receives signed Recommand notifications for one transport.
 *
 * The signature is checked on the request body exactly as received, before anything else is
 * read from it. Every answer has an empty body: 404 for a transport that takes no webhooks, 401
 * for a missing or wrong signature, and 204 otherwise, also for notifications that are ignored,
 * so the sender does not retry them. Repeated deliveries are harmless: inbound documents are
 * stored once per id and delivery states only move a pending document.
 *
 * A delivery status that arrives before the send job has stored the provider document id finds
 * no row and is dropped; the status polls recover it, except on a synchronous queue connection,
 * which schedules no polls. On the sync connection the fetch and acknowledgement of a received
 * document run inside this request, so an error there answers with 500.
 */
final class WebhookController
{
    private const DOCUMENT_RECEIVED = 'document.received';

    public function __construct(
        private readonly EInvoicingManager $manager,
        private readonly InboundRecorder $inbound,
        private readonly DeliveryRecorder $delivery,
    ) {}

    public function __invoke(Request $request, string $transport): Response
    {
        try {
            $webhook = $this->manager->webhook($transport);
        } catch (ConfigurationException) {
            return new Response('', 404);
        }

        try {
            $event = $webhook->parse($request->getContent(), self::headers($request));
        } catch (AuthenticationFailed) {
            return new Response('', 401);
        } catch (UnexpectedResponse) {
            // A signed notification that is not about a document: acknowledged and ignored.
            return new Response('', 204);
        }

        if ($event->companyId !== null && $event->companyId !== $this->manager->companyId($transport)) {
            return new Response('', 204);
        }

        event(new WebhookReceived($transport, $event));

        if ($event->type === self::DOCUMENT_RECEIVED) {
            $this->inbound->notify($transport, $event->documentId);
        }

        if ($event->deliveryState !== null) {
            $messages = OutboundMessage::query()
                ->where('transport', $transport)
                ->where('provider_document_id', $event->documentId)
                ->get();
            foreach ($messages as $message) {
                $this->delivery->record($message, $event->deliveryState, $event->failure, $event->documentId);
            }
        }

        return new Response('', 204);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function headers(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = array_values(array_filter($values, is_string(...)));
        }

        return $headers;
    }
}
