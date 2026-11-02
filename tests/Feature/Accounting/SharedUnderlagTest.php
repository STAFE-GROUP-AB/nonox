<?php

declare(strict_types=1);

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\DeleteDraftVoucher;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\RemoveAttachment;
use App\Domain\Accounting\LedgerVerifier;
use App\Models\AuditLog;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Modules\Inbox\Actions\ApproveInboxMessage;
use App\Modules\Inbox\Actions\InboxReview;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\ModuleManager;
use App\Support\Files\StoredFiles;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\Inbox;

/*
 * AUD-BOK-1 (#41): one stored file can be the underlag of several rows (an
 * inbox mail and every voucher made from it). Removing one row never removes
 * a file another row still refers to.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

function sharedUnderlagPdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('kvitto.pdf', "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n");
}

/** @return array{0: InboxMessage, 1: list<Voucher>} */
function sharedUnderlagDrafts(object $test): array
{
    app(ModuleManager::class)->enable(InboxModule::KEY, $test->company, $test->owner);
    Inbox::allow($test->company, 'export@kassaexport.example');
    $message = Inbox::fresh(Inbox::receive($test->company, Inbox::fixture('sie.eml')));
    $approved = app(ApproveInboxMessage::class)($test->owner, $message, $message->proposal, ApproveInboxMessage::DRAFT);

    return [$approved, Voucher::query()->whereIn('id', $approved->voucher_ids)->orderBy('created_at')->get()->all()];
}

it('keeps the mail of a posted voucher when a sibling draft from the same mail is deleted', function () {
    [$message, [$first, $second]] = sharedUnderlagDrafts($this);

    app(PostVoucher::class)($this->owner, $first);
    app(DeleteDraftVoucher::class)($this->owner, $second);

    expect(Voucher::query()->whereKey($second->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($message->eml_path))->toBeTrue()
        ->and(app(LedgerVerifier::class)->verify($this->company)['problems'])->toBe([])
        ->and($message->fresh()->status)->toBe(MessageStatus::DraftCreated)
        ->and($message->fresh()->voucher_ids)->toBe([$first->id]);
});

it('keeps the inbox original when every draft from it is deleted, and opens the message again', function () {
    [$message, $drafts] = sharedUnderlagDrafts($this);

    foreach ($drafts as $draft) {
        app(DeleteDraftVoucher::class)($this->owner, $draft);
    }

    $message = $message->fresh();

    expect(Storage::disk('local')->exists($message->eml_path))->toBeTrue()
        ->and($message->status)->toBe(MessageStatus::NeedsReview)
        ->and($message->voucher_ids)->toBe([])
        ->and($message->handled_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'inbox.reopened')->count())->toBe(1);

    // Open again, it can be approved anew; its files are all still there.
    $again = app(ApproveInboxMessage::class)($this->owner, $message, $message->proposal, ApproveInboxMessage::DRAFT);

    expect($again->voucher_ids)->toHaveCount(count($drafts));
});

it('keeps the inbox original when its attachment is removed from a draft', function () {
    [$message, [$draft]] = sharedUnderlagDrafts($this);
    $eml = VoucherAttachment::query()->where('voucher_id', $draft->id)->where('mime', 'message/rfc822')->sole();

    app(RemoveAttachment::class)($this->owner, $eml);

    expect(VoucherAttachment::query()->whereKey($eml->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($message->eml_path))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'voucher.attachment_removed')->sole()->payload['file_kept'])->toBeTrue();
});

it('still removes a file that only the deleted draft used', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company);
    $attachment = app(AttachToVoucher::class)($this->owner, $draft, sharedUnderlagPdf());

    app(DeleteDraftVoucher::class)($this->owner, $draft);

    expect(Storage::disk('local')->exists($attachment->file_path))->toBeFalse();
});

it('still removes a file that only the removed attachment used', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company);
    $attachment = app(AttachToVoucher::class)($this->owner, $draft, sharedUnderlagPdf());

    app(RemoveAttachment::class)($this->owner, $attachment);

    expect(Storage::disk('local')->exists($attachment->file_path))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'voucher.attachment_removed')->sole()->payload['file_kept'])->toBeFalse();
});

