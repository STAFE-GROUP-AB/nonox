<?php

declare(strict_types=1);

use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\ModuleManager;
use App\Modules\Peppol\Actions\AcceptPeppolDocument;
use App\Modules\Peppol\Actions\LookupParticipant;
use App\Modules\Peppol\Actions\RecordTransmissionStatus;
use App\Modules\Peppol\Actions\SavePeppolSettings;
use App\Modules\Peppol\Actions\SendInvoiceViaPeppol;
use App\Modules\Peppol\Domain\InboundDocument;
use App\Modules\Peppol\Domain\TransmissionStatus;
use App\Modules\Peppol\Domain\WebhookEvent;
use App\Modules\Peppol\Drivers\LogAccessPoint;
use App\Modules\Peppol\Jobs\HandlePeppolEvent;
use App\Modules\Peppol\Jobs\TransmitPeppolDocument;
use App\Modules\Peppol\Livewire\InvoicePeppolPanel;
use App\Modules\Peppol\Livewire\PeppolSettingsForm;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\Models\PeppolTransmission;
use App\Modules\Peppol\PeppolModule;
use App\Modules\Peppol\Support\AccessPoints;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Peppol;
use Tests\Support\PeppolFixtures;

/*
 * Launch hardening of Peppol e-faktura (ADR-050, addendum 2026-11-06): the
 * operator's kill switch, Swedish texts for every error a user meets, and
 * failures logged with ids only. The log access point only: nothing leaves.
 */

beforeEach(function () {
    LogAccessPoint::reset();
    Storage::fake('local');
    [$this->owner, $this->company] = PeppolFixtures::company();
    $this->customer = PeppolFixtures::customer($this->owner, $this->company, ['peppol_id' => '0007:'.PeppolFixtures::BUYER_ORG, 'invoice_delivery' => 'PEPPOL']);
    $this->invoice = PeppolFixtures::invoice($this->owner, $this->company, $this->customer, [
        ['description' => 'Konsulttimmar', 'quantity' => '4', 'unit' => 'tim', 'price' => '950', 'vat_rate' => 2500],
    ]);
    $this->participant = Peppol::ready($this->company, $this->owner, 'acct-L');
});

afterEach(fn () => LogAccessPoint::reset());

describe('the kill switch', function () {
    it('pauses every access point with true, or the ones named in a list', function () {
        expect(AccessPoints::isPaused())->toBeFalse();

        config(['services.peppol.paused' => 'qvalia']);
        expect(AccessPoints::isPaused())->toBeFalse()
            ->and(AccessPoints::isPaused('qvalia'))->toBeTrue();

        config(['services.peppol.paused' => true]);
        expect(AccessPoints::isPaused())->toBeTrue();
    });

    it('refuses to send, look up, register or link while paused, in Swedish', function () {
        config(['services.peppol.paused' => true]);

        expect(fn () => app(SendInvoiceViaPeppol::class)($this->owner, $this->invoice))->toThrow(ValidationException::class, 'tillfälligt avstängt')
            ->and(fn () => app(LookupParticipant::class)($this->owner, $this->company, '0007:'.PeppolFixtures::BUYER_ORG))->toThrow(ValidationException::class, 'tillfälligt avstängt')
            ->and(fn () => app(SavePeppolSettings::class)($this->owner, $this->company, '0007:'.PeppolFixtures::SELLER_ORG, false, register: true, consent: true))->toThrow(ValidationException::class, 'tillfälligt avstängt')
            ->and(PeppolTransmission::query()->count())->toBe(0)
            ->and(LogAccessPoint::$sent)->toBe([]);

        $this->artisan('peppol:link', ['company' => $this->company->id, 'account' => 'acct-L'])->assertFailed();
        expect(app(ModuleManager::class)->enableCheck(PeppolModule::KEY, Bookkeeping::company()[1])->blockers[0] ?? '')->toContain('tillfälligt avstängt');

        Livewire::test(InvoicePeppolPanel::class, ['invoice' => $this->invoice])
            ->assertSee(AccessPoints::pausedMessage())
            ->assertDontSeeHtml('wire:click="send"')
            ->assertDontSeeHtml('wire:click="check"');
        Livewire::test(PeppolSettingsForm::class, ['company' => $this->company])->assertSee(AccessPoints::pausedMessage());
    });

    it('keeps a queued sending waiting while paused and sends it, once, when Peppol is back', function () {
        LogAccessPoint::$failNext = 'HTTP 503';
        $transmission = app(SendInvoiceViaPeppol::class)($this->owner, $this->invoice);
        config(['services.peppol.paused' => true]);

        (new TransmitPeppolDocument($this->company->id, $transmission->id))->handle(app(ModuleManager::class), app(RecordTransmissionStatus::class));
        $this->artisan('peppol:sync')->assertSuccessful();

        expect($transmission->fresh()->status)->toBe(TransmissionStatus::Queued)
            ->and($transmission->fresh()->attempts)->toBe(1)
            ->and($transmission->fresh()->supportText())->toContain('tillfälligt avstängt')
            ->and(LogAccessPoint::$sent)->toBe([]);

        config(['services.peppol.paused' => false]);
        $this->artisan('peppol:sync')->assertSuccessful();
        $this->artisan('peppol:sync')->assertSuccessful();

        expect($transmission->fresh()->status)->toBeIn([TransmissionStatus::Sent, TransmissionStatus::Delivered])
            ->and(LogAccessPoint::$sent)->toHaveCount(1)
            ->and(LogAccessPoint::$sent[0]['key'])->toBe($transmission->id);
    });

    it('leaves received documents at the access point while paused, and answers the webhook so it is delivered again', function () {
        $this->company->forceFill(['org_number' => '556334-3689', 'vat_number' => 'SE556334368901'])->save();
        Peppol::ready($this->company->fresh(), $this->owner, 'acct-R', receive: true);
        LogAccessPoint::$inbox['acct-R'] = [new InboundDocument('doc-1', (string) file_get_contents(base_path('tests/Fixtures/peppol/inrikes-25-12-6.xml')))];
        config(['services.peppol.paused' => true]);

        $this->artisan('peppol:sync')->assertSuccessful();
        (new HandlePeppolEvent($this->company->id, WebhookEvent::INCOMING, 'doc-1', 'Invoice'))->handle(app(ModuleManager::class), app(AcceptPeppolDocument::class), app(RecordTransmissionStatus::class));
        // Unsigned (the log driver verifies nothing): refused as unverified, not told about the pause.
        $this->post('/webhooks/peppol/log')->assertStatus(401);

        expect(CurrentCompany::run($this->company, fn () => InboxMessage::query()->count()))->toBe(0)
            ->and(CurrentCompany::run($this->company, fn () => PeppolParticipant::query()->sole()->last_polled_at))->toBeNull();

        config(['services.peppol.paused' => false]);
        $this->artisan('peppol:sync')->assertSuccessful();
        expect(CurrentCompany::run($this->company, fn () => InboxMessage::query()->count()))->toBe(1);
    });
});

