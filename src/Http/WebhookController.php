<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\WebhookReceived;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Jobs\PollDeliveryStatus;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Support\DeliveryRecorder;
use IntrAgile\EInvoicing\Laravel\Support\InboundRecorder;
use IntrAgile\EInvoicing\Transport\Exception\AuthenticationFailed;
use IntrAgile\EInvoicing\Transport\Exception\UnexpectedResponse;
use IntrAgile\EInvoicing\Transport\Webhook\WebhookEvent;
use IntrAgile\EInvoicing\Transport\Webhook\WebhookKind;

/**
 * Receives signed provider notifications for one transport.
 *
 * The transport's webhook parser checks the signature on the request body exactly as received,
 * before anything else is read from it. Every answer has an empty body: 404 for a transport that
 * takes no webhooks, 401 for a missing or wrong signature, and 204 otherwise, also for
 * notifications that are ignored, so the sender does not retry them. A notification naming
 * another provider account than the configured one is ignored; one naming no account, or arriving
 * for a transport without a configured account, is applied.
 *
 * A received document is recorded once per id and fetched. A delivery change with a state moves
 * a pending document to its final state; a final state is never changed again, so a replayed or
 * late notification cannot undo it. A delivery change without a state schedules a status poll for
 * each pending document it names; on a synchronous queue connection, which schedules no polls,
 * and once a document has used up its status checks, nothing happens and the row stays pending.
 * Repeated deliveries are harmless.
 *
 * A delivery status that arrives before the send job has stored the provider document id finds
 * no row and is dropped; the status polls recover it, except on a synchronous queue connection.
 * On the sync connection the fetch and acknowledgement of a received document run inside this
 * request, so an error there answers with 500.
 */
final class WebhookController
{
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

        $account = $this->manager->accountId($transport);
        if ($event->accountId !== null && $account !== null && $event->accountId !== $account) {
            return new Response('', 204);
        }

        event(new WebhookReceived($transport, $event));

        match ($event->kind) {
            WebhookKind::DocumentReceived => $this->inbound->notify($transport, $event->documentId),
            WebhookKind::DeliveryChanged => $this->deliveryChanged($transport, $event),
            WebhookKind::Other => null,
        };

        return new Response('', 204);
    }

    private function deliveryChanged(string $transport, WebhookEvent $event): void
    {
        $messages = OutboundMessage::query()
            ->where('transport', $transport)
            ->where('provider_document_id', $event->documentId);

        if ($event->deliveryState === null) {
            // The provider did not say where delivery stands: ask, for the documents still pending.
            foreach ($messages->where('state', OutboundState::Pending)->get() as $message) {
                PollDeliveryStatus::start($message->id, $message->attempts);
            }

            return;
        }

        foreach ($messages->get() as $message) {
            $this->delivery->record($message, $event->deliveryState, $event->failure, $event->documentId);
        }
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
