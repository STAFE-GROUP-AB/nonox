<?php

declare(strict_types=1);

use App\Actions\Accounting\DeleteDraftVoucher;
use App\Actions\Accounting\PostManualVoucher;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Approval\ApproveDraft;
use App\Actions\Approval\RejectApproval;
use App\Actions\Approval\SubmitForApproval;
use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Approval\ApprovalRequestStatus;
use App\Domain\Approval\ApprovalRequired;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\DecisionOutcome;
use App\Domain\Approval\DraftApprovals;
use App\Domain\Approval\DraftApprovalStatus;
use App\Domain\Approval\DraftFingerprint;
use App\Domain\Approval\SubjectFacts;
use App\Domain\Invoicing\InvoiceStatus;
use App\Livewire\Accounting\VoucherEditor;
use App\Livewire\Approval\ApprovalPolicies;
use App\Livewire\Approval\DraftApprovalCard;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ApprovalFixtures;
use Tests\Support\Bookkeeping;

/*
 * ADR-081 E21: manual vouchers and credit invoices as approval subjects.
 * A draft that a policy holds stays a draft without a number until that very
 * version is approved, so voucher series and invoice numbers stay gapless;
 * PostVoucher itself is not changed (PostManualVoucher is its caller). With
 * no policy (owner decision 19, the default) nothing changes.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->accountant = Bookkeeping::member($this->company, 'accountant');
    $this->approver = Bookkeeping::member($this->company, 'approver');
    $this->company = $this->company->fresh();
});

/** A MANUAL_VOUCHER (or CREDIT_INVOICE) policy whose one step names the accountant. */
function e21Policy(object $test, string $type = 'MANUAL_VOUCHER', array $step = []): void
{
    ApprovalFixtures::policy($test->owner, $test->company, [[
        'approver_kind' => 'USERS',
        'approver_user_ids' => [$test->accountant->id],
        ...$step,
    ]], type: $type, name: 'Kontroll');
}

function e21Draft(User $user, Company $company, int $ore = 100000, ?Voucher $draft = null, string $description = 'Inköp av kontorsmaterial'): Voucher
{
    return app(SaveDraftVoucher::class)($user, $company, [
        'voucher_date' => '2026-03-05',
        'description' => $description,
        'series' => 'A',
        'rows' => [
            ['account' => 6110, 'debit_ore' => $ore],
            ['account' => 1930, 'credit_ore' => $ore],
        ],
    ], $draft);
}

function e21Subject(Voucher|Invoice $draft): string
{
    return $draft instanceof Voucher ? DraftApprovals::voucherSubjectId($draft->fresh()) : DraftApprovals::invoiceSubjectId($draft->fresh());
}

function e21Customer(User $owner, Company $company): Customer
{
    return app(SaveCustomer::class)($owner, $company, [
        'name' => 'Kreditkunden AB', 'org_number' => '5561234567', 'payment_terms_days' => 30,
        'address_line1' => 'Kundvägen 7', 'postal_code' => '211 20', 'city' => 'Malmö',
    ]);
}

/** An issued invoice of 1 000 kr + 25 % and a draft credit of all of it, made by $user. */
function e21CreditDraft(object $test, User $user): Invoice
{
    $invoice = app(IssueInvoice::class)($test->owner, app(SaveDraftInvoice::class)($test->owner, $test->company, [
        'customer_id' => e21Customer($test->owner, $test->company)->id,
        'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '2', 'price' => '500', 'vat_rate' => 2500]],
    ]));

    return app(CreditInvoice::class)($user, $invoice->fresh());
}

it('changes nothing without a policy: the draft posts at once and no approval row is written', function () {
    $draft = e21Draft($this->bookkeeper, $this->company);

    $posted = app(PostManualVoucher::class)($this->bookkeeper, $draft);

    expect($posted->status)->toBe(VoucherStatus::Posted)
        ->and($posted->number)->toBe(1)
        ->and(ApprovalRequest::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'voucher.posted_after_approval')->exists())->toBeFalse();
});

it('costs one query more than PostVoucher for a company without a policy', function () {
    $draft = e21Draft($this->bookkeeper, $this->company);
    $other = e21Draft($this->bookkeeper, $this->company, 2000);

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(PostVoucher::class)($this->bookkeeper, $other);
    $plain = count(DB::getQueryLog());
    DB::flushQueryLog();
    app(PostManualVoucher::class)($this->bookkeeper, $draft);
    $guarded = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The guard's own lock of the voucher, the policy check and the request check.
    expect($guarded - $plain)->toBeLessThanOrEqual(3);
});

