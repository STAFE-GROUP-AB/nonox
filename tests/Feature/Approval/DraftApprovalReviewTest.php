<?php

declare(strict_types=1);

use App\Actions\Accounting\CorrectVoucher;
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
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\DraftApprovals;
use App\Domain\Review\ReviewSources;
use App\Livewire\Accounting\VoucherActions;
use App\Models\ApprovalRequest;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\Inventory\Actions\Purchasing\ApprovePurchaseRequisition;
use App\Modules\Inventory\Actions\Purchasing\PurchaseRequisitionLifecycle;
use App\Modules\Inventory\Actions\Purchasing\SavePurchaseRequisition;
use App\Modules\Inventory\Purchasing\PurchaseRequisitionApprovals;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ApprovalFixtures;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;
use Tests\Support\Inventory;
use Tests\Support\Purchasing;

/*
 * ADR-081 E21 after the cold review: a correction cannot re-book around a
 * MANUAL_VOUCHER policy, a draft is locked while it is saved, a re-save
 * withdraws the open request of the old version, an approved version is
 * not rejected, the tax account's own booking is not held, a credit
 * invoice's version covers every column, and requisitions reach the queue.
 */
beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->accountant = Bookkeeping::member($this->company, 'accountant');
    $this->company = $this->company->fresh();
});

function e21rPolicy(object $test, string $type = 'MANUAL_VOUCHER'): void
{
    ApprovalFixtures::policy($test->owner, $test->company, [['approver_kind' => 'USERS', 'approver_user_ids' => [$test->accountant->id]]], type: $type, name: 'Granskning');
}

function e21rDraft(User $user, Company $company, int $ore = 100000, ?Voucher $draft = null): Voucher
{
    return app(SaveDraftVoucher::class)($user, $company, [
        'voucher_date' => '2026-03-05', 'description' => 'Kontorsmaterial', 'series' => 'A',
        'rows' => [['account' => 6110, 'debit_ore' => $ore], ['account' => 1930, 'credit_ore' => $ore]],
    ], $draft);
}

it('refuses a correction that re-books around the policy, and lets a pure reversal through (P1-1)', function () {
    $posted = app(PostVoucher::class)($this->bookkeeper, e21rDraft($this->bookkeeper, $this->company));
    e21rPolicy($this);

    expect(fn () => app(CorrectVoucher::class)($this->bookkeeper, $posted->fresh(), 'Fel konto', null, [
        ['account' => 6110, 'debit_ore' => 5000000], ['account' => 1930, 'credit_ore' => 5000000],
    ]))->toThrow(ValidationException::class, 'attestflöde gäller ombokningen');

    expect(Voucher::query()->where('status', VoucherStatus::Posted->value)->count())->toBe(1);

    // The app's own correction only reverses: allowed.
    Livewire::test(VoucherActions::class, ['voucher' => $posted->fresh()])
        ->set('reason', 'Dubbelbokad')
        ->call('correct')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect($posted->fresh()->corrected_by_voucher_id)->not->toBeNull();
});

it('answers 422 on the API for a re-booking correction and for posting a held draft (P1-1)', function () {
    Connect::setUp();
    [$owner, $company] = Connect::company();
    $accountant = Bookkeeping::member($company, 'accountant');
    $key = Connect::apiKey($owner, $company->fresh(), ['vouchers:view', 'vouchers:create', 'vouchers:post', 'vouchers:correct']);
    $body = ['voucher_date' => '2026-03-01', 'description' => 'Kontant', 'series' => 'A', 'rows' => [['account' => 1930, 'debit_ore' => 125000], ['account' => 3001, 'credit_ore' => 125000]]];

    $first = $this->withToken($key)->withHeader('Idempotency-Key', 'r-1')->postJson('/api/v1/vouchers', $body)->assertCreated()->json('data.id');
    $this->withToken($key)->withHeader('Idempotency-Key', 'r-1-post')->postJson("/api/v1/vouchers/{$first}/post")->assertOk();

    CurrentCompany::run($company, fn () => ApprovalFixtures::policy($owner, $company->fresh(), [['approver_kind' => 'USERS', 'approver_user_ids' => [$accountant->id]]], type: 'MANUAL_VOUCHER'));

    $this->withToken($key)->withHeader('Idempotency-Key', 'r-1-fix')->postJson("/api/v1/vouchers/{$first}/correct", [
        'reason' => 'Fel konto', 'rows' => [['account' => 1930, 'debit_ore' => 125000], ['account' => 3011, 'credit_ore' => 125000]],
    ])->assertStatus(422)->assertJsonValidationErrors(['rows']);

    $second = $this->withToken($key)->withHeader('Idempotency-Key', 'r-2')->postJson('/api/v1/vouchers', $body)->assertCreated()->json('data.id');
    $this->withToken($key)->withHeader('Idempotency-Key', 'r-2-post')->postJson("/api/v1/vouchers/{$second}/post")
        ->assertStatus(422)->assertJsonValidationErrors(['voucher']);

    $this->withToken($key)->withHeader('Idempotency-Key', 'r-1-reverse')->postJson("/api/v1/vouchers/{$first}/correct", ['reason' => 'Dubbelbokad'])->assertCreated();
});

