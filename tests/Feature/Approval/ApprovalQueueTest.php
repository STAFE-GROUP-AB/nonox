<?php

declare(strict_types=1);

use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Approval\ApproveDraft;
use App\Actions\Approval\RejectApproval;
use App\Actions\Approval\ResubmitApproval;
use App\Actions\Approval\SubmitForApproval;
use App\Domain\Approval\ApprovalRequestStatus;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\DraftApprovals;
use App\Domain\Review\ReviewSources;
use App\Domain\Review\Sources\ApprovalRequestSource;
use App\Livewire\Approval\ApprovalQueue;
use App\Models\ApprovalRequest;
use App\Models\Company;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\Inventory\Actions\Purchasing\ApprovePurchaseOrder;
use App\Modules\Inventory\Actions\Purchasing\SavePurchaseOrder;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\PurchaseOrderApprovals;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ApprovalFixtures;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Purchasing;

/*
 * ADR-081 E21: the approval engine's open requests as a source of the
 * ADR-067 approval queue ("Att godkänna"), and the check of the purchase
 * order wiring through the generic actions. The queue lists what the person
 * may decide on its current step, links to the subject's own page and
 * decides nothing itself.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->accountant = Bookkeeping::member($this->company, 'accountant');
    $this->approver = Bookkeeping::member($this->company, 'approver');
    $this->company = $this->company->fresh();
    $this->supplier = ApprovalFixtures::supplier($this->owner, $this->company);
});

function e21qVoucher(User $user, Company $company, int $ore = 100000): Voucher
{
    return app(SaveDraftVoucher::class)($user, $company, [
        'voucher_date' => '2026-03-05',
        'description' => 'Representation',
        'series' => 'A',
        'rows' => [['account' => 6071, 'debit_ore' => $ore], ['account' => 1930, 'credit_ore' => $ore]],
    ]);
}

/** @return list<string> the titles of the items the user sees */
function e21qTitles(User $user, Company $company): array
{
    return array_map(fn ($item): string => $item->title, app(ReviewSources::class)->items($user, $company));
}

function e21qPolicies(object $test): void
{
    ApprovalFixtures::policy($test->owner, $test->company, [['approver_kind' => 'USERS', 'approver_user_ids' => [$test->accountant->id]]], type: 'MANUAL_VOUCHER');
    ApprovalFixtures::policy($test->owner, $test->company, [['approver_kind' => 'USERS', 'approver_user_ids' => [$test->approver->id]]]);
}

it('lists the requests the person may decide now, from every subject, and nothing else', function () {
    e21qPolicies($this);
    $voucher = e21qVoucher($this->bookkeeper, $this->company);
    app(SubmitForApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::ManualVoucher, DraftApprovals::voucherSubjectId($voucher));
    $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier, '4000');
    app(RejectApproval::class)($this->owner, $this->company, ApprovalSubjectType::SupplierInvoice, $invoice->id);
    app(ResubmitApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::SupplierInvoice, $invoice->id);

    $items = app(ReviewSources::class)->items($this->accountant, $this->company);

    expect(array_map(fn ($item): string => $item->title, $items))->toBe(['Verifikationsutkast 2026-03-05'])
        ->and($items[0]->url)->toBe(route('accounting.vouchers.show', ['voucher' => $voucher->id]))
        ->and($items[0]->amountOre)->toBe(100000)
        ->and($items[0]->preparerId)->toBe($this->bookkeeper->id)
        ->and($items[0]->source)->toBe(ApprovalRequestSource::KEY)
        ->and(e21qTitles($this->approver, $this->company))->toBe(["Leverantörsfaktura {$invoice->number}"])
        ->and(e21qTitles($this->bookkeeper, $this->company))->toBe([]);

    app(ApproveDraft::class)($this->accountant, $this->company, ApprovalSubjectType::ManualVoucher, DraftApprovals::voucherSubjectId($voucher->fresh()));

    expect(e21qTitles($this->accountant, $this->company))->toBe([]);
});

it('marks a draft saved after it was sent as stale and read-only', function () {
    e21qPolicies($this);
    $voucher = e21qVoucher($this->bookkeeper, $this->company);
    app(SubmitForApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::ManualVoucher, DraftApprovals::voucherSubjectId($voucher));

    app(SaveDraftVoucher::class)($this->bookkeeper, $this->company, [
        'voucher_date' => '2026-03-05', 'description' => 'Representation', 'series' => 'A',
        'rows' => [['account' => 6071, 'debit_ore' => 120000], ['account' => 1930, 'credit_ore' => 120000]],
    ], $voucher->fresh());

    $items = app(ReviewSources::class)->items($this->accountant, $this->company);

    expect($items)->toHaveCount(1)
        ->and($items[0]->stale)->toBeTrue()
        ->and($items[0]->readOnly)->toBeTrue();
});