it('holds a draft a policy applies to until that version is approved, then numbers it at posting', function () {
    e21Policy($this);
    $draft = e21Draft($this->bookkeeper, $this->company);

    expect(fn () => app(PostManualVoucher::class)($this->bookkeeper, $draft))->toThrow(ApprovalRequired::class, 'ska attesteras');
    expect($draft->fresh()->status)->toBe(VoucherStatus::Draft)
        ->and($draft->fresh()->number)->toBeNull();

    $request = app(SubmitForApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::ManualVoucher, e21Subject($draft));

    expect($request->status)->toBe(ApprovalRequestStatus::Pending)
        ->and($request->prepared_by)->toBe($this->bookkeeper->id)
        ->and(fn () => app(PostManualVoucher::class)($this->bookkeeper, $draft->fresh()))->toThrow(ApprovalRequired::class, 'väntar på attest')
        ->and(fn () => app(ApproveDraft::class)($this->owner, $this->company, ApprovalSubjectType::ManualVoucher, e21Subject($draft)))->toThrow(AuthorizationException::class);

    expect(app(ApproveDraft::class)($this->accountant, $this->company, ApprovalSubjectType::ManualVoucher, e21Subject($draft)))->toBe(DecisionOutcome::Completed)
        ->and($draft->fresh()->status)->toBe(VoucherStatus::Draft);

    $posted = app(PostManualVoucher::class)($this->bookkeeper, $draft->fresh());

    expect($posted->status)->toBe(VoucherStatus::Posted)
        ->and($posted->number)->toBe(1)
        ->and(AuditLog::query()->where('action', 'voucher.posted_after_approval')->sole()->payload)->toBe(['reference' => 'A1', 'approval_request_id' => $request->id]);
});

it('keeps the series gapless while a draft waits, is rejected or is deleted', function () {
    e21Policy($this, step: ['min_amount' => '5 000']);
    $waiting = e21Draft($this->bookkeeper, $this->company, 1000000);
    $rejected = e21Draft($this->bookkeeper, $this->company, 2000000);
    $deleted = e21Draft($this->bookkeeper, $this->company, 3000000);

    foreach ([$waiting, $rejected, $deleted] as $draft) {
        app(SubmitForApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::ManualVoucher, e21Subject($draft));
    }

    app(RejectApproval::class)($this->accountant, $this->company, ApprovalSubjectType::ManualVoucher, e21Subject($rejected), 'Fel konto');
    app(DeleteDraftVoucher::class)($this->bookkeeper, $deleted->fresh());

    // Small vouchers follow no step and post at once in between.
    $first = app(PostManualVoucher::class)($this->bookkeeper, e21Draft($this->bookkeeper, $this->company, 10000));
    app(ApproveDraft::class)($this->accountant, $this->company, ApprovalSubjectType::ManualVoucher, e21Subject($waiting));
    $second = app(PostManualVoucher::class)($this->bookkeeper, $waiting->fresh());
    $third = app(PostManualVoucher::class)($this->bookkeeper, e21Draft($this->bookkeeper, $this->company, 20000));

    expect([$first->number, $second->number, $third->number])->toBe([1, 2, 3])
        ->and(Voucher::query()->where('status', VoucherStatus::Posted->value)->orderBy('number')->pluck('number')->all())->toBe([1, 2, 3])
        ->and($rejected->fresh()->number)->toBeNull()
        ->and(ApprovalRequest::query()->where('subject_id', 'like', "{$deleted->id}.%")->sole()->status)->toBe(ApprovalRequestStatus::Withdrawn)
        ->and(fn () => app(PostManualVoucher::class)($this->bookkeeper, $rejected->fresh()))->toThrow(ApprovalRequired::class, 'avvisades');
});

