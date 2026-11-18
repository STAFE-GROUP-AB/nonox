<?php

declare(strict_types=1);

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Documents\AddDocumentVersion;
use App\Actions\Documents\AttachDocumentToVoucher;
use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\LinkDocument;
use App\Actions\Documents\SetLegalHold;
use App\Actions\Documents\UpdateDocument;
use App\Actions\Invoicing\SaveCustomer;
use App\Domain\Documents\DocumentRetention;
use App\Domain\Documents\DocumentSearch;
use App\Domain\Documents\LegalHoldReason;
use App\Domain\Documents\RetentionClass;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentTag;
use App\Models\DocumentVersion;
use App\Models\FiscalYear;
use App\Models\VoucherAttachment;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Support\Files\EncryptedAdapter;
use App\Support\Files\StoredFiles;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Documents\DocumentSupport;
use Tests\Support\Bookkeeping;

/*
 * ADR-081 gap 9, package E19: company documents with versions, tags, links,
 * retention classes and legal hold. Retention is guarded by the policy, the
 * actions, the model and the database.
 */

beforeEach(function () {
    $this->root = DocumentSupport::useTemporaryDisk();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

afterEach(fn () => DocumentSupport::removeTemporaryDisk($this->root));

it('stores the file encrypted at rest with the SHA-256 of what was uploaded', function () {
    $file = DocumentSupport::pdf('Försäkringsbrev.pdf', 'Företagsförsäkring för verksamheten');
    $bytes = (string) file_get_contents($file->getRealPath());

    $document = DocumentSupport::store($this->owner, $this->company, ['title' => 'Företagsförsäkring', 'tags' => 'försäkring, 2026, Försäkring'], $file);
    $version = DocumentVersion::query()->findOrFail($document->current_version_id);
    $raw = (string) file_get_contents("{$this->root}/{$version->path}");

    expect(EncryptedAdapter::isEncrypted($raw))->toBeTrue()
        ->and($raw)->not->toContain('%PDF')
        ->and(Storage::disk(StoredFiles::DISK)->get($version->path))->toBe($bytes)
        ->and($version->sha256)->toBe(hash('sha256', $bytes))
        ->and($version->version)->toBe(1)
        ->and($version->mime)->toBe('application/pdf')
        ->and($version->original_name)->toBe('Försäkringsbrev.pdf')
        ->and($version->extracted_text)->toContain('Företagsförsäkring')
        ->and($version->path)->toStartWith("dokument/{$this->company->id}/{$document->id}/")
        ->and(DocumentTag::query()->where('document_id', $document->id)->pluck('tag')->all())->toBe(['försäkring', '2026']);
});

it('writes only ids, enums and counts to the audit log, never the title or the tags', function () {
    $document = DocumentSupport::store($this->owner, $this->company, ['title' => 'Hemlig rubrik', 'tags' => 'hemligtagg']);

    $entry = AuditLog::query()->where('action', 'document.created')->where('subject_id', $document->id)->firstOrFail();
    $payload = (string) json_encode($entry->payload, JSON_UNESCAPED_UNICODE);

    expect($payload)->not->toContain('Hemlig rubrik')
        ->and($payload)->not->toContain('hemligtagg')
        ->and($entry->payload['retention_class'])->toBe('OTHER')
        ->and($entry->payload['tag_count'])->toBe(1);
});

it('refuses a file type that is not allowed, whatever its name says', function () {
    DocumentSupport::store($this->owner, $this->company, [], UploadedFile::fake()->createWithContent('avtal.pdf', '<?php echo 1;'));
})->throws(ValidationException::class, 'PDF, en bild');

it('requires an answer on personal data and a fiscal year for räkenskapsinformation', function () {
    expect(fn () => DocumentSupport::store($this->owner, $this->company, ['contains_personal_data' => '']))
        ->toThrow(ValidationException::class, 'personuppgifter')
        ->and(fn () => DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'ACCOUNTING']))
        ->toThrow(ValidationException::class, 'räkenskapsåret');
});

it('keeps räkenskapsinformation until seven years after the end of the calendar year the fiscal year ended (BFL 7 kap. 2 §)', function () {
    $year = DocumentSupport::fiscalYear($this->company);
    $document = DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'ACCOUNTING', 'fiscal_year_id' => $year->id]);

    expect($document->retain_until->toDateString())->toBe(($year->end_date->year + 7).'-12-31')
        ->and(DocumentRetention::blocker($document))->toContain('räkenskapsinformation')
        ->and(DocumentRetention::deletableFrom($document)->toDateString())->toBe(($year->end_date->year + 8).'-01-01');
});