it('deletes the inbox files with the message once no voucher refers to them, and keeps one a voucher does', function () {
    [$message, [$first, $second]] = sharedUnderlagDrafts($this);
    app(PostVoucher::class)($this->owner, $first);
    app(DeleteDraftVoucher::class)($this->owner, $second);

    // Another mail with nothing booked from it is removed for good.
    $rejected = Inbox::fresh(Inbox::receive($this->company, Inbox::mail('export@kassaexport.example', 'Fel underlag', 'Bortse från detta.')));
    app(InboxReview::class)->delete($this->owner, $rejected);

    expect(Storage::disk('local')->exists($rejected->eml_path))->toBeFalse()
        ->and(Storage::disk('local')->exists($message->eml_path))->toBeTrue();

    // Even if the message row went away, the posted voucher's copy of the reference keeps the file.
    DB::transaction(function () use ($message): void {
        CompanyScope::bypass(fn () => InboxMessage::query()->whereKey($message->id)->delete());
        StoredFiles::deleteUnreferenced([$message->eml_path]);
    });

    expect(Storage::disk('local')->exists($message->eml_path))->toBeTrue();
});

it('leaves the file in place when the removal is rolled back', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company);
    $attachment = app(AttachToVoucher::class)($this->owner, $draft, sharedUnderlagPdf());

    expect(fn () => DB::transaction(function () use ($attachment): void {
        $attachment->delete();
        StoredFiles::deleteUnreferenced([$attachment->file_path]);

        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class);

    expect(VoucherAttachment::query()->whereKey($attachment->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($attachment->file_path))->toBeTrue();
});

it('never removes a file another company refers to', function () {
    [$ownerB, $companyB] = Bookkeeping::company();
    $postedB = CurrentCompany::run($companyB, fn () => app(PostVoucher::class)($ownerB, Bookkeeping::draft($ownerB, $companyB)));
    $fileB = CurrentCompany::run($companyB, fn () => app(AttachToVoucher::class)($ownerB, $postedB, sharedUnderlagPdf()));

    CurrentCompany::set($this->company);
    $draftA = Bookkeeping::draft($this->owner, $this->company);

    // A row in company A that, by mistake or tampering, names company B's file.
    $rowA = VoucherAttachment::create([
        'voucher_id' => $draftA->id,
        'file_path' => $fileB->file_path,
        'original_name' => 'kvitto.pdf',
        'mime' => 'application/pdf',
        'size' => $fileB->size,
        'sha256' => $fileB->sha256,
        'uploaded_by' => $this->owner->id,
    ]);

    app(RemoveAttachment::class)($this->owner, $rowA);

    expect(Storage::disk('local')->exists($fileB->file_path))->toBeTrue();

    VoucherAttachment::create([...$rowA->only(['voucher_id', 'file_path', 'original_name', 'mime', 'size', 'sha256', 'uploaded_by'])]);
    app(DeleteDraftVoucher::class)($this->owner, $draftA);

    expect(Storage::disk('local')->exists($fileB->file_path))->toBeTrue()
        ->and(CurrentCompany::run($companyB, fn () => app(LedgerVerifier::class)->verify($companyB)['problems']))->toBe([]);
});

it('knows every column in the schema that holds a stored file path', function () {
    $columns = collect(DB::select("select table_name, column_name from information_schema.columns where table_schema = current_schema() and column_name like '%path%'"))
        ->map(fn (object $row): string => "{$row->table_name}.{$row->column_name}")
        ->reject(fn (string $column): bool => in_array($column, [
            // Profile photos live on the public disk, never linked as underlag.
            'users.profile_photo_path',
        ], true))
        ->sort()->values()->all();

    $known = collect(StoredFiles::REFERENCES)->map(fn (string $column, string $table): string => "{$table}.{$column}")->sort()->values()->all();

    expect($columns)->toBe($known);
});
