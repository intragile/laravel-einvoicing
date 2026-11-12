<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\FailureCategory;
use IntrAgile\EInvoicing\Transport\FakeTransport;
use IntrAgile\EInvoicing\Transport\Transport;
use PHPUnit\Framework\Attributes\Group;

/**
 * Installs the official Peppol rules from a local copy of the sources, named by the environment
 * variable EINVOICING_PEPPOL_FROM, and sends through a validating transport. OpenPeppol publishes
 * no licence for its rules, so neither the sources nor the installed rules are part of this
 * repository.
 */
#[Group('peppol-rules')]
final class PeppolRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sync');
    }

    public function test_installs_rules_and_doctor_passes(): void
    {
        $from = getenv('EINVOICING_PEPPOL_FROM');
        if (! is_string($from) || $from === '') {
            $this->markTestSkipped('Set EINVOICING_PEPPOL_FROM to a folder holding the Peppol BIS Billing .sch files.');
        }
        $example = dirname($from).'/examples/base-example.xml';
        if (! is_file($example)) {
            $this->markTestSkipped("The Peppol base example is not at {$example}.");
        }
        $xml = (string) file_get_contents($example);

        // A fake inner driver, so the manager wraps it in the validating transport; fake() would not.
        $inner = new FakeTransport;
        config()->set('einvoicing.validation.enabled', true);
        config()->set('einvoicing.validation.rules_dir', $this->emptyDir().'/rules');
        config()->set('einvoicing.transports.recommand', ['driver' => 'inner']);
        $this->app->make(EInvoicingManager::class)->extend('inner', static fn (): Transport => $inner);

        $code = Artisan::call('einvoicing:install-rules', ['--from' => $from]);
        $output = Artisan::output();
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('5 rule files written', $output);

        Artisan::call('einvoicing:doctor');
        $this->assertMatchesRegularExpression('/\|\s*validation\s*\|\s*ok\s*\|/', Artisan::output());

        $sent = EInvoicing::send($xml, 'base-example');

        $this->assertSame(OutboundState::Delivered, OutboundMessage::query()->findOrFail($sent->id)->state);
        $this->assertCount(1, $inner->sent());

        // The payable amount no longer matches the totals: a fatal business rule fails.
        $invalid = str_replace(
            '<cbc:PayableAmount currencyID="EUR">1656.25</cbc:PayableAmount>',
            '<cbc:PayableAmount currencyID="EUR">1.00</cbc:PayableAmount>',
            $xml,
        );
        $this->assertNotSame($xml, $invalid);

        $refused = OutboundMessage::query()->findOrFail(EInvoicing::send($invalid, 'invalid')->id);

        $this->assertSame(OutboundState::Failed, $refused->state);
        $this->assertSame(FailureCategory::Validation, $refused->failure_category);
        $this->assertCount(1, $inner->sent());
    }
}
