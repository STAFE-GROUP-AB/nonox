<?php

declare(strict_types=1);

use App\Actions\Invoicing\RunRecurringInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveRecurringInvoice;
use App\Events\InvoiceSent;
use App\Livewire\Invoicing\Customers;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Voucher;
use App\Modules\Connect\Http\Resources\ApiPresenter;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\Domain\DocumentType;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\ModuleDisabledException;
use App\Modules\ModuleManager;
use App\Modules\Peppol\Actions\AcceptPeppolDocument;
use App\Modules\Peppol\Actions\RecordTransmissionStatus;
use App\Modules\Peppol\Actions\SavePeppolSettings;
use App\Modules\Peppol\Actions\SendInvoiceViaPeppol;
use App\Modules\Peppol\Domain\InboundDocument;
use App\Modules\Peppol\Domain\RegistrationStatus;
use App\Modules\Peppol\Domain\TransmissionStatus;
use App\Modules\Peppol\Drivers\LogAccessPoint;
use App\Modules\Peppol\Events\PeppolTransmissionChanged;
use App\Modules\Peppol\Jobs\TransmitPeppolDocument;
use App\Modules\Peppol\Livewire\InvoicePeppolPanel;
use App\Modules\Peppol\Livewire\PeppolSettingsForm;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\Models\PeppolTransmission;
use App\Modules\Peppol\PeppolModule;
use App\Support\Peppol\PeppolDocumentRules;
use App\Support\Peppol\UblInvoiceWriter;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Peppol;
use Tests\Support\PeppolFixtures;

beforeEach(function () {
    LogAccessPoint::reset();
    Storage::fake('local');
    [$this->owner, $this->company] = PeppolFixtures::company();
    $this->customer = PeppolFixtures::customer($this->owner, $this->company, ['peppol_id' => '0007:'.PeppolFixtures::BUYER_ORG, 'invoice_delivery' => 'PEPPOL']);
    $this->invoice = PeppolFixtures::invoice($this->owner, $this->company, $this->customer, [
        ['description' => 'Konsulttimmar', 'quantity' => '4', 'unit' => 'tim', 'price' => '950', 'vat_rate' => 2500],
    ]);
});

afterEach(fn () => LogAccessPoint::reset());

/** The golden domestic invoice, as a supplier on Peppol would send it to the company. */
function peppolInbound(string $reference = 'doc-1'): InboundDocument
{
    return new InboundDocument($reference, (string) file_get_contents(base_path('tests/Fixtures/peppol/inrikes-25-12-6.xml')));
}

describe('the module', function () {
    it('can only be turned on when the installation has an access point', function () {
        expect(app(ModuleManager::class)->enableCheck(PeppolModule::KEY, $this->company)->allowed())->toBeTrue();

        config(['services.peppol.driver' => 'qvalia', 'services.peppol.qvalia.partner_reg_no' => null]);
        expect(app(ModuleManager::class)->enableCheck(PeppolModule::KEY, $this->company)->blockers[0])->toContain('avtal med en Peppol-operatör');
    });

    it('refuses to send while it is off', function () {
        expect(fn () => app(SendInvoiceViaPeppol::class)($this->owner, $this->invoice))->toThrow(ModuleDisabledException::class);
    });
});