it('computes a contract\'s retention from its end date and blocks a running contract', function () {
    $running = DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'CONTRACT', 'contract_retention_years' => '2']);
    $ended = DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'CONTRACT', 'contract_ends_on' => '2026-03-31', 'contract_retention_years' => '2']);

    expect($running->retain_until)->toBeNull()
        ->and(DocumentRetention::blocker($running))->toContain('löper tills vidare')
        ->and($ended->retain_until->toDateString())->toBe('2028-03-31');
});

it('lets a chosen date only lengthen the retention, never shorten it below the rule', function () {
    $year = DocumentSupport::fiscalYear($this->company);
    $document = DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'ACCOUNTING', 'fiscal_year_id' => $year->id, 'keep_until' => '2027-01-01']);

    expect($document->retain_until->toDateString())->toBe(($year->end_date->year + 7).'-12-31');

    $longer = DocumentSupport::store($this->owner, $this->company, ['keep_until' => '2040-06-30']);

    expect($longer->retain_until->toDateString())->toBe('2040-06-30');
});

it('refuses to reclassify räkenskapsinformation out of its retention period', function () {
    $year = DocumentSupport::fiscalYear($this->company);
    $document = DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'ACCOUNTING', 'fiscal_year_id' => $year->id]);

    app(UpdateDocument::class)($this->owner, $document, ['title' => 'Kvitto', 'category' => 'ACCOUNTING', 'retention_class' => 'OTHER', 'contains_personal_data' => '0']);
})->throws(ValidationException::class, 'kan inte sparas som något annat');

it('refuses deleting a kept document through the action, Eloquent and raw SQL', function () {
    $year = DocumentSupport::fiscalYear($this->company);
    $document = DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'ACCOUNTING', 'fiscal_year_id' => $year->id]);

    expect(fn () => app(DeleteDocument::class)($this->owner, $document))->toThrow(ValidationException::class, 'räkenskapsinformation')
        ->and(fn () => $document->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::delete('delete from documents where id = ?', [$document->id])))->toThrow(QueryException::class, 'The document is kept')
        ->and(fn () => DB::transaction(fn () => DB::delete('delete from document_versions where document_id = ?', [$document->id])))->toThrow(QueryException::class, 'The document is kept')
        ->and(fn () => DB::transaction(fn () => DB::update('update documents set retain_until = ? where id = ?', ['2026-12-31', $document->id])))->toThrow(QueryException::class, 'Räkenskapsinformation is kept')
        ->and(Document::query()->whereKey($document->id)->exists())->toBeTrue();
});

it('never changes a stored version, through Eloquent or raw SQL', function () {
    $document = DocumentSupport::store($this->owner, $this->company);
    $version = DocumentVersion::query()->findOrFail($document->current_version_id);

    expect(fn () => $version->forceFill(['sha256' => str_repeat('0', 64)])->save())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::update('update document_versions set sha256 = ? where id = ?', [str_repeat('0', 64), $version->id])))->toThrow(QueryException::class, 'append-only');
});

