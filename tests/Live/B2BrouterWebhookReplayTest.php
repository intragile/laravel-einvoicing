<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Live;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use IntrAgile\EInvoicing\France\Lifecycle\LifecycleStatus;
use IntrAgile\EInvoicing\Laravel\Events\LifecycleStatusChanged;
use IntrAgile\EInvoicing\Laravel\Events\WebhookReceived;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Replays webhook deliveries that B2Brouter really sent through the bridge's webhook route.
 *
 * The deliveries are captured beforehand by a public endpoint that stores each POST as it came:
 * B2BROUTER_WEBHOOK_CAPTURE_DIR holds "<name>.body" (the raw body) and "<name>.headers.json"
 * (the X-B2Brouter-* headers as PHP $_SERVER keys) per delivery. B2BROUTER_WEBHOOK_SECRET is the
 * web hook's signing secret and B2BROUTER_ACCOUNT_ID the account it was created for. Skips
 * without them. No request leaves the machine: the test checks that real signatures verify, that
 * every delivery is accepted, and that the French lifecycle statuses fire LifecycleStatusChanged.
 */
#[Group('b2brouter-france-live')]
final class B2BrouterWebhookReplayTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSPORT = 'b2brouter-fr';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('einvoicing.transports.'.self::TRANSPORT, [
            'driver' => 'b2brouter',
            'api_key' => 'test_replay-only',
            'account_id' => (string) getenv('B2BROUTER_ACCOUNT_ID'),
            'webhook_secret' => (string) getenv('B2BROUTER_WEBHOOK_SECRET'),
        ]);
    }

    protected function setUp(): void
    {
        foreach (['B2BROUTER_WEBHOOK_CAPTURE_DIR', 'B2BROUTER_WEBHOOK_SECRET', 'B2BROUTER_ACCOUNT_ID'] as $name) {
            if ((string) getenv($name) === '') {
                self::markTestSkipped('Set B2BROUTER_WEBHOOK_CAPTURE_DIR, B2BROUTER_WEBHOOK_SECRET and B2BROUTER_ACCOUNT_ID to replay captured B2Brouter webhook deliveries.');
            }
        }

        parent::setUp();
    }

    public function test_captured_deliveries_verify_and_fire_lifecycle_events(): void
    {
        $deliveries = self::deliveries((string) getenv('B2BROUTER_WEBHOOK_CAPTURE_DIR'));
        self::assertNotSame([], $deliveries, 'The capture directory holds no delivery.');
        Event::fake([LifecycleStatusChanged::class, WebhookReceived::class]);

        $codes = [];
        foreach ($deliveries as [$body, $headers]) {
            $response = $this->call('POST', '/einvoicing/webhooks/'.self::TRANSPORT, [], [], [], $headers, $body);
            $code = (string) (json_decode($body, true)['code'] ?? '?');
            $codes[$code] = ($codes[$code] ?? 0) + 1;
            self::assertSame(204, $response->getStatusCode(), $code.' was not accepted.');
        }
        fwrite(STDERR, "\n[b2brouter-france-live] Replayed deliveries: ".json_encode($codes)."\n");

        Event::assertDispatchedTimes(WebhookReceived::class, count($deliveries));
        Event::assertDispatched(LifecycleStatusChanged::class, static fn (LifecycleStatusChanged $event): bool => $event->status === LifecycleStatus::Deposited && ! $event->received);
        Event::assertDispatched(LifecycleStatusChanged::class, static fn (LifecycleStatusChanged $event): bool => $event->status === LifecycleStatus::Approved && $event->received);

        // A changed body no longer matches its signature.
        [$body, $headers] = $deliveries[0];
        $this->call('POST', '/einvoicing/webhooks/'.self::TRANSPORT, [], [], [], $headers, $body.' ')->assertStatus(401);
    }

    /**
     * @return list<array{string, array<string, string>}>
     */
    private static function deliveries(string $directory): array
    {
        $deliveries = [];
        foreach (glob(rtrim($directory, '/').'/*.body') ?: [] as $file) {
            $headers = json_decode((string) file_get_contents(substr($file, 0, -5).'.headers.json'), true);
            $server = ['CONTENT_TYPE' => 'application/json'];
            foreach (is_array($headers) ? $headers : [] as $name => $value) {
                if (is_string($name) && is_string($value) && str_starts_with($name, 'HTTP_')) {
                    $server[$name] = $value;
                }
            }
            $deliveries[] = [(string) file_get_contents($file), $server];
        }

        return $deliveries;
    }
}