describe('the company settings', function () {
    beforeEach(fn () => app(ModuleManager::class)->enable(PeppolModule::KEY, $this->company, $this->owner));

    it('takes the company\'s own organisation number or a GLN, never another company\'s number', function () {
        $save = app(SavePeppolSettings::class);

        expect($save($this->owner, $this->company, '0007:559900-0006', false)->identifier)->toBe('0007:5599000006')
            ->and($save($this->owner, $this->company, '0088:7300009000005', false)->identifier)->toBe('0088:7300009000005')
            ->and(fn () => $save($this->owner, $this->company, '0007:'.PeppolFixtures::BUYER_ORG, false))->toThrow(ValidationException::class, 'eget organisationsnummer')
            ->and(fn () => $save($this->owner, $this->company, '9930:DE123456789', false))->toThrow(ValidationException::class);
    });

    it('gives one Peppol ID to one company only', function () {
        [$otherOwner, $other] = Bookkeeping::company();
        app(ModuleManager::class)->enable(PeppolModule::KEY, $other, $otherOwner);
        app(SavePeppolSettings::class)($otherOwner, $other, '0088:7300009000005', false);

        expect(fn () => app(SavePeppolSettings::class)($this->owner, $this->company, '0088:7300009000005', false))->toThrow(ValidationException::class, 'kan inte användas');
    });

    it('needs the inbox to receive, and consent to register', function () {
        $save = app(SavePeppolSettings::class);

        expect(fn () => $save($this->owner, $this->company, '0007:5599000006', true))->toThrow(ValidationException::class, 'Inkorg');

        app(ModuleManager::class)->enable('inbox', $this->company, $this->owner);
        expect(fn () => $save($this->owner, $this->company, '0007:5599000006', true, register: true))->toThrow(ValidationException::class, 'godkänner');

        $waiting = $save($this->owner, $this->company, '0007:5599000006', true, register: true, consent: true);
        expect($waiting->registration_status)->toBe(RegistrationStatus::Pending)
            ->and($waiting->consent_by)->toBe($this->owner->id);

        Peppol::ready($this->company, $this->owner, 'acct-A', receive: true);
        $registered = $save($this->owner, $this->company, '0007:5599000006', true, register: true, consent: true);
        expect($registered->registration_status)->toBe(RegistrationStatus::Registered)
            ->and(AuditLog::query()->where('action', 'peppol.registered')->exists())->toBeTrue();
    });

    it('is a settings form for those who manage settings', function () {
        Livewire::test(PeppolSettingsForm::class, ['company' => $this->company])
            ->assertSet('identifier', '0007:5599000006')
            ->set('identifier', '0007:5599000006')
            ->call('save')
            ->assertHasNoErrors();

        $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
        $this->actingAs($bookkeeper);
        Livewire::test(PeppolSettingsForm::class, ['company' => $this->company])->assertForbidden();
    });
});

describe('the customer register', function () {
    it('validates the Peppol ID and derives it from a Swedish organisation number', function () {
        $save = app(SaveCustomer::class);
        $derived = $save($this->owner, $this->company, ['name' => 'Kommunen', 'org_number' => '212000-0001', 'payment_terms_days' => 30, 'invoice_delivery' => 'PEPPOL', 'peppol_id' => '']);

        expect($derived->peppol_id)->toBe('0007:2120000001')
            ->and($derived->invoice_delivery)->toBe('PEPPOL')
            ->and(fn () => $save($this->owner, $this->company, ['name' => 'Fel', 'payment_terms_days' => 30, 'peppol_id' => '0007:2120000002']))->toThrow(ValidationException::class, 'Peppol-ID')
            ->and(fn () => $save($this->owner, $this->company, ['name' => 'Utan', 'country' => 'DE', 'payment_terms_days' => 30, 'invoice_delivery' => 'PEPPOL', 'peppol_id' => '']))->toThrow(ValidationException::class, 'Peppol-ID');

        $audit = AuditLog::query()->where('action', 'customer.updated')->latest('id')->first();
        expect(json_encode($audit?->payload))->not->toContain('2120000001');
    });

    it('shows the Peppol fields only while the module is on', function () {
        Livewire::test(Customers::class)->call('create')->assertDontSee(__('Kundens Peppol-ID'));

        app(ModuleManager::class)->enable(PeppolModule::KEY, $this->company, $this->owner);
        Livewire::test(Customers::class)->call('create')->assertSee(__('Kundens Peppol-ID'))->assertSet('form.invoice_delivery', 'EMAIL');
    });
});