it('keeps the earlier versions when a new version is uploaded', function () {
    $document = DocumentSupport::store($this->owner, $this->company);
    $first = $document->current_version_id;

    $second = app(AddDocumentVersion::class)($this->owner, $document, DocumentSupport::pdf('avtal-v2.pdf', 'Andra versionen av avtalet'));

    expect($second->version)->toBe(2)
        ->and($document->fresh()->current_version_id)->toBe($second->id)
        ->and(DocumentVersion::query()->whereKey($first)->exists())->toBeTrue()
        ->and(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(2);
});

it('deletes an OTHER document with all versions and their files, and the search text with them', function () {
    $document = DocumentSupport::store($this->owner, $this->company);
    app(AddDocumentVersion::class)($this->owner, $document, DocumentSupport::pdf('v2.pdf', 'Andra versionen av avtalet'));
    $paths = DocumentVersion::query()->where('document_id', $document->id)->pluck('path')->all();

    app(DeleteDocument::class)($this->owner, $document);

    expect(Document::query()->whereKey($document->id)->exists())->toBeFalse()
        ->and(DocumentVersion::query()->where('document_id', $document->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'document.deleted')->where('subject_id', $document->id)->exists())->toBeTrue();

    foreach ($paths as $path) {
        expect(Storage::disk(StoredFiles::DISK)->exists($path))->toBeFalse();
    }
});

it('blocks deletion while a legal hold is set, and only documents:hold may set it', function () {
    $document = DocumentSupport::store($this->owner, $this->company);
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(fn () => app(SetLegalHold::class)($bookkeeper, $document, true, LegalHoldReason::Dispute))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetLegalHold::class)($this->owner, $document, true))->toThrow(ValidationException::class, 'Välj varför');

    app(SetLegalHold::class)($this->owner, $document, true, LegalHoldReason::Dispute);

    expect($document->fresh()->legal_hold)->toBeTrue()
        ->and($document->fresh()->legal_hold_reason)->toBe(LegalHoldReason::Dispute)
        ->and(AuditLog::query()->where('action', 'document.legal_hold_set')->where('subject_id', $document->id)->value('payload')['reason'])->toBe('TVIST')
        ->and(fn () => DB::transaction(fn () => DB::update('update documents set legal_hold_reason = null where id = ?', [$document->id])))->toThrow(QueryException::class)
        ->and(fn () => app(DeleteDocument::class)($this->owner, $document->fresh()))->toThrow(ValidationException::class, 'bevarandespärr')
        ->and(fn () => DB::transaction(fn () => DB::delete('delete from documents where id = ?', [$document->id])))->toThrow(QueryException::class, 'The document is kept');

    app(SetLegalHold::class)($this->owner, $document->fresh(), false);
    app(DeleteDocument::class)($this->owner, $document->fresh());

    expect(Document::query()->whereKey($document->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('subject_id', $document->id)->whereIn('action', ['document.legal_hold_set', 'document.legal_hold_lifted'])->count())->toBe(2);
});

it('gives the roles of the proposal: owner, admin, accountant all; bookkeeper view and manage; auditor view; approver none', function (string $role, bool $view, bool $manage, bool $hold) {
    $user = Bookkeeping::member($this->company, $role);

    expect($user->hasTeamPermission($this->company, 'documents:view'))->toBe($view)
        ->and($user->hasTeamPermission($this->company, 'documents:manage'))->toBe($manage)
        ->and($user->hasTeamPermission($this->company, 'documents:hold'))->toBe($hold);
})->with([
    'admin' => ['admin', true, true, true],
    'accountant' => ['accountant', true, true, true],
    'bookkeeper' => ['bookkeeper', true, true, false],
    'auditor' => ['auditor', true, false, false],
    'approver' => ['approver', false, false, false],
]);

it('refuses an upload by the auditor', function () {
    DocumentSupport::store(Bookkeeping::member($this->company, 'auditor'), $this->company);
})->throws(AuthorizationException::class);

it('shows personnel documents only to someone with payroll:view-sensitive (owner decision 21)', function () {
    app(ModuleManager::class)->enable(PayrollModule::KEY, $this->company, $this->owner);
    $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');

    expect(fn () => DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'PERSONNEL']))
        ->toThrow(ValidationException::class, 'känsliga löneuppgifter');

    $document = DocumentSupport::store($payrollAdmin, $this->company, ['title' => 'Anställningsavtal', 'retention_class' => 'PERSONNEL', 'contains_personal_data' => '1'], DocumentSupport::pdf('anstallningsavtal.pdf', 'Anställningsavtal för en anställd'));

    expect(Document::query()->visibleTo($this->owner, $this->company)->pluck('id')->all())->toBe([])
        ->and(Document::query()->visibleTo($payrollAdmin, $this->company)->pluck('id')->all())->toBe([$document->id])
        ->and(DocumentVersion::query()->findOrFail($document->current_version_id)->extracted_text)->toBeNull();

    $this->get(route('documents.show', $document))->assertNotFound();
    $this->get(route('documents.file', [$document, $document->current_version_id]))->assertNotFound();

    $this->actingAs($payrollAdmin)->get(route('documents.show', $document))->assertOk()->assertSee('Personal');
    $this->get(route('documents.index'))->assertOk()->assertSee('Anställningsavtal');

    // The payroll administrator files personnel documents only; the rest of the archive is not theirs.
    expect(fn () => DocumentSupport::store($payrollAdmin, $this->company, ['retention_class' => 'OTHER']))
        ->toThrow(ValidationException::class, 'bara spara personaldokument');
});