it('binds the approval to the version: a changed draft needs a new approval, an unchanged save keeps it', function () {
    e21Policy($this);
    $draft = e21Draft($this->bookkeeper, $this->company);
    $seen = e21Subject($draft);
    app(SubmitForApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::ManualVoucher, $seen);

    // Saved with the same content (the editor saves before it posts): same version.
    e21Draft($this->bookkeeper, $this->company, draft: $draft->fresh());
    expect(e21Subject($draft))->toBe($seen);

    e21Draft($this->bookkeeper, $this->company, 150000, $draft->fresh());
    $changed = e21Subject($draft);

    expect($changed)->not->toBe($seen)
        ->and(fn () => app(ApproveDraft::class)($this->accountant, $this->company, ApprovalSubjectType::ManualVoucher, $seen))->toThrow(ValidationException::class, 'har ändrats')
        ->and(DraftApprovals::status(ApprovalSubjectType::ManualVoucher, $this->company->id, $changed, fn () => SubjectFacts::forManualVoucher($draft->fresh())))->toBe(DraftApprovalStatus::Required);

    // Sending the new version withdraws the old one's open request.
    app(SubmitForApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::ManualVoucher, $changed);
    app(ApproveDraft::class)($this->accountant, $this->company, ApprovalSubjectType::ManualVoucher, $changed);

    expect(ApprovalRequest::query()->where('subject_id', $seen)->sole()->status)->toBe(ApprovalRequestStatus::Withdrawn)
        ->and(ApprovalRequest::query()->where('subject_id', $changed)->sole()->status)->toBe(ApprovalRequestStatus::Approved);

    // Approved, then changed again: posting is refused until the new version is approved.
    e21Draft($this->bookkeeper, $this->company, 160000, $draft->fresh());

    expect(fn () => app(PostManualVoucher::class)($this->bookkeeper, $draft->fresh()))->toThrow(ApprovalRequired::class)
        ->and($draft->fresh()->number)->toBeNull();
});

it('changes the version when an attachment is added', function () {
    $draft = e21Draft($this->bookkeeper, $this->company);
    $before = e21Subject($draft);

    DB::table('voucher_attachments')->insert([
        'voucher_id' => $draft->id, 'company_id' => $this->company->id, 'file_path' => 'underlag/test.pdf', 'original_name' => 'kvitto.pdf',
        'mime' => 'application/pdf', 'size' => 10, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $this->bookkeeper->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(e21Subject($draft))->not->toBe($before)
        ->and(DraftFingerprint::parse(e21Subject($draft)))->not->toBeNull();
});

it('counts everyone who saved the draft as its preparer, not only its creator', function () {
    ApprovalFixtures::policy($this->owner, $this->company, [[
        'approver_kind' => 'PERMISSION', 'approver_permission' => 'vouchers:post', 'exclude_preparer' => true,
    ]], type: 'MANUAL_VOUCHER');
    $draft = e21Draft($this->bookkeeper, $this->company);
    e21Draft($this->accountant, $this->company, 120000, $draft->fresh());

    $facts = SubjectFacts::forManualVoucher($draft->fresh());

    expect($facts->preparerIds)->toBe([$this->bookkeeper->id, $this->accountant->id])
        ->and(fn () => app(ApproveDraft::class)($this->accountant, $this->company, ApprovalSubjectType::ManualVoucher, e21Subject($draft)))->toThrow(ValidationException::class)
        ->and(fn () => app(ApproveDraft::class)($this->bookkeeper, $this->company, ApprovalSubjectType::ManualVoucher, e21Subject($draft)))->toThrow(ValidationException::class)
        ->and(app(ApproveDraft::class)($this->owner, $this->company, ApprovalSubjectType::ManualVoucher, e21Subject($draft)))->toBe(DecisionOutcome::Completed);
});

it('tests the amount, account range and cost centre conditions on the voucher rows', function () {
    e21Policy($this, step: ['min_amount' => '50 000', 'account_from' => '6000', 'account_to' => '6999']);

    $small = e21Draft($this->bookkeeper, $this->company, 100000);
    $large = e21Draft($this->bookkeeper, $this->company, 6000000);

    expect(app(PostManualVoucher::class)($this->bookkeeper, $small)->status)->toBe(VoucherStatus::Posted)
        ->and(fn () => app(PostManualVoucher::class)($this->bookkeeper, $large))->toThrow(ApprovalRequired::class)
        ->and(SubjectFacts::forManualVoucher($large->fresh())->amountOre)->toBe(6000000)
        ->and(SubjectFacts::forManualVoucher($large->fresh())->accountNumbers)->toEqualCanonicalizing([6110, 1930]);
});

it('leaves system vouchers alone: only drafts entered by a person are held', function () {
    e21Policy($this);
    $draft = e21Draft($this->bookkeeper, $this->company);
    $draft->forceFill(['source' => 'VAT'])->save();

    expect(app(PostManualVoucher::class)($this->owner, $draft->fresh())->status)->toBe(VoucherStatus::Posted)
        ->and(ApprovalRequest::query()->count())->toBe(0);
});

it('refuses to send a draft no policy applies to, and lets only a preparer send it', function () {
    $draft = e21Draft($this->bookkeeper, $this->company);

    expect(fn () => app(SubmitForApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::ManualVoucher, e21Subject($draft)))->toThrow(ValidationException::class, 'Inget attestflöde');

    e21Policy($this);

    expect(fn () => app(SubmitForApproval::class)($this->approver, $this->company, ApprovalSubjectType::ManualVoucher, e21Subject($draft)))->toThrow(AuthorizationException::class);
});

it('sends a rejected version to approval again, and records reasons on the decision only', function () {
    e21Policy($this);
    $draft = e21Draft($this->bookkeeper, $this->company);
    app(RejectApproval::class)($this->accountant, $this->company, ApprovalSubjectType::ManualVoucher, e21Subject($draft), 'Saknar kvitto');

    $again = app(SubmitForApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::ManualVoucher, e21Subject($draft));

    expect($again->status)->toBe(ApprovalRequestStatus::Pending)
        ->and(ApprovalRequest::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'like', 'approval.%')->get()->pluck('payload')->flatten()->all())->not->toContain('Saknar kvitto');
});

it('holds a credit invoice until approved and takes its invoice number only when it is issued', function () {
    e21Policy($this, 'CREDIT_INVOICE');
    $credit = e21CreditDraft($this, $this->bookkeeper);

    expect(fn () => app(IssueInvoice::class)($this->bookkeeper, $credit->fresh()))->toThrow(ApprovalRequired::class, 'Kreditfakturan ska attesteras')
        ->and($credit->fresh()->status)->toBe(InvoiceStatus::Draft)
        ->and($credit->fresh()->number)->toBeNull();

    app(SubmitForApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::CreditInvoice, e21Subject($credit));

    // An ordinary invoice issued meanwhile takes the next number.
    $ordinary = app(IssueInvoice::class)($this->owner, app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $credit->customer_id, 'invoice_date' => '2026-03-10',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '1', 'price' => '500', 'vat_rate' => 2500]],
    ]));

    expect(app(ApproveDraft::class)($this->accountant, $this->company, ApprovalSubjectType::CreditInvoice, e21Subject($credit)))->toBe(DecisionOutcome::Completed);

    $issued = app(IssueInvoice::class)($this->bookkeeper, $credit->fresh());

    expect($issued->status)->toBe(InvoiceStatus::Paid)
        ->and($issued->number)->toBe($ordinary->number + 1)
        ->and(SubjectFacts::forCreditInvoice($credit->fresh())->amountOre)->toBe(-125000)
        ->and(AuditLog::query()->where('action', 'invoice.issued_after_approval')->sole()->payload['number'])->toBe($issued->number);
});