describe('sending', function () {
    beforeEach(fn () => Peppol::ready($this->company, $this->owner, 'acct-A'));

    it('hands a valid BIS Billing 3.0 document to the access point without touching the invoice or the books', function () {
        Event::fake([InvoiceSent::class, PeppolTransmissionChanged::class]);
        $before = $this->invoice->fresh()->getAttributes();
        $vouchers = Voucher::query()->count();

        $transmission = app(SendInvoiceViaPeppol::class)($this->owner, $this->invoice)->fresh();

        expect($transmission->status)->toBe(TransmissionStatus::Sent)
            ->and($transmission->provider_reference)->toStartWith('log-')
            ->and($transmission->receiver)->toBe('0007:'.PeppolFixtures::BUYER_ORG)
            ->and(LogAccessPoint::$sent)->toHaveCount(1)
            ->and(LogAccessPoint::$sent[0]['key'])->toBe($transmission->id)
            ->and(PeppolDocumentRules::check(LogAccessPoint::$sent[0]['xml']))->toBe([])
            ->and(hash('sha256', (string) Storage::disk('local')->get($transmission->xml_path)))->toBe($transmission->xml_sha256)
            ->and($this->invoice->fresh()->getAttributes())->toBe($before)
            ->and(Voucher::query()->count())->toBe($vouchers)
            ->and(AuditLog::query()->whereIn('action', ['invoice.peppol_queued', 'invoice.peppol_sent'])->count())->toBe(2);

        Event::assertDispatched(InvoiceSent::class);
        Event::assertDispatched(PeppolTransmissionChanged::class, fn (PeppolTransmissionChanged $event): bool => $event->status === TransmissionStatus::Sent);

        expect(fn () => app(SendInvoiceViaPeppol::class)($this->owner, $this->invoice))->toThrow(ValidationException::class, 'redan skickad');
    });

    it('sends a credit invoice as a credit note', function () {
        $credit = PeppolFixtures::credit($this->owner, $this->invoice);
        app(SendInvoiceViaPeppol::class)($this->owner, $credit);

        expect(LogAccessPoint::$sent[0]['credit_note'])->toBeTrue()
            ->and(LogAccessPoint::$sent[0]['xml'])->toContain('<CreditNote');
    });

    it('refuses without the customer\'s Peppol ID or the company\'s account', function () {
        $this->customer->forceFill(['peppol_id' => null])->save();
        expect(fn () => app(SendInvoiceViaPeppol::class)($this->owner, $this->invoice))->toThrow(ValidationException::class, 'Peppol-ID');

        $this->customer->forceFill(['peppol_id' => '0007:'.PeppolFixtures::BUYER_ORG])->save();
        CurrentCompany::run($this->company, fn () => PeppolParticipant::query()->update(['provider_account' => null]));
        expect(fn () => app(SendInvoiceViaPeppol::class)($this->owner, $this->invoice))->toThrow(ValidationException::class, 'inte kopplat');
    });

    it('keeps a transmission queued while the access point is down, and fails it when the document is refused', function () {
        LogAccessPoint::$failNext = 'HTTP 503';
        $queued = app(SendInvoiceViaPeppol::class)($this->owner, $this->invoice)->fresh();

        expect($queued->status)->toBe(TransmissionStatus::Queued)
            ->and($queued->last_error)->toBe('HTTP 503')
            ->and($queued->attempts)->toBe(1);

        (new TransmitPeppolDocument($this->company->id, $queued->id))->handle(app(ModuleManager::class), app(RecordTransmissionStatus::class));
        expect($queued->fresh()->status)->toBe(TransmissionStatus::Sent);

        $second = PeppolFixtures::invoice($this->owner, $this->company, $this->customer, [['description' => 'Arbete', 'quantity' => '1', 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500]]);
        LogAccessPoint::$failNext = 'HTTP 422 (E_VALIDATION)';
        LogAccessPoint::$failRetryable = false;
        $failed = app(SendInvoiceViaPeppol::class)($this->owner, $second)->fresh();

        $again = app(SendInvoiceViaPeppol::class)($this->owner, $second)->fresh();

        expect($failed->status)->toBe(TransmissionStatus::Failed)
            ->and($failed->refused)->toBeTrue()
            ->and($failed->failed_at)->not->toBeNull()
            ->and($again->status)->toBe(TransmissionStatus::Sent)
            ->and($again->id)->not->toBe($failed->id);
    });

    it('sends a sending that timed out again as the same transmission, so the access point can drop the duplicate', function () {
        LogAccessPoint::$failNext = 'The access point did not answer.';
        LogAccessPoint::$failRetryable = true;
        $first = app(SendInvoiceViaPeppol::class)($this->owner, $this->invoice);
        // The retries ran out: failed, but not refused.
        app(RecordTransmissionStatus::class)($first->fresh(), TransmissionStatus::Failed, error: 'The access point did not answer.');

        $again = app(SendInvoiceViaPeppol::class)($this->owner, $this->invoice)->fresh();

        expect($again->id)->toBe($first->id)
            ->and($again->status)->toBe(TransmissionStatus::Sent)
            ->and(LogAccessPoint::$sent[0]['key'])->toBe($first->id)
            ->and(PeppolTransmission::query()->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'invoice.peppol_requeued')->exists())->toBeTrue();
    });

    it('refuses reverse charge without the buyer\'s VAT number', function () {
        $builder = PeppolFixtures::customer($this->owner, $this->company, ['name' => 'Bygg AB', 'reverse_charge_construction' => true]);
        $invoice = PeppolFixtures::invoice($this->owner, $this->company, $builder, [['description' => 'Takarbete', 'quantity' => '1', 'unit' => 'st', 'price' => '1 000', 'vat_rate' => 2500]], ['reverse_charge' => true]);
        // Issuing already demands the number; the writer refuses on its own as well (an in-memory copy, never saved).
        $copy = $invoice->replicate();
        $copy->number = $invoice->number;
        $copy->status = $invoice->status;
        $copy->customer_snapshot = [...$invoice->customer_snapshot, 'vat_number' => null];

        expect(UblInvoiceWriter::unsupportedReason($invoice, $this->company))->toBeNull()
            ->and(UblInvoiceWriter::unsupportedReason($copy, $this->company))->toContain('momsregistreringsnummer');
    });

    it('records delivery from the access point and never goes back', function () {
        $transmission = app(SendInvoiceViaPeppol::class)($this->owner, $this->invoice);
        $this->artisan('peppol:sync')->assertSuccessful();

        expect($transmission->fresh()->status)->toBe(TransmissionStatus::Delivered);

        app(RecordTransmissionStatus::class)($transmission->fresh(), TransmissionStatus::Failed, error: 'late');
        expect($transmission->fresh()->status)->toBe(TransmissionStatus::Delivered);
    });

    it('is a panel on the invoice page', function () {
        Livewire::test(InvoicePeppolPanel::class, ['invoice' => $this->invoice])
            ->assertSee(__('Skicka via Peppol'))
            ->call('check')
            ->assertSet('lookupOk', true)
            ->call('send')
            ->assertHasNoErrors()
            ->assertSee(TransmissionStatus::Sent->label());

        LogAccessPoint::$unknown = ['0007:'.PeppolFixtures::BUYER_ORG];
        Livewire::test(InvoicePeppolPanel::class, ['invoice' => $this->invoice])->call('check')->assertSet('lookupOk', false);
    });

    it('sends a recurring invoice to a Peppol customer through Peppol', function () {
        $agreement = app(SaveRecurringInvoice::class)($this->owner, $this->company, [
            'customer_id' => $this->customer->id, 'name' => 'Supportavtal', 'next_invoice_date' => '2026-03-02', 'interval_months' => 1, 'send_automatically' => true,
            'rows' => [['description' => 'Support', 'quantity' => '1', 'unit' => 'st', 'price' => '500', 'vat_rate' => 2500]],
        ]);
        app(RunRecurringInvoice::class)($this->owner, $agreement);

        expect(LogAccessPoint::$sent)->toHaveCount(1);
    });

    it('shows the Peppol status in the API', function () {
        app(SendInvoiceViaPeppol::class)($this->owner, $this->invoice);
        $presented = ApiPresenter::invoice($this->invoice->fresh()->load(['rows.account', 'rows.article', 'payments.account', 'customer', 'peppolTransmissions']));

        expect($presented['peppol']['status'])->toBe('SENT')
            ->and($presented['peppol'])->not->toHaveKey('receiver');
    });
});