it('links a document to a record of the company and refuses an unknown type', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kunden Exempel AB', 'payment_terms_days' => 30]);
    $document = DocumentSupport::store($this->owner, $this->company);

    $link = app(LinkDocument::class)($this->owner, $document, 'customer', $customer->id);
    app(LinkDocument::class)($this->owner, $document, 'customer', $customer->id);

    expect($link->linkable_id)->toBe($customer->id)
        ->and($document->links()->count())->toBe(1)
        ->and(fn () => app(LinkDocument::class)($this->owner, $document, 'voucher', 1))->toThrow(ValidationException::class)
        ->and(fn () => app(LinkDocument::class)($this->owner, $document, 'employee', 1))->toThrow(ValidationException::class);

    app(LinkDocument::class)->remove($this->owner, $document, $link->id);

    expect($document->links()->count())->toBe(0);
});

it('makes a räkenskapsinformation document underlag through voucher_attachments with the same file', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company);
    $document = DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'ACCOUNTING', 'fiscal_year_id' => $voucher->fiscal_year_id]);
    $version = DocumentVersion::query()->findOrFail($document->current_version_id);

    $attachment = app(AttachDocumentToVoucher::class)($this->owner, $document, $voucher->reference());

    expect($attachment)->toBeInstanceOf(VoucherAttachment::class)
        ->and($attachment->file_path)->toBe($version->path)
        ->and($attachment->sha256)->toBe($version->sha256)
        ->and(fn () => app(AttachDocumentToVoucher::class)($this->owner, $document, $voucher->reference()))->toThrow(ValidationException::class, 'redan underlag');

    $other = DocumentSupport::store($this->owner, $this->company);

    expect(fn () => app(AttachDocumentToVoucher::class)($this->owner, $other, $voucher->reference()))->toThrow(ValidationException::class, 'Bara räkenskapsinformation');
});

it('keeps a file that a voucher still refers to when the document is deleted', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company);
    $document = DocumentSupport::store($this->owner, $this->company);
    $version = DocumentVersion::query()->findOrFail($document->current_version_id);
    app(AttachToVoucher::class)->linkStoredFile($this->owner, $voucher, $version->path, $version->original_name, $version->mime, $version->size, $version->sha256);

    app(DeleteDocument::class)($this->owner, $document->fresh());

    expect(Document::query()->whereKey($document->id)->exists())->toBeFalse()
        ->and(Storage::disk(StoredFiles::DISK)->exists($version->path))->toBeTrue();
});

it('finds documents by a word in the title, a tag or the PDF text', function () {
    $lease = DocumentSupport::store($this->owner, $this->company, ['title' => 'Hyresavtal kontor', 'tags' => 'lokal'], DocumentSupport::pdf('hyra.pdf', 'Hyresvärden är Fastighetsbolaget Exempel AB'));
    $insurance = DocumentSupport::store($this->owner, $this->company, ['title' => 'Försäkringsbrev', 'tags' => 'skydd'], DocumentSupport::pdf('f.pdf', 'Ansvarsförsäkring för verksamheten'));

    $found = fn (string $text): array => DocumentSearch::apply(Document::query(), $text)->orderBy('id')->pluck('id')->all();

    expect($found('hyresavtal'))->toBe([$lease->id])
        ->and($found('lok'))->toBe([$lease->id])
        ->and($found('fastighetsbolaget'))->toBe([$lease->id])
        ->and($found('ansvarsförsäkring'))->toBe([$insurance->id])
        ->and($found('saknas helt'))->toBe([]);
});

it('lets a chosen date be lowered down to the rule, never below it, and logs both dates (GDPR art. 5.1 e)', function () {
    $other = DocumentSupport::store($this->owner, $this->company, ['keep_until' => '2035-06-30']);
    $lowered = app(UpdateDocument::class)($this->owner, $other, ['title' => 'Hyresavtal lokal', 'category' => 'AGREEMENT', 'retention_class' => 'OTHER', 'keep_until' => '2027-01-31', 'contains_personal_data' => '0']);

    expect($lowered->retain_until->toDateString())->toBe('2027-01-31');

    $entry = AuditLog::query()->where('action', 'document.updated')->where('subject_id', $other->id)->latest('id')->firstOrFail();

    expect($entry->payload['retain_until_before'])->toBe('2035-06-30')
        ->and($entry->payload['retain_until'])->toBe('2027-01-31');

    $year = DocumentSupport::fiscalYear($this->company);
    $floor = ($year->end_date->year + 7).'-12-31';
    $accounting = DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'ACCOUNTING', 'fiscal_year_id' => $year->id, 'keep_until' => '2040-12-31']);
    $back = app(UpdateDocument::class)($this->owner, $accounting, ['title' => 'Kvitto', 'category' => 'ACCOUNTING', 'retention_class' => 'ACCOUNTING', 'fiscal_year_id' => $year->id, 'keep_until' => '', 'contains_personal_data' => '0']);

    expect($back->retain_until->toDateString())->toBe($floor)
        ->and(DB::transaction(fn () => DB::update('update documents set retain_until = ? where id = ?', ['2036-01-01', $accounting->id])))->toBe(1)
        ->and(fn () => DB::transaction(fn () => DB::update('update documents set retain_until = ? where id = ?', ['2030-01-01', $accounting->id])))->toThrow(QueryException::class, 'Räkenskapsinformation is kept');
});