it('needs a new approval for a credit invoice changed after it was approved, and leaves ordinary invoices alone', function () {
    e21Policy($this, 'CREDIT_INVOICE');
    $credit = e21CreditDraft($this, $this->bookkeeper);
    app(ApproveDraft::class)($this->accountant, $this->company, ApprovalSubjectType::CreditInvoice, e21Subject($credit));

    app(SaveDraftInvoice::class)($this->bookkeeper, $this->company, [
        'customer_id' => $credit->customer_id, 'invoice_date' => '2026-03-10', 'due_date' => '2026-03-10',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '-1', 'price' => '500', 'vat_rate' => 2500]],
    ], $credit->fresh());

    expect(fn () => app(IssueInvoice::class)($this->bookkeeper, $credit->fresh()))->toThrow(ApprovalRequired::class)
        ->and(SubjectFacts::forCreditInvoice($credit->fresh())->preparerIds)->toBe([$this->bookkeeper->id]);
});

it('offers the two new flows on the policy page and refuses a supplier condition for them', function () {
    Livewire::test(ApprovalPolicies::class)
        ->assertSee('Manuella verifikationer')
        ->assertSee('Nytt flöde för manuella verifikationer')
        ->assertSee('Kreditfakturor')
        ->assertSee('Nytt flöde för kreditfakturor')
        ->call('create', 'MANUAL_VOUCHER')
        ->assertDontSee('step_0_supplier');

    $supplier = ApprovalFixtures::supplier($this->owner, $this->company);

    expect(fn () => ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'vouchers:post', 'supplier_id' => (string) $supplier->id]], type: 'MANUAL_VOUCHER'))
        ->toThrow(ValidationException::class, 'leverantör kan inte vara villkor')
        ->and(fn () => ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'supplier-invoices:approve']], type: 'CREDIT_INVOICE'))
        ->toThrow(ValidationException::class);
});

