<?php

declare(strict_types=1);

namespace App\Domain\Review\Sources;

use App\Domain\Approval\ApprovalContext;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalRequestStatus;
use App\Domain\Approval\ApprovalSubjects;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\DraftFingerprint;
use App\Domain\Approval\SubjectFacts;
use App\Domain\Review\Contracts\ReviewSource;
use App\Domain\Review\ReviewItem;
use App\Domain\Review\ReviewPreparer;
use App\Models\ApprovalRequest;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\SupplierInvoice;
use App\Models\SupplierPaymentBatch;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * The open approval requests of the company's own approval policies
 * (ADR-081 gap 8) as one source of the approval queue (ADR-067 §1): every
 * request the user may decide on its current step, whatever its subject.
 * There is no second store: the source reads approval_requests and the
 * subjects, and the decision is made on the subject's own page through its
 * own action (ApproveSupplierInvoice, ApprovePaymentBatch,
 * ApprovePurchaseOrder, ApproveDraft).
 *
 * A manual voucher or credit invoice draft saved after it was sent is stale
 * (ADR-067 §5): its request is for a version that no longer exists, so it is
 * listed read-only until it is sent again. A subject that is gone, or no
 * longer waits, is left out.
 *
 * Fixed queries: the requests and their decisions, the approval context, and
 * one batch per subject type that has requests. Purchase orders are read only
 * while Lager is on. Purchase requisitions (E9) are not wired yet; their
 * requests are left out until that package adds its loader here.
 */
