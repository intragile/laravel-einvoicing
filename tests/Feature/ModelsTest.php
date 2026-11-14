<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ServiceProvider;
use IntrAgile\EInvoicing\Laravel\EInvoicingServiceProvider;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\DeliveryFailure;
use IntrAgile\EInvoicing\Transport\FailureCategory;
use IntrAgile\EInvoicing\Transport\OutboundDocument;
use IntrAgile\EInvoicing\Transport\Peppol\DocumentTypeId;
use IntrAgile\EInvoicing\Transport\Peppol\ParticipantId;
use IntrAgile\EInvoicing\Transport\Peppol\PeppolBis3;
use IntrAgile\EInvoicing\Transport\Peppol\ProcessId;

final class ModelsTest extends TestCase
{
    use RefreshDatabase;

    private function document(): OutboundDocument
    {
        return new OutboundDocument(
            '<Invoice>é</Invoice>',
            ParticipantId::parse('0208:0123456789'),
            ParticipantId::parse('0208:AbC'),
            new DocumentTypeId(PeppolBis3::INVOICE),
            new ProcessId(PeppolBis3::PROCESS),
            'BE',
        );
    }

    private function stored(string $transport = 'recommand'): OutboundMessage
    {
        $message = OutboundMessage::fromDocument($this->document(), $transport, 'INV-1');
        $message->state = OutboundState::Queued;
        $message->save();

        return $message;
    }

    public function test_round_trips_outbound_document(): void
    {
        $original = $this->document();
        $id = $this->stored()->getKey();

        $restored = OutboundMessage::query()->findOrFail($id)->toOutboundDocument();

        $this->assertSame($original->xml, $restored->xml);
        $this->assertSame('0208:0123456789', $restored->sender->toString());
        $this->assertSame('0208:AbC', $restored->receiver->toString());
        $this->assertSame($original->documentType->value, $restored->documentType->value);
        $this->assertSame($original->process->value, $restored->process->value);
        $this->assertSame('BE', $restored->countryC1);
    }

    public function test_transition_wins_once(): void
    {
        $first = $this->stored();
        $second = OutboundMessage::query()->findOrFail($first->getKey());

        $this->assertTrue($first->transition(OutboundState::Queued, OutboundState::Sending, ['attempts' => 1]));
        $this->assertFalse($second->transition(OutboundState::Queued, OutboundState::Sending, ['attempts' => 1]));
        $this->assertSame(OutboundState::Sending, $second->state);
        $this->assertSame(OutboundState::Sending, $first->fresh()?->state);
    }

    public function test_transition_respects_conditions(): void
    {
        $message = $this->stored();
        OutboundMessage::query()->whereKey($message->getKey())->update(['attempts' => 1]);

        $this->assertFalse($message->transition(OutboundState::Queued, OutboundState::Sending, [], ['attempts' => 0]));
        $this->assertSame(OutboundState::Queued, $message->state);
        // The lost race refreshed the model to the row as stored.
        $this->assertSame(1, $message->attempts);
        $this->assertFalse($message->isDirty());
    }

    public function test_from_document_builds_the_calling_class(): void
    {
        $subclass = (new class extends OutboundMessage {})::class;

        $message = $subclass::fromDocument($this->document(), 'recommand', 'INV-1');

        $this->assertInstanceOf($subclass, $message);
        $this->assertFalse($message->exists);
    }

    public function test_provider_id_unique_per_transport(): void
    {
        $a = $this->stored('one');
        $a->provider_document_id = 'doc-1';
        $a->save();
        $b = $this->stored('two');
        $b->provider_document_id = 'doc-1';
        $b->save();

        $c = $this->stored('one');
        $c->provider_document_id = 'doc-1';
        $this->expectException(UniqueConstraintViolationException::class);
        $c->save();
    }

    public function test_xml_hidden_from_array(): void
    {
        $this->assertArrayNotHasKey('xml', $this->stored()->toArray());
    }

    public function test_failure_is_rebuilt_from_columns(): void
    {
        $message = $this->stored();
        $this->assertNull($message->failure());

        $message->failure_category = FailureCategory::Validation;
        $message->failure_message = 'bad';
        $message->provider_code = 'E1';
        $message->save();

        $failure = OutboundMessage::query()->findOrFail($message->getKey())->failure();
        $this->assertInstanceOf(DeliveryFailure::class, $failure);
        $this->assertSame(FailureCategory::Validation, $failure->category);
        $this->assertSame('bad', $failure->message);
        $this->assertSame('E1', $failure->providerCode);
    }

    public function test_terminal_states(): void
    {
        $this->assertTrue(OutboundState::Delivered->isTerminal());
        $this->assertTrue(OutboundState::Failed->isTerminal());
        $this->assertFalse(OutboundState::Unconfirmed->isTerminal());
        $this->assertFalse(OutboundState::Pending->isTerminal());
    }

    public function test_migration_is_publishable(): void
    {
        $paths = ServiceProvider::pathsToPublish(EInvoicingServiceProvider::class, 'einvoicing-migrations');

        $this->assertCount(1, $paths);
        $target = (string) array_values($paths)[0];
        $this->assertMatchesRegularExpression('/\d{4}_\d{2}_\d{2}_\d{6}_create_einvoicing_tables\.php$/', $target);
        $this->assertFileExists((string) array_key_first($paths));
    }
}