it('caps a chosen date at thirty years after the rule', function () {
    $year = DocumentSupport::fiscalYear($this->company);

    expect(fn () => DocumentSupport::store($this->owner, $this->company, ['keep_until' => now()->addYears(31)->toDateString()]))
        ->toThrow(ValidationException::class, 'högst 30 år')
        ->and(DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'ACCOUNTING', 'fiscal_year_id' => $year->id, 'keep_until' => ($year->end_date->year + 37).'-12-31'])->retain_until->year)->toBe($year->end_date->year + 37)
        ->and(fn () => DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'ACCOUNTING', 'fiscal_year_id' => $year->id, 'keep_until' => ($year->end_date->year + 38).'-01-01']))->toThrow(ValidationException::class, 'högst 30 år');
});

it('keeps a contract marked as räkenskapsinformation for seven years after its fiscal year', function () {
    $year = DocumentSupport::fiscalYear($this->company);

    expect(fn () => DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'CONTRACT', 'is_accounting_record' => '1']))
        ->toThrow(ValidationException::class, 'räkenskapsåret');

    $lease = DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'CONTRACT', 'is_accounting_record' => '1', 'fiscal_year_id' => $year->id, 'contract_ends_on' => '2027-06-30', 'contract_retention_years' => '1']);
    $plain = DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'CONTRACT', 'fiscal_year_id' => $year->id, 'contract_ends_on' => '2027-06-30', 'contract_retention_years' => '1']);

    expect($lease->is_accounting_record)->toBeTrue()
        ->and($lease->retain_until->toDateString())->toBe(($year->end_date->year + 7).'-12-31')
        ->and($plain->fiscal_year_id)->toBeNull()
        ->and($plain->retain_until->toDateString())->toBe('2028-06-30')
        ->and(fn () => app(UpdateDocument::class)($this->owner, $lease, ['title' => 'Leasing', 'category' => 'AGREEMENT', 'retention_class' => 'CONTRACT', 'contract_ends_on' => '2027-06-30', 'contract_retention_years' => '1', 'contains_personal_data' => '0']))
        ->toThrow(ValidationException::class, 'kan inte sparas som något annat');
});

it('never moves a document into or out of the personnel class, and keeps a running contract a contract', function () {
    app(ModuleManager::class)->enable(PayrollModule::KEY, $this->company, $this->owner);
    $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');
    $personnel = DocumentSupport::store($payrollAdmin, $this->company, ['title' => 'Intyg', 'retention_class' => 'PERSONNEL', 'contains_personal_data' => '1']);
    $running = DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'CONTRACT']);

    expect(fn () => app(UpdateDocument::class)($payrollAdmin, $personnel, ['title' => 'Intyg', 'category' => 'OTHER', 'retention_class' => 'OTHER', 'contains_personal_data' => '1']))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateDocument::class)($this->owner, $running, ['title' => 'Avtal', 'category' => 'OTHER', 'retention_class' => 'OTHER', 'contains_personal_data' => '0']))
        ->toThrow(ValidationException::class, 'löper tills vidare');

    $other = DocumentSupport::store($this->owner, $this->company);

    expect(fn () => app(UpdateDocument::class)($this->owner, $other, ['title' => 'X', 'category' => 'OTHER', 'retention_class' => 'PERSONNEL', 'contains_personal_data' => '1']))
        ->toThrow(ValidationException::class);
});

it('stores a personnel document\'s title encrypted', function () {
    app(ModuleManager::class)->enable(PayrollModule::KEY, $this->company, $this->owner);
    $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');
    $document = DocumentSupport::store($payrollAdmin, $this->company, ['title' => 'Läkarintyg för Testperson', 'retention_class' => 'PERSONNEL', 'contains_personal_data' => '1']);
    $raw = DB::table('documents')->where('id', $document->id)->first();

    expect($raw->title)->toBe('')
        ->and((string) $raw->title_encrypted)->not->toContain('Läkarintyg')
        ->and(Document::query()->findOrFail($document->id)->title)->toBe('Läkarintyg för Testperson');
});

