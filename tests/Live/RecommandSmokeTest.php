<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Live;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use IntrAgile\EInvoicing\CodeList\ElectronicAddressScheme;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Jobs\PollDeliveryStatus;
use IntrAgile\EInvoicing\Laravel\Tests\Support\Invoices;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Model\ElectronicAddress;
use IntrAgile\EInvoicing\Model\Party;
use IntrAgile\EInvoicing\Transport\Peppol\ParticipantId;
use IntrAgile\EInvoicing\Validation\DocumentValidator;
use IntrAgile\EInvoicing\Validation\XRechnung\XRechnungValidator;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sends an XRechnung invoice through the installed bridge to the Peppol test network with the
 * built-in "recommand" driver, and follows the outbound row until it is delivered.
 *
 * Needs RECOMMAND_API_KEY_ID, RECOMMAND_API_SECRET, RECOMMAND_COMPANY_ID and RECOMMAND_SENDER_ID
 * of a Recommand playground team created with useTestNetwork, and skips without them; the
 * receiver is RECOMMAND_RECIPIENT_ID, or the sender itself. Before anything is sent,
 * GET /api/v1/playgrounds/current must report a playground on the test network, so the test can
 * never send from a production team.
 *
 * EInvoicing::send() runs on the sync queue, so the send job finishes before it returns. The sync
 * connection cannot delay the status polls, so the test runs each poll itself: up to 10 polls,
 * 30 seconds apart. The document is checked by the KoSIT XRechnung validator, which is what the
 * default Peppol payload validator delegates XRechnung documents to; the Peppol BIS rules, which
 * this document does not use, need not be installed.
 */
#[Group('recommand-live')]
final class RecommandSmokeTest extends TestCase
{
    use RefreshDatabase;

    private const TAG = 'recommand-live';

    private const POLLS = 10;

    private const POLL_WAIT_SECONDS = 30;

    private const REQUIRED_ENV = ['RECOMMAND_API_KEY_ID', 'RECOMMAND_API_SECRET', 'RECOMMAND_COMPANY_ID', 'RECOMMAND_SENDER_ID'];

    protected function defineEnvironment($app): void
    {
        $baseUrl = (string) getenv('RECOMMAND_BASE_URL');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('einvoicing.queue.connection', null);
        $app['config']->set('einvoicing.default', 'recommand');
        $app['config']->set('einvoicing.status.max_attempts', self::POLLS);
        $app['config']->set('einvoicing.transports.recommand', [
            'driver' => 'recommand',
            'api_key_id' => (string) getenv('RECOMMAND_API_KEY_ID'),
            'secret' => (string) getenv('RECOMMAND_API_SECRET'),
            'company_id' => (string) getenv('RECOMMAND_COMPANY_ID'),
            'base_url' => $baseUrl !== '' ? $baseUrl : 'https://app.recommand.eu',
        ]);
    }

    protected function setUp(): void
    {
        foreach (self::REQUIRED_ENV as $name) {
            if ((string) getenv($name) === '') {
                self::markTestSkipped('Set '.implode(', ', self::REQUIRED_ENV).' of a Recommand playground team on the Peppol test network.');
            }
        }

        parent::setUp();
    }

    public function test_send_through_the_bridge_reaches_delivered(): void
    {
        $this->assertTestNetworkPlayground();
        app()->instance(DocumentValidator::class, XRechnungValidator::create());

        $sender = ParticipantId::parse((string) getenv('RECOMMAND_SENDER_ID'));
        $recipientEnv = (string) getenv('RECOMMAND_RECIPIENT_ID');
        $recipient = $recipientEnv !== '' ? ParticipantId::parse($recipientEnv) : $sender;
        $number = 'LIVE-'.bin2hex(random_bytes(6));

        $base = Invoices::xrechnung();
        $invoice = $base->withNumber($number)
            ->withSeller(self::reachedAt($base->seller, $sender))
            ->withBuyer(self::reachedAt($base->buyer, $recipient));

        $message = EInvoicing::send($invoice, $number, 'recommand');
        $message->refresh();
        self::assertContains($message->state, [OutboundState::Pending, OutboundState::Delivered], (string) $message->failure_message);

        for ($poll = 1; $poll <= self::POLLS && $message->state === OutboundState::Pending; $poll++) {
            sleep(self::POLL_WAIT_SECONDS);
            PollDeliveryStatus::dispatchSync($message->id, $message->attempts);
            $message->refresh();
        }

        fwrite(STDERR, sprintf(
            '%s[%s] bridge-smoke state=%s messageId=%s%s',
            PHP_EOL,
            self::TAG,
            $message->state->value,
            $message->peppol_message_id ?? 'none',
            PHP_EOL,
        ));
        self::assertSame(OutboundState::Delivered, $message->state, (string) $message->failure_message);
    }

    /**
     * Fails unless the API key belongs to a playground team on the Peppol test network. It makes
     * only that one GET request; neither the credentials nor the response body reach the message.
     */
    private function assertTestNetworkPlayground(): void
    {
        $baseUrl = config()->string('einvoicing.transports.recommand.base_url');
        $response = Http::withBasicAuth((string) getenv('RECOMMAND_API_KEY_ID'), (string) getenv('RECOMMAND_API_SECRET'))
            ->acceptJson()
            ->timeout(30)
            ->get(rtrim($baseUrl, '/').'/api/v1/playgrounds/current');

        self::assertSame(200, $response->status(), 'GET /api/v1/playgrounds/current answered HTTP '.$response->status().'.');
        $playground = $response->json('playground');
        self::assertTrue(
            is_array($playground),
            'The API key does not belong to a playground team; the live test refuses to send from a production team.',
        );
        self::assertTrue(
            ($playground['useTestNetwork'] ?? null) === true,
            'The playground does not use the Peppol test network; create one with useTestNetwork: true.',
        );
    }

    private static function reachedAt(Party $party, ParticipantId $participant): Party
    {
        $scheme = ElectronicAddressScheme::tryFrom($participant->icd);
        self::assertNotNull($scheme, sprintf('The participant scheme %s is not an electronic address scheme.', $participant->icd));

        return new Party(
            name: $party->name,
            tradingName: $party->tradingName,
            identifiers: $party->identifiers,
            legalRegistration: $party->legalRegistration,
            vatIdentifier: $party->vatIdentifier,
            taxRegistration: $party->taxRegistration,
            additionalLegalInformation: $party->additionalLegalInformation,
            electronicAddress: new ElectronicAddress($participant->value, $scheme),
            address: $party->address,
            contact: $party->contact,
        );
    }
}