describe('receiving', function () {
    beforeEach(function () {
        // The golden invoice is addressed to 0007:5566778808: this company receives it.
        $this->company->forceFill(['org_number' => '556677-8808', 'vat_number' => 'SE556677880801'])->save();
        $this->participant = Peppol::ready($this->company->fresh(), $this->owner, 'acct-R', receive: true);
    });

    it('takes an e-invoice into the inbox as a supplier invoice proposal with the XML as the original', function () {
        LogAccessPoint::$inbox['acct-R'] = [peppolInbound()];
        $this->artisan('peppol:sync')->assertSuccessful();

        $message = CurrentCompany::run($this->company, fn () => InboxMessage::query()->with('attachments')->sole());
        $xml = (string) file_get_contents(base_path('tests/Fixtures/peppol/inrikes-25-12-6.xml'));

        expect($message->channel)->toBe(Channel::Peppol)
            ->and($message->from_email)->toBe('0007:5599000006')
            ->and($message->recipient)->toBe('0007:5566778808')
            ->and($message->eml_sha256)->toBe(hash('sha256', $xml))
            ->and(Storage::disk('local')->get($message->eml_path))->toBe($xml)
            ->and($message->quarantine_reasons)->toBe([])
            ->and($message->document_type)->toBe(DocumentType::PeppolInvoice)
            ->and($message->proposal['invoice']['invoice_number'] ?? null)->toBe('1')
            ->and($message->proposal['invoice']['ocr'] ?? null)->toBe('18')
            ->and(collect($message->warnings)->pluck('code'))->toContain('NO_SUPPLIER')
            ->and($message->attachments->pluck('mime'))->toContain('application/pdf');

        // A retried delivery of the same bytes is taken once.
        app(AcceptPeppolDocument::class)($this->company, peppolInbound('doc-2'));
        expect(CurrentCompany::run($this->company, fn () => InboxMessage::query()->count()))->toBe(1);
    });

    it('refuses a document addressed to another participant, and everything while receiving is off', function () {
        $other = new InboundDocument('x', str_replace('<cbc:EndpointID schemeID="0007">5566778808</cbc:EndpointID>', '<cbc:EndpointID schemeID="0007">2120000001</cbc:EndpointID>', peppolInbound()->xml));

        expect(app(AcceptPeppolDocument::class)($this->company, $other))->toBeNull();

        $this->participant->forceFill(['receive_enabled' => false])->save();
        expect(app(AcceptPeppolDocument::class)($this->company, peppolInbound()))->toBeNull()
            ->and(CurrentCompany::run($this->company, fn () => InboxMessage::query()->count()))->toBe(0)
            ->and(AuditLog::query()->where('action', 'peppol.dropped')->count())->toBe(2);
    });

    it('serves the original as the XML file', function () {
        $message = app(AcceptPeppolDocument::class)($this->company, peppolInbound());

        $this->get(route('inbox.original', ['message' => $message?->id]))
            ->assertOk()
            ->assertDownload('e-faktura.xml');
    });
});