it('gives the owner a logged path to personnel documents while payroll is off', function () {
    $modules = app(ModuleManager::class);
    $modules->enable(PayrollModule::KEY, $this->company, $this->owner);
    $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');
    $document = DocumentSupport::store($payrollAdmin, $this->company, ['title' => 'Anställningsavtal', 'retention_class' => 'PERSONNEL', 'contains_personal_data' => '1']);

    $this->get(route('documents.show', $document))->assertNotFound();

    $modules->disable(PayrollModule::KEY, $this->company, $this->owner, true);

    $this->get(route('documents.show', $document))->assertOk()->assertSee('Anställningsavtal');
    $this->get(route('documents.file', [$document, $document->current_version_id]))->assertOk();

    expect(AuditLog::query()->where('action', 'document.personnel_owner_access')->where('subject_id', $document->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'document.file_opened')->where('subject_id', $document->id)->value('payload')['owner_fallback'])->toBeTrue()
        ->and(fn () => DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'PERSONNEL']))->toThrow(ValidationException::class);

    app(DeleteDocument::class)($this->owner, $document->fresh());

    expect(Document::query()->whereKey($document->id)->exists())->toBeFalse();
});

it('refuses the two-step escape from the floor of räkenskapsinformation, in raw SQL', function () {
    $year = DocumentSupport::fiscalYear($this->company);
    $old = FiscalYear::create(['start_date' => '2016-01-01', 'end_date' => '2016-12-31']);
    $document = DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'ACCOUNTING', 'fiscal_year_id' => $year->id]);
    $sql = fn (string $statement, array $bindings = []) => fn () => DB::transaction(fn () => DB::update($statement, $bindings));

    expect($sql("update documents set retention_class = 'OTHER' where id = ?", [$document->id]))->toThrow(QueryException::class, 'never moves below its floor')
        ->and($sql('update documents set fiscal_year_id = ? where id = ?', [$old->id, $document->id]))->toThrow(QueryException::class, 'never moves below its floor')
        ->and($sql('update documents set fiscal_year_id = null where id = ?', [$document->id]))->toThrow(QueryException::class)
        ->and(Document::query()->findOrFail($document->id)->retention_class)->toBe(RetentionClass::Accounting);

    $contract = DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'CONTRACT', 'is_accounting_record' => '1', 'fiscal_year_id' => $year->id, 'contract_ends_on' => '2027-01-31']);

    expect($sql('update documents set is_accounting_record = false where id = ?', [$contract->id]))->toThrow(QueryException::class, 'never moves below its floor');
});

it('refuses the two-step escape from the floor of räkenskapsinformation, through the app', function () {
    $year = DocumentSupport::fiscalYear($this->company);
    $old = FiscalYear::create(['start_date' => '2016-01-01', 'end_date' => '2016-12-31']);
    $document = DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'ACCOUNTING', 'fiscal_year_id' => $year->id]);
    $input = ['title' => 'Kvitto', 'category' => 'ACCOUNTING', 'contains_personal_data' => '0'];

    expect(fn () => app(UpdateDocument::class)($this->owner, $document, [...$input, 'retention_class' => 'OTHER', 'keep_until' => $document->retain_until->toDateString()]))
        ->toThrow(ValidationException::class, 'kan inte sparas som något annat')
        ->and(fn () => app(UpdateDocument::class)($this->owner, $document, [...$input, 'retention_class' => 'ACCOUNTING', 'fiscal_year_id' => $old->id, 'keep_until' => $document->retain_until->toDateString()]))
        ->toThrow(ValidationException::class, 'tidigare räkenskapsår')
        ->and(fn () => $document->fresh()->forceFill(['retention_class' => 'OTHER'])->save())
        ->toThrow(LogicException::class, 'keeps its floor');

    $contract = DocumentSupport::store($this->owner, $this->company, ['retention_class' => 'CONTRACT', 'is_accounting_record' => '1', 'fiscal_year_id' => $year->id, 'contract_ends_on' => '2027-01-31']);

    expect(fn () => app(UpdateDocument::class)($this->owner, $contract, [...$input, 'retention_class' => 'CONTRACT', 'contract_ends_on' => '2027-01-31', 'keep_until' => $contract->retain_until->toDateString()]))
        ->toThrow(ValidationException::class, 'kan inte sparas som något annat')
        ->and(Document::query()->findOrFail($document->id)->retention_class)->toBe(RetentionClass::Accounting);
});