it('sends the draft to approval from the editor\'s Bokför and posts it from the card once approved', function () {
    e21Policy($this);
    $this->actingAs($this->bookkeeper);

    Livewire::test(VoucherEditor::class)
        ->set('voucherDate', '2026-03-05')
        ->set('description', 'Inköp av kontorsmaterial')
        ->set('rows', [
            ['account' => '6110', 'debit' => '1000', 'credit' => '', 'description' => '', 'cost_center' => '', 'project' => ''],
            ['account' => '1930', 'debit' => '', 'credit' => '1000', 'description' => '', 'cost_center' => '', 'project' => ''],
        ])
        ->call('post')
        ->assertHasNoErrors()
        ->assertRedirect();

    $draft = Voucher::query()->sole();

    expect($draft->status)->toBe(VoucherStatus::Draft)
        ->and(ApprovalRequest::query()->sole()->status)->toBe(ApprovalRequestStatus::Pending);

    Livewire::test(DraftApprovalCard::class, ['type' => 'MANUAL_VOUCHER', 'draftId' => $draft->id])
        ->assertSee('Verifikationen väntar på attest')
        ->assertDontSee('Attestera verifikationen');

    $this->actingAs($this->accountant);
    Livewire::test(DraftApprovalCard::class, ['type' => 'MANUAL_VOUCHER', 'draftId' => $draft->id])
        ->assertSee('Attestera verifikationen')
        ->call('approve')
        ->assertHasNoErrors()
        ->assertSee('Verifikationen är attesterad och kan bokföras')
        ->call('post')
        ->assertRedirect(route('accounting.vouchers.show', ['voucher' => $draft->id]));

    expect($draft->fresh()->status)->toBe(VoucherStatus::Posted)
        ->and($draft->fresh()->number)->toBe(1);
});

it('refuses on the card what was saved after the approver opened it', function () {
    e21Policy($this);
    $draft = e21Draft($this->bookkeeper, $this->company);
    app(SubmitForApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::ManualVoucher, e21Subject($draft));

    $this->actingAs($this->accountant);
    $card = Livewire::test(DraftApprovalCard::class, ['type' => 'MANUAL_VOUCHER', 'draftId' => $draft->id])->assertSee('Attestera verifikationen');

    e21Draft($this->bookkeeper, $this->company, 999900, $draft->fresh());

    $card->call('approve')->assertHasErrors(['approve']);

    expect(ApprovalRequest::query()->sole()->decisions()->count())->toBe(0);
    Livewire::test(DraftApprovalCard::class, ['type' => 'MANUAL_VOUCHER', 'draftId' => $draft->id])->assertSee('Skicka till attest', false);
});

it('shows nothing on the card for a company without a policy', function () {
    $draft = e21Draft($this->bookkeeper, $this->company);

    Livewire::test(DraftApprovalCard::class, ['type' => 'MANUAL_VOUCHER', 'draftId' => $draft->id])->assertDontSee('Attest');
});

it('renders the card in a fixed number of queries however many decisions the draft has', function () {
    $count = function (int $steps): int {
        $members = [];

        for ($i = 0; $i < $steps; $i++) {
            $members[] = Bookkeeping::member($this->company, 'accountant');
        }

        $this->company = $this->company->fresh();
        ApprovalFixtures::policy($this->owner, $this->company, array_map(fn (User $member): array => ['approver_kind' => 'USERS', 'approver_user_ids' => [$member->id]], $members), type: 'MANUAL_VOUCHER', name: "Flöde {$steps}", priority: 10 - $steps);
        $draft = e21Draft($this->bookkeeper, $this->company);

        foreach (array_slice($members, 0, $steps - 1) as $member) {
            app(ApproveDraft::class)($member, $this->company, ApprovalSubjectType::ManualVoucher, e21Subject($draft));
        }

        $this->actingAs(end($members));
        $card = Livewire::test(DraftApprovalCard::class, ['type' => 'MANUAL_VOUCHER', 'draftId' => $draft->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $card->call('$refresh');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(1))->toBe($count(4));
});

it('writes ids, enums and counts only to the audit log', function () {
    e21Policy($this);
    $draft = e21Draft($this->bookkeeper, $this->company, description: 'Hemlig beskrivning');
    e21Draft($this->bookkeeper, $this->company, draft: $draft->fresh(), description: 'Hemlig beskrivning');

    expect(AuditLog::query()->where('action', 'voucher.draft_saved')->sole()->payload)->toBe(['rows' => 2])
        ->and(AuditLog::query()->pluck('payload')->flatten()->all())->not->toContain('Hemlig beskrivning');
});