it('wires purchase orders through the queue and the generic reject and resubmit while Lager is on', function () {
    Inventory::enable($this->company, $this->owner);
    $vendor = Purchasing::supplier($this->owner, $this->company);
    $screw = Purchasing::screw($this->owner, $this->company, $vendor);
    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'purchase-orders:send']], type: 'PURCHASE_ORDER');
    $order = Purchasing::order($this->bookkeeper, $this->company, $vendor, [['article_id' => $screw->id, 'quantity' => '1', 'price' => '1000']]);
    $subjectId = PurchaseOrderApprovals::subjectId($order);

    app(RejectApproval::class)($this->approver, $this->company, ApprovalSubjectType::PurchaseOrder, $subjectId, 'Fel leverantör');
    app(ResubmitApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::PurchaseOrder, $subjectId);

    $items = app(ReviewSources::class)->items($this->approver, $this->company);

    expect(array_map(fn ($item): string => $item->title, $items))->toBe(["Inköpsorder {$order->number}"])
        ->and($items[0]->url)->toBe(route('inventory.purchaseOrders.show', ['purchaseOrder' => $order->id]));

    app(ApprovePurchaseOrder::class)($this->approver, $this->company, $subjectId);

    expect(e21qTitles($this->approver, $this->company))->toBe([]);

    $second = Purchasing::order($this->bookkeeper, $this->company, $vendor, [['article_id' => $screw->id, 'quantity' => '2', 'price' => '1000']]);
    app(RejectApproval::class)($this->approver, $this->company, ApprovalSubjectType::PurchaseOrder, PurchaseOrderApprovals::subjectId($second));
    app(ResubmitApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::PurchaseOrder, PurchaseOrderApprovals::subjectId($second));

    expect(e21qTitles($this->approver, $this->company))->toHaveCount(1);

    app(ModuleManager::class)->disable(InventoryModule::KEY, $this->company, $this->owner, confirmed: true);

    expect(e21qTitles($this->approver, $this->company->fresh()))->toBe([]);
});

it('counts everyone who saved a purchase order as its preparer', function () {
    Inventory::enable($this->company, $this->owner);
    $vendor = Purchasing::supplier($this->owner, $this->company);
    $screw = Purchasing::screw($this->owner, $this->company, $vendor);
    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'purchase-orders:send', 'exclude_preparer' => true]], type: 'PURCHASE_ORDER');
    $order = Purchasing::order($this->bookkeeper, $this->company, $vendor, [['article_id' => $screw->id, 'quantity' => '1', 'price' => '1000']]);
    $editor = Bookkeeping::member($this->company, 'admin');
    $this->company = $this->company->fresh();

    app(SavePurchaseOrder::class)($editor, $this->company, ['supplier_id' => $vendor->id, 'rows' => [['id' => $order->rows()->sole()->id, 'article_id' => $screw->id, 'quantity' => '2', 'price' => '1000']]], $order->fresh());
    app(SavePurchaseOrder::class)($this->bookkeeper, $this->company, ['supplier_id' => $vendor->id, 'rows' => [['id' => $order->rows()->sole()->id, 'article_id' => $screw->id, 'quantity' => '3', 'price' => '1000']]], $order->fresh());
    $order = PurchaseOrder::query()->findOrFail($order->id);

    // The admin saved an earlier round and the bookkeeper the last one: the admin is still one of its preparers.
    expect(fn () => app(ApprovePurchaseOrder::class)($editor, $this->company, PurchaseOrderApprovals::subjectId($order)))->toThrow(ValidationException::class)
        ->and(app(ApprovePurchaseOrder::class)($this->approver, $this->company, PurchaseOrderApprovals::subjectId($order))->isApproved())->toBeTrue();
});

it('shows the queue page with links, and an empty state when nothing waits', function () {
    $this->actingAs($this->accountant);
    Livewire::test(ApprovalQueue::class)->assertSee('Inget väntar på din attest');

    e21qPolicies($this);
    $voucher = e21qVoucher($this->bookkeeper, $this->company);
    app(SubmitForApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::ManualVoucher, DraftApprovals::voucherSubjectId($voucher));

    Livewire::test(ApprovalQueue::class)
        ->assertSee('Verifikationsutkast 2026-03-05')
        ->assertSee(route('accounting.vouchers.show', ['voucher' => $voucher->id]))
        ->assertSee($this->bookkeeper->name);

    $this->get(route('approvals.queue'))->assertOk()->assertSee('Att godkänna');
});

it('reads the queue in a fixed number of queries however many requests wait', function () {
    e21qPolicies($this);
    $count = function (int $vouchers, int $invoices): int {
        for ($i = 0; $i < $vouchers; $i++) {
            $voucher = e21qVoucher($this->bookkeeper, $this->company, 100000 + $i);
            app(SubmitForApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::ManualVoucher, DraftApprovals::voucherSubjectId($voucher));
        }

        for ($i = 0; $i < $invoices; $i++) {
            $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier, (string) (1000 + $i), number: "Q-{$vouchers}-{$i}");
            app(RejectApproval::class)($this->owner, $this->company, ApprovalSubjectType::SupplierInvoice, $invoice->id);
            app(ResubmitApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::SupplierInvoice, $invoice->id);
        }

        $company = $this->company->fresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ReviewSources::class)->items($this->owner->fresh(), $company);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = $count(1, 1);
    ApprovalRequest::query()->where('status', ApprovalRequestStatus::Pending->value)->get()->each->forceFill(['status' => ApprovalRequestStatus::Withdrawn])->each->save();

    expect($count(5, 4))->toBe($few);
});