final class ApprovalRequestSource implements ReviewSource
{
    public const string KEY = 'attest';

    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly ModuleManager $modules,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Attest');
    }

    /** @return list<ReviewItem> */
    public function items(User $user, Company $company): array
    {
        return CurrentCompany::run($company, function () use ($user, $company): array {
            $requests = ApprovalRequest::query()
                ->where('company_id', $company->id)
                ->where('status', ApprovalRequestStatus::Pending->value)
                ->with('decisions')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            if ($requests->isEmpty()) {
                return [];
            }

            $byType = $requests->groupBy(fn (ApprovalRequest $request): string => $request->subject_type->value);
            $subjects = [];

            foreach ($byType as $type => $group) {
                $subjects[$type] = $this->load($company, ApprovalSubjectType::from($type), array_values($group->map(fn (ApprovalRequest $request): string => $request->subject_id)->all()));
            }

            $context = ApprovalContext::load($company);
            $items = [];

            foreach ($requests as $request) {
                $entry = $subjects[$request->subject_type->value][$request->subject_id] ?? null;

                if ($entry === null) {
                    continue;
                }

                /** @var array{subject: Model, facts: SubjectFacts, stale: bool} $entry */
                $eligibility = $this->engine->eligibility($context, $user, $entry['facts'], $request->snapshot(), $request->current_step, $request->decisions);

                if (! $eligibility->allowed) {
                    continue;
                }

                $items[] = new ReviewItem(
                    source: self::KEY,
                    kind: $request->subject_type->label(),
                    id: (string) $request->id,
                    title: $this->title($request->subject_type, $entry['subject']),
                    date: $request->created_at ?? now()->toImmutable(),
                    amountOre: abs($entry['facts']->amountOre),
                    preparedBy: ReviewPreparer::Person,
                    preparerId: $request->prepared_by,
                    reason: $request->snapshot()->count() > 1
                        ? __('Steg :step av :steps i :name', ['step' => $request->current_step, 'steps' => $request->snapshot()->count(), 'name' => $request->snapshot()->name])
                        : __('Attest enligt :name', ['name' => $request->snapshot()->name]),
                    url: $this->url($request->subject_type, $entry['subject']),
                    readOnly: $entry['stale'],
                    stale: $entry['stale'],
                    onBehalfOf: $eligibility->onBehalfOf,
                );
            }

            return $items;
        });
    }

    /**
     * The subjects of one type that still wait, with their facts, in a fixed
     * number of queries.
     *
     * @param  list<string>  $subjectIds
     * @return array<string, array{subject: Model, facts: SubjectFacts, stale: bool}> subject id => entry
     */
    private function load(Company $company, ApprovalSubjectType $type, array $subjectIds): array
    {
        return match ($type) {
            ApprovalSubjectType::SupplierInvoice => $this->supplierInvoices($subjectIds),
            ApprovalSubjectType::PaymentBatch => $this->paymentBatches($subjectIds),
            ApprovalSubjectType::PurchaseOrder => $this->purchaseOrders($company, $subjectIds),
            ApprovalSubjectType::ManualVoucher => $this->drafts($company, $subjectIds, Voucher::class),
            ApprovalSubjectType::CreditInvoice => $this->drafts($company, $subjectIds, Invoice::class),
            // Hook for E9: purchase requisitions get their loader here when they are wired.
            ApprovalSubjectType::PurchaseRequisition => [],
        };
    }

    /**
     * @param  list<string>  $subjectIds
     * @return array<string, array{subject: Model, facts: SubjectFacts, stale: bool}>
     */
    private function supplierInvoices(array $subjectIds): array
    {
        $invoices = SupplierInvoice::query()->whereKey($subjectIds)->get()->filter(fn (SupplierInvoice $invoice): bool => ApprovalSubjects::awaiting($invoice))->values();
        $facts = SubjectFacts::forSupplierInvoices($invoices);
        $entries = [];

        foreach ($invoices as $invoice) {
            if (isset($facts[$invoice->id])) {
                $entries[$invoice->id] = ['subject' => $invoice, 'facts' => $facts[$invoice->id], 'stale' => false];
            }
        }

        return $entries;
    }

    /**
     * @param  list<string>  $subjectIds
     * @return array<string, array{subject: Model, facts: SubjectFacts, stale: bool}>
     */
    private function paymentBatches(array $subjectIds): array
    {
        $entries = [];

        foreach (SupplierPaymentBatch::query()->whereKey($subjectIds)->get() as $batch) {
            if (ApprovalSubjects::awaiting($batch)) {
                $entries[$batch->id] = ['subject' => $batch, 'facts' => SubjectFacts::forPaymentBatch($batch), 'stale' => false];
            }
        }

        return $entries;
    }

    /**
     * Orders on the round their request names; another round's request is
     * withdrawn by the order itself (ADR-081 E10).
     *
     * @param  list<string>  $subjectIds
     * @return array<string, array{subject: Model, facts: SubjectFacts, stale: bool}>
     */
    private function purchaseOrders(Company $company, array $subjectIds): array
    {
        if (! $this->modules->isEnabled(InventoryModule::KEY, $company)) {
            return [];
        }

        $ids = array_values(array_unique(array_map(fn (string $id): int => (int) strtok($id, '.'), $subjectIds)));
        $orders = PurchaseOrder::query()->whereKey($ids)->with('rows')->get()->filter(fn (PurchaseOrder $order): bool => ApprovalSubjects::awaiting($order))->values();
        $entries = [];

        foreach (SubjectFacts::forPurchaseOrders($company->id, $orders) as $subjectId => $facts) {
            $order = $orders->first(fn (PurchaseOrder $order): bool => $order->id === (int) strtok($subjectId, '.'));

            if ($order instanceof PurchaseOrder && in_array($subjectId, $subjectIds, true)) {
                $entries[$subjectId] = ['subject' => $order, 'facts' => $facts, 'stale' => false];
            }
        }

        return $entries;
    }

    /**
     * Manual voucher and credit invoice drafts. The request names the
     * version it was sent for; a draft saved since is listed as stale under
     * its request's subject id.
     *
     * @param  list<string>  $subjectIds
     * @param  class-string<Voucher|Invoice>  $model
     * @return array<string, array{subject: Model, facts: SubjectFacts, stale: bool}>
     */
    private function drafts(Company $company, array $subjectIds, string $model): array
    {
        $parsed = [];

        foreach ($subjectIds as $subjectId) {
            $parts = DraftFingerprint::parse($subjectId);

            if ($parts !== null) {
                $parsed[$subjectId] = $parts[0];
            }
        }

        $ids = array_values(array_unique($parsed));

        if ($model === Voucher::class) {
            $drafts = Voucher::query()->whereKey($ids)->get()->filter(fn (Voucher $voucher): bool => ApprovalSubjects::awaiting($voucher))->values();
            $fingerprints = DraftFingerprint::vouchers($company->id, array_values($drafts->map(fn (Voucher $voucher): string => $voucher->id)->all()));
            $facts = SubjectFacts::forManualVouchers($company->id, $drafts, $fingerprints);
        } else {
            $drafts = Invoice::query()->whereKey($ids)->get()->filter(fn (Invoice $invoice): bool => $invoice->isCredit() && ApprovalSubjects::awaiting($invoice))->values();
            $fingerprints = DraftFingerprint::invoices($company->id, array_values($drafts->map(fn (Invoice $invoice): string => $invoice->id)->all()));
            $facts = SubjectFacts::forCreditInvoices($company->id, $drafts, $fingerprints);
        }

        $entries = [];

        foreach ($parsed as $subjectId => $draftId) {
            $draft = $drafts->first(fn (Model $draft): bool => $draft->getKey() === $draftId);

            if (! $draft instanceof Model || ! isset($facts[$draftId])) {
                continue;
            }

            $entries[$subjectId] = ['subject' => $draft, 'facts' => $facts[$draftId], 'stale' => $facts[$draftId]->subjectId !== $subjectId];
        }

        return $entries;
    }

    private function title(ApprovalSubjectType $type, Model $subject): string
    {
        return match (true) {
            $subject instanceof SupplierInvoice => __('Leverantörsfaktura :number', ['number' => $subject->number]),
            $subject instanceof SupplierPaymentBatch => __('Betalfil :number', ['number' => $subject->number]),
            $subject instanceof PurchaseOrder => __('Inköpsorder :number', ['number' => $subject->number]),
            $subject instanceof Voucher => __('Verifikationsutkast :date', ['date' => $subject->voucher_date->toDateString()]),
            $subject instanceof Invoice => __('Kreditfaktura, utkast :date', ['date' => $subject->invoice_date->toDateString()]),
            default => $type->label(),
        };
    }

    private function url(ApprovalSubjectType $type, Model $subject): ?string
    {
        return match ($type) {
            ApprovalSubjectType::SupplierInvoice => route('payables.invoices.show', ['supplierInvoice' => $subject->getKey()]),
            ApprovalSubjectType::PaymentBatch => route('payables.batches.index'),
            ApprovalSubjectType::PurchaseOrder => route('inventory.purchaseOrders.show', ['purchaseOrder' => $subject->getKey()]),
            ApprovalSubjectType::ManualVoucher => route('accounting.vouchers.show', ['voucher' => $subject->getKey()]),
            ApprovalSubjectType::CreditInvoice => route('invoicing.invoices.edit', ['invoice' => $subject->getKey()]),
            // Hook for E9: the requisition's page.
            ApprovalSubjectType::PurchaseRequisition => null,
        };
    }
}