it('locks the draft while it is saved and refuses a save of a voucher that is no longer a draft (P1-2)', function () {
    $draft = e21rDraft($this->bookkeeper, $this->company);
    $stale = $draft->fresh();
    app(PostVoucher::class)($this->bookkeeper, $draft);

    // A save that was opened on the draft before it was posted is refused, not applied to the posted voucher.
    expect(fn () => e21rDraft($this->bookkeeper, $this->company, 200000, $stale))->toThrow(Exception::class)
        ->and((int) Voucher::query()->sole()->rows()->sum('debit_ore'))->toBe(100000);
});

it('withdraws the open request of the old version when a save changes the draft (P2-5)', function () {
    e21rPolicy($this);
    $draft = e21rDraft($this->bookkeeper, $this->company);
    $old = DraftApprovals::voucherSubjectId($draft);
    app(SubmitForApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::ManualVoucher, $old);

    e21rDraft($this->bookkeeper, $this->company, 130000, $draft->fresh());

    expect(ApprovalRequest::query()->sole()->status)->toBe(ApprovalRequestStatus::Withdrawn)
        ->and(app(ReviewSources::class)->items($this->accountant, $this->company))->toBe([]);
});

it('does not reject a version that is already approved (P3)', function () {
    e21rPolicy($this);
    $draft = e21rDraft($this->bookkeeper, $this->company);
    $subject = DraftApprovals::voucherSubjectId($draft);
    app(ApproveDraft::class)($this->accountant, $this->company, ApprovalSubjectType::ManualVoucher, $subject);

    expect(fn () => app(RejectApproval::class)($this->owner, $this->company, ApprovalSubjectType::ManualVoucher, $subject))->toThrow(ValidationException::class, 'redan attesterat')
        ->and(app(PostManualVoucher::class)($this->bookkeeper, $draft->fresh())->status)->toBe(VoucherStatus::Posted);
});

it('leaves a voucher linked to another record, like the tax account\'s, outside the policy (P2-3)', function () {
    e21rPolicy($this);
    $draft = e21rDraft($this->bookkeeper, $this->company);
    $draft->forceFill(['source_type' => 'tax_account_posting', 'source_id' => '1'])->save();

    expect(DraftApprovals::isManualVoucher($draft->fresh()))->toBeFalse()
        ->and(app(PostManualVoucher::class)($this->bookkeeper, $draft->fresh())->status)->toBe(VoucherStatus::Posted);
});

it('makes every column of a credit invoice part of its version (P2-4)', function () {
    e21rPolicy($this, 'CREDIT_INVOICE');
    $customer = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Kunden AB', 'org_number' => '5561234567', 'payment_terms_days' => 30,
        'address_line1' => 'Kundvägen 7', 'postal_code' => '211 20', 'city' => 'Malmö',
    ]);
    $invoice = app(IssueInvoice::class)($this->owner, app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $customer->id, 'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '2', 'price' => '500', 'vat_rate' => 2500]],
    ]));
    $credit = app(CreditInvoice::class)($this->bookkeeper, $invoice->fresh());
    $before = DraftApprovals::invoiceSubjectId($credit->fresh());

    Invoice::query()->whereKey($credit->id)->update(['our_reference' => 'Ändrad referens']);

    expect(DraftApprovals::invoiceSubjectId($credit->fresh()))->not->toBe($before);
});

it('lists a purchase requisition waiting on a later step in the queue, linked to its page', function () {
    Inventory::enable($this->company, $this->owner);
    $places = Inventory::locations($this->company, $this->owner);
    $vendor = Purchasing::supplier($this->owner, $this->company);
    $screw = Purchasing::screw($this->owner, $this->company, $vendor, ['default_location_id' => $places['bin2']->id]);
    $approver = Bookkeeping::member($this->company, 'approver');
    $this->company = $this->company->fresh();
    ApprovalFixtures::policy($this->owner, $this->company, [
        ['approver_kind' => 'PERMISSION', 'approver_permission' => 'purchase-orders:send'],
        ['approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage'],
    ], type: 'PURCHASE_REQUISITION', name: 'Anmodan');

    $requisition = app(SavePurchaseRequisition::class)($this->bookkeeper, $this->company, ['needed_by' => '2026-09-01', 'reason' => 'Behövs', 'rows' => [['article_id' => $screw->id, 'quantity' => '2', 'price' => '100']]]);
    $requisition = app(PurchaseRequisitionLifecycle::class)->submit($this->bookkeeper, $this->company, $requisition);
    app(ApprovePurchaseRequisition::class)($approver, $this->company, PurchaseRequisitionApprovals::subjectId($requisition));

    $items = app(ReviewSources::class)->items($this->owner, $this->company);

    expect(array_map(fn ($item): string => $item->title, $items))->toBe(["Inköpsanmodan {$requisition->number}"])
        ->and($items[0]->url)->toBe(route('inventory.requisitions.show', ['requisition' => $requisition->id]))
        ->and($items[0]->reason)->toContain('Steg 2 av 2');
});

it('records codes, never messages, when the inbox cannot book automatically', function () {
    $source = (string) file_get_contents(app_path('Modules/Inbox/Actions/ApproveInboxMessage.php'));

    expect($source)->not->toContain('$exception->errors() :')
        ->and($source)->toContain("'approval_required'");
});
