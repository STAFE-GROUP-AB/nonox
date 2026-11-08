<?php

declare(strict_types=1);

use App\Domain\Company\LegalForm;
use App\Models\Account;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Modules\Inbox\Actions\AcceptUploadedReceipt;
use App\Modules\Inbox\Agents\DailyReportAgent;
use App\Modules\Inbox\Agents\InboxClassifierAgent;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\Domain\DocumentType;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\Livewire\InboxWorkspace;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Support\VirusScanner;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inbox;

/*
 * ADR-072 build step 7: Inkorg → Fota kvitto. A company receipt from the
 * phone becomes an inbox message of type receipt from the signed-in person,
 * passes the same file checks as a mail attachment, and nothing is booked
 * before a person approves it (KJ-R21).
 */

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-31 07:00:00'));
    [$this->owner, $this->company] = Inbox::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    InboxClassifierAgent::fake();
    DailyReportAgent::fake();
});

it('puts a photographed receipt in the inbox as a receipt from the person, with nothing booked', function () {
    Livewire::test(InboxWorkspace::class)
        ->assertSee('Fota kvitto')
        ->set('receiptPhoto', UploadedFile::fake()->image('parkering.jpg', 300, 500))
        ->call('uploadReceipt')
        ->assertDispatched('toast');

    $message = InboxMessage::query()->sole();

    expect($message->channel)->toBe(Channel::Upload)
        ->and($message->alias)->toBe('kvitto')
        ->and($message->from_email)->toBe($this->owner->email)
        ->and($message->status)->not->toBe(MessageStatus::Booked)
        ->and($message->document_type)->toBe(DocumentType::Receipt)
        ->and($message->attachments)->toHaveCount(1)
        ->and(Storage::disk('local')->exists($message->eml_path))->toBeTrue()
        ->and(Voucher::query()->count())->toBe(0);
});

it('refuses someone without inbox:manage, and a file that is not a receipt', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');

    expect(fn () => app(AcceptUploadedReceipt::class)($auditor, $this->company->fresh(), UploadedFile::fake()->image('k.jpg')))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AcceptUploadedReceipt::class)($this->owner, $this->company, UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')))->toThrow(ValidationException::class, 'bild eller en PDF')
        ->and(InboxMessage::query()->count())->toBe(0);
});

it('keeps a file the virus scan flags in quarantine, unread', function () {
    app()->instance(VirusScanner::class, new class implements VirusScanner
    {
        public function enabled(): bool
        {
            return true;
        }

        public function scan(string $contents): ?string
        {
            return 'Eicar-Test-Signature';
        }
    });

    $message = app(AcceptUploadedReceipt::class)($this->owner, $this->company, UploadedFile::fake()->image('kvitto.jpg'));

    expect($message->status)->toBe(MessageStatus::Quarantine)
        ->and($message->quarantine_reasons[0]['code'])->toBe('BLOCKED_ATTACHMENT')
        ->and($message->document_type)->toBeNull();
});

it('offers Betalt privat in an enskild firma, moving the bank credit to 2018', function () {
    $this->company->forceFill(['legal_form' => LegalForm::Ef])->save();
    Account::query()->firstOrCreate(['number' => 2018], ['name' => 'Övriga egna insättningar', 'type' => 'LIABILITY_EQUITY', 'is_active' => true]);
    $message = app(AcceptUploadedReceipt::class)($this->owner, $this->company, UploadedFile::fake()->image('k.jpg'));

    Livewire::withQueryParams(['id' => $message->id])->test(InboxWorkspace::class)
        ->set('form.kind', 'vouchers')
        ->set('form.vouchers.0.rows', [['account' => '5800', 'debit' => '120', 'credit' => '', 'description' => ''], ['account' => '1930', 'debit' => '', 'credit' => '120', 'description' => '']])
        ->call('paidPrivately')
        ->assertSet('form.vouchers.0.rows.1.account', '2018')
        ->assertSet('form.vouchers.0.rows.0.account', '5800')
        ->set('form.vouchers.0.rows', [['account' => '5800', 'debit' => '120', 'credit' => '', 'description' => ''], ['account' => '2440', 'debit' => '', 'credit' => '120', 'description' => '']])
        ->call('paidPrivately')
        ->assertDispatched('toast', message: 'Ingen betalning från ett bank- eller kassakonto att flytta. Ändra raderna själv om det behövs.', type: 'error');
});

it('books an approved photo with the photo itself as the only underlag, under its own name and type', function () {
    $message = app(AcceptUploadedReceipt::class)($this->owner, $this->company, UploadedFile::fake()->image('parkering.jpg', 300, 500));

    $component = Livewire::withQueryParams(['id' => $message->id])->test(InboxWorkspace::class)
        ->set('form.kind', 'vouchers')
        ->set('form.vouchers.0.date', '2026-10-30')
        ->set('form.vouchers.0.description', 'Parkering')
        ->set('form.vouchers.0.rows', [['account' => '4010', 'debit' => '120', 'credit' => '', 'description' => ''], ['account' => '1930', 'debit' => '', 'credit' => '120', 'description' => '']])
        ->set('warningsConfirmed', true)
        ->call('approve', 'post')
        ->call('approve', 'post')
        ->assertHasNoErrors();

    $voucher = Voucher::query()->where('status', 'POSTED')->sole();
    $attachments = VoucherAttachment::query()->where('voucher_id', $voucher->id)->get();

    expect($attachments)->toHaveCount(1)
        ->and($attachments->sole()->original_name)->toBe('parkering.jpg')
        ->and($attachments->sole()->mime)->toBe('image/jpeg')
        ->and($attachments->sole()->mime)->not->toBe('message/rfc822');
});