describe('errors a user meets', function () {
    it('explains a refused, an unreachable and an undelivered sending in Swedish, never with the provider\'s text', function () {
        LogAccessPoint::$failNext = 'Qvalia send failed with HTTP 422 (E_VALIDATION).';
        LogAccessPoint::$failRetryable = false;
        $refused = app(SendInvoiceViaPeppol::class)($this->owner, $this->invoice)->fresh();

        expect($refused->supportText())->toContain('tog inte emot e-fakturan')
            ->and($refused->supportText())->not->toContain('E_VALIDATION');

        Livewire::test(InvoicePeppolPanel::class, ['invoice' => $this->invoice])
            ->assertSee($refused->supportText())
            ->assertDontSee('E_VALIDATION');

        $unreachable = new PeppolTransmission(['status' => TransmissionStatus::Failed]);
        $unreachable->forceFill(['last_error' => 'The access point did not answer.', 'refused' => false]);
        $undelivered = new PeppolTransmission(['status' => TransmissionStatus::Failed]);
        $undelivered->forceFill(['last_error' => 'The access point reported a failed delivery.', 'refused' => false, 'provider_reference' => 'x']);
        $waiting = new PeppolTransmission(['status' => TransmissionStatus::Queued]);
        $waiting->forceFill(['last_error' => 'HTTP 503']);

        expect($unreachable->supportText())->toContain('gick inte att nå')
            ->and($undelivered->supportText())->toContain('mottagarens')
            ->and($waiting->supportText())->toContain('försöker igen')
            ->and((new PeppolTransmission(['status' => TransmissionStatus::Sent]))->supportText())->toBeNull();
    });

    it('shows the participant\'s error from the access point in Swedish', function () {
        $this->participant->forceFill(['last_error' => 'Qvalia register failed with HTTP 500.'])->save();

        expect($this->participant->fresh()->supportText())->toContain('Peppol-operatören svarade med ett fel')
            ->and($this->participant->fresh()->supportText())->not->toContain('Qvalia');

        Livewire::test(PeppolSettingsForm::class, ['company' => $this->company])->assertDontSee('HTTP 500');

        $this->participant->forceFill(['last_error' => __('En mottagen e-faktura är för stor för att tas emot och ligger kvar hos Peppol-operatören. Kontakta den som driftar NONOX.')])->save();
        expect($this->participant->fresh()->supportText())->toContain('för stor');
    });

    it('logs a sending that failed with ids only', function () {
        Log::spy();
        LogAccessPoint::$failNext = 'refused';
        LogAccessPoint::$failRetryable = false;

        $transmission = app(SendInvoiceViaPeppol::class)($this->owner, $this->invoice);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Peppol')
            && $context === ['company_id' => $this->company->id, 'transmission_id' => $transmission->id, 'driver' => 'log', 'retryable' => false, 'attempts' => 1]);
    });
});
