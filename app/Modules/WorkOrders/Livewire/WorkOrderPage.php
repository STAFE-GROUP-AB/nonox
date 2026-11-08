<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Livewire;

use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SupplierInvoiceRow;
use App\Modules\WorkOrders\Actions\ChangeWorkOrderStatus;
use App\Modules\WorkOrders\Actions\CreateInvoiceBasis;
use App\Modules\WorkOrders\Actions\DecideChangeOrder;
use App\Modules\WorkOrders\Actions\PaymentPlans;
use App\Modules\WorkOrders\Actions\RecordMaterial;
use App\Modules\WorkOrders\Actions\RecordTime;
use App\Modules\WorkOrders\Actions\ReverseEntry;
use App\Modules\WorkOrders\Actions\SaveChangeOrder;
use App\Modules\WorkOrders\Actions\SignChangeOrder;
use App\Modules\WorkOrders\Domain\Advice;
use App\Modules\WorkOrders\Domain\ChangeOrderBasis;
use App\Modules\WorkOrders\Domain\ContactChannel;
use App\Modules\WorkOrders\Domain\PerformedReason;
use App\Modules\WorkOrders\Domain\PriceForm;
use App\Modules\WorkOrders\Domain\WorkOrderStatus;
use App\Modules\WorkOrders\Livewire\Concerns\WorkOrdersPage;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\LabourKind;
use App\Modules\WorkOrders\Models\MaterialEntry;
use App\Modules\WorkOrders\Models\PaymentInstalment;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderInvoice;
use App\Modules\WorkOrders\Support\ExpireChangeOrders;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One work order in the office: its warnings, time and material, ÄTA with
 * the customer's answer, the payment plan, the invoices made from it, and
 * "Skapa fakturaunderlag". A fixed number of queries whatever the history.
 */
class WorkOrderPage extends Component
{
    use WorkOrdersPage;

    #[Locked]
    public int $workOrderId;

    /** @var array<string, mixed> */
    public array $time = [];

    /** @var array<string, mixed> */
    public array $material = [];

    /** The entry being reversed, "time:<id>" or "material:<id>". */
    public ?string $reversing = null;

    public string $reason = '';

    public string $basisDate = '';

    /** null: no ÄTA form; 0: a new ÄTA; otherwise the draft being edited */
    public ?int $editingChange = null;

    /** @var array<string, mixed> */
    public array $change = [];

    /** The ÄTA a decision form is open for, and which: oral, performed, attempt. */
    public ?int $decidingChange = null;

    public string $decision = '';

    /** @var array<string, mixed> */
    public array $decide = [];

    /** @var array<int, array<string, mixed>> */
    public array $plan = [];

    public bool $planAgreed = false;

    public bool $editingPlan = false;

    /** The e-mail the signing link goes to; empty: the customer's. */
    public string $signEmail = '';

    public function mount(int $workOrderId): void
    {
        $this->workOrderId = $this->findWorkOrder($workOrderId)->id;
        $this->basisDate = CarbonImmutable::today()->toDateString();
        $this->resetEntryForms();
    }

    public function recordTime(RecordTime $record): void
    {
        $workOrder = $this->workOrder();

        if ($this->attempt(fn () => $record($this->user(), $this->company(), $workOrder, [...$this->time, 'change_order_id' => ($this->time['change_order_id'] ?? '') ?: null, 'source' => 'WEB']), __('Tiden är registrerad.'), 'time.')) {
            $this->resetEntryForms();
        }
    }

    public function recordMaterial(RecordMaterial $record): void
    {
        $workOrder = $this->workOrder();
        $input = [...$this->material, 'change_order_id' => ($this->material['change_order_id'] ?? '') ?: null, 'article_id' => ($this->material['article_id'] ?? '') ?: null, 'supplier_invoice_row_id' => ($this->material['supplier_invoice_row_id'] ?? '') ?: null];

        if ($this->attempt(fn () => $record($this->user(), $this->company(), $workOrder, $input), __('Materialet är registrerat.'), 'material.')) {
            $this->resetEntryForms();
        }
    }

    public function startReverse(string $key): void
    {
        $this->reversing = $key;
        $this->reason = '';
    }

    public function reverse(ReverseEntry $reverse): void
    {
        [$kind, $id] = explode(':', (string) $this->reversing, 2) + [1 => ''];
        $entry = $kind === 'time'
            ? TimeEntry::query()->where('work_order_id', $this->workOrderId)->findOrFail($id)
            : MaterialEntry::query()->where('work_order_id', $this->workOrderId)->findOrFail($id);

        if ($this->attempt(fn () => $reverse($this->user(), $this->company(), $entry, $this->reason), __('Posten är backad.'), '')) {
            $this->reversing = null;
        }
    }

    public function changeStatus(string $to, ChangeWorkOrderStatus $change): void
    {
        $status = WorkOrderStatus::from($to);
        $this->attempt(fn () => $change($this->user(), $this->company(), $this->workOrder(), $status), __('Arbetsordern är :status.', ['status' => mb_strtolower($status->label())]), '');
    }

    public function createBasis(CreateInvoiceBasis $create): void
    {
        $invoice = null;
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->basisDate) ?: CarbonImmutable::today();
        $ok = $this->attempt(function () use ($create, $date, &$invoice): void {
            $invoice = $create($this->user(), $this->company(), $this->workOrder(), $date);
        }, '', '');

        if ($ok && $invoice instanceof Invoice) {
            Toast::flash(__('Fakturaunderlaget är skapat som ett utkast. Granska det och skapa fakturan.'));
            $this->redirectRoute('invoicing.invoices.edit', $invoice->id);
        }
    }

    public function newChange(): void
    {
        $this->editingChange = 0;
        $this->change = ['title' => '', 'description' => '', 'basis' => ChangeOrderBasis::ChangeOrdered->value, 'price_form' => PriceForm::Fixed->value, 'labour' => '', 'material' => '', 'other' => '', 'limit' => '', 'time_effect' => '', 'payment_terms' => '', 'tax_reduction_applies' => true];
    }

    public function editChange(int $id): void
    {
        $changeOrder = ChangeOrder::query()->where('work_order_id', $this->workOrderId)->findOrFail($id);
        $this->editingChange = $changeOrder->id;
        $this->change = [
            'title' => $changeOrder->title, 'description' => $changeOrder->description, 'basis' => $changeOrder->basis->value, 'price_form' => $changeOrder->price_form->value,
            'labour' => $changeOrder->labour_ore->format(false), 'material' => $changeOrder->material_ore->format(false), 'other' => $changeOrder->other_ore->format(false),
            'limit' => (string) $changeOrder->limit_ore?->format(false), 'time_effect' => (string) $changeOrder->time_effect, 'payment_terms' => (string) $changeOrder->payment_terms,
            'tax_reduction_applies' => $changeOrder->tax_reduction_applies,
        ];
    }

    public function saveChange(SaveChangeOrder $save): void
    {
        $changeOrder = $this->editingChange ? ChangeOrder::query()->where('work_order_id', $this->workOrderId)->findOrFail($this->editingChange) : null;

        if ($this->attempt(fn () => $save($this->user(), $this->company(), $this->workOrder(), $this->change, $changeOrder), $changeOrder === null ? __('ÄTA:t är skapat som ett utkast.') : __('ÄTA:t är sparat.'), 'change.')) {
            $this->reset('editingChange', 'change');
        }
    }

    public function cancelChange(): void
    {
        $this->reset('editingChange', 'change', 'decidingChange', 'decision', 'decide');
        $this->resetErrorBag();
    }

    public function openDecision(int $id, string $decision): void
    {
        $this->decidingChange = ChangeOrder::query()->where('work_order_id', $this->workOrderId)->findOrFail($id)->id;
        $this->decision = $decision;
        $this->decide = ['name' => '', 'title' => '', 'channel' => ContactChannel::Phone->value, 'at' => CarbonImmutable::now()->format('Y-m-d H:i'), 'note' => '', 'reason' => '', 'justification' => ''];
    }

    public function submitDecision(DecideChangeOrder $decide): void
    {
        $changeOrder = ChangeOrder::query()->where('work_order_id', $this->workOrderId)->findOrFail((int) $this->decidingChange);
        $at = str_replace('T', ' ', (string) ($this->decide['at'] ?? ''));

        $ok = match ($this->decision) {
            'oral' => $this->attempt(fn () => $decide->approveOrally($this->user(), $this->company(), $changeOrder, [...$this->decide, 'at' => $at]), __(':ata är godkänt muntligt. Kunden får en kopia via e-post.', ['ata' => $changeOrder->label()]), 'decide.'),
            'attempt' => $this->attempt(fn () => $decide->recordAttempt($this->user(), $this->company(), $changeOrder, [...$this->decide, 'at' => $at]), __('Försöket att nå kunden är registrerat.'), 'decide.'),
            'performed' => $this->attempt(fn () => $decide->performWithoutAnswer($this->user(), $this->company(), $changeOrder, $this->decide), __(':ata är registrerat som utfört utan kundens besked. Kunden får en kopia via e-post.', ['ata' => $changeOrder->label()]), 'decide.'),
            'declined' => $this->attempt(fn () => $decide->decline($this->user(), $this->company(), $changeOrder, (string) ($this->decide['name'] ?? '')), __(':ata är avböjt.', ['ata' => $changeOrder->label()]), 'decide.'),
            default => false,
        };

        if ($ok) {
            $this->reset('decidingChange', 'decision', 'decide');
        }
    }

    /** "Skicka för signering": the customer gets a link and signs the ÄTA before the work is done (ADR-074 §4). */
    public function sendForSigning(int $id, SignChangeOrder $sign): void
    {
        $changeOrder = ChangeOrder::query()->where('work_order_id', $this->workOrderId)->findOrFail($id);
        $this->attempt(fn () => $sign->send($this->user(), $this->company(), $changeOrder, ['email' => $this->signEmail]), __(':ata är skickat till kunden för signering.', ['ata' => $changeOrder->label()]), 'sign.');
    }

    public function withdrawChange(int $id, DecideChangeOrder $decide): void
    {
        $changeOrder = ChangeOrder::query()->where('work_order_id', $this->workOrderId)->findOrFail($id);
        $this->attempt(fn () => $decide->withdraw($this->user(), $this->company(), $changeOrder), __(':ata är återkallat.', ['ata' => $changeOrder->label()]), '');
    }

    public function editPlan(): void
    {
        $this->editingPlan = true;
        $this->plan = PaymentInstalment::query()->where('work_order_id', $this->workOrderId)->orderBy('position')->get()
            ->map(fn (PaymentInstalment $instalment): array => ['label' => $instalment->label, 'amount' => $instalment->amount_ore->format(false), 'due_on' => (string) $instalment->due_on?->toDateString(), 'milestone' => (string) $instalment->milestone])->values()->all();

        if ($this->plan === []) {
            $this->addInstalment();
        }
    }

    public function addInstalment(): void
    {
        $this->plan[] = ['label' => '', 'amount' => '', 'due_on' => '', 'milestone' => ''];
    }

    public function savePlan(PaymentPlans $plans): void
    {
        if ($this->attempt(fn () => $plans->save($this->user(), $this->company(), $this->workOrder(), $this->plan, $this->planAgreed), __('Betalningsplanen är sparad.'), 'plan.')) {
            $this->editingPlan = false;
        }
    }

    public function invoiceInstalment(int $id, PaymentPlans $plans): void
    {
        $instalment = PaymentInstalment::query()->where('work_order_id', $this->workOrderId)->findOrFail($id);
        $invoice = null;
        $ok = $this->attempt(function () use ($plans, $instalment, &$invoice): void {
            $invoice = $plans->invoice($this->user(), $this->company(), $instalment);
        }, '', '');

        if ($ok && $invoice instanceof Invoice) {
            Toast::flash(__('A conto-fakturan är skapad som ett utkast. Granska den och skapa fakturan.'));
            $this->redirectRoute('invoicing.invoices.edit', $invoice->id);
        }
    }

    public function render(): View
    {
        $workOrder = $this->workOrder();
        $workOrder->load(['customer', 'project', 'assignments.user', 'buyers.customer']);
        $customer = $workOrder->customer;
        $today = CarbonImmutable::today();
        $canManage = $this->can('manage');

        $timeEntries = TimeEntry::query()->where('work_order_id', $workOrder->id)->with(['labourKind', 'user:id,name'])->orderByDesc('work_date')->orderByDesc('entered_at')->get();
        $materialEntries = MaterialEntry::query()->where('work_order_id', $workOrder->id)->with('user:id,name')->orderByDesc('work_date')->orderByDesc('entered_at')->get();
        $changeOrders = ChangeOrder::query()->where('work_order_id', $workOrder->id)->withCount('contactAttempts')->orderBy('number')->get();
        $links = WorkOrderInvoice::query()->where('work_order_id', $workOrder->id)->with('invoice:id,number,status,invoice_date,net_ore,total_ore,kind')->orderBy('id')->get();
        $instalments = PaymentInstalment::query()->where('work_order_id', $workOrder->id)->with('invoiceLink')->orderBy('position')->get();

        $supplierRows = $canManage && $workOrder->project_id !== null
            ? SupplierInvoiceRow::query()->where('project_id', $workOrder->project_id)
                ->whereHas('supplierInvoice', fn ($query) => $query->whereIn('status', [SupplierInvoiceStatus::Approved->value, SupplierInvoiceStatus::Paid->value]))
                ->whereNotIn('id', MaterialEntry::query()->select('supplier_invoice_row_id')->whereNotNull('supplier_invoice_row_id')->whereNull('reverses_id')->whereNull('reversed_by_id'))
                ->get()
            : collect();

        return view('work-orders::livewire.show', [
            'workOrder' => $workOrder,
            'customer' => $customer,
            'timeEntries' => $timeEntries,
            'materialEntries' => $materialEntries,
            'changeOrders' => $changeOrders,
            'lapsed' => ExpireChangeOrders::lapsedIn($changeOrders),
            'links' => $links,
            'instalments' => $instalments,
            'supplierRows' => $supplierRows,
            'kinds' => LabourKind::query()->where('is_active', true)->orderBy('position')->get(),
            'canManage' => $canManage,
            'canSend' => $this->can('send'),
            'canReport' => Gate::forUser($this->user())->allows('report', $workOrder),
            'canInvoice' => $canManage && Gate::forUser($this->user())->allows('create', [Invoice::class, $this->company()]),
            'consumer' => $customer instanceof Customer && Advice::isConsumer($customer),
            'approximate' => $customer instanceof Customer ? Advice::approximatePrice($workOrder, $customer) : null,
            'deadlines' => Advice::invoiceDeadlines($workOrder, $today),
            'staffLedger' => $customer instanceof Customer && Advice::staffLedgerHint($workOrder, $customer, $today),
            'rotAconto' => Advice::rotAconto($workOrder, $today),
            'reasons' => PerformedReason::cases(),
            'channels' => ContactChannel::cases(),
            'bases' => ChangeOrderBasis::cases(),
            'priceForms' => PriceForm::cases(),
            'nextStatuses' => $workOrder->status->next(),
            'self' => WorkOrdersModule::class,
        ]);
    }

    private function workOrder(): WorkOrder
    {
        return $this->findWorkOrder($this->workOrderId);
    }

    private function resetEntryForms(): void
    {
        $today = CarbonImmutable::today()->toDateString();
        $this->time = ['labour_kind_id' => (string) (LabourKind::query()->where('is_active', true)->orderBy('position')->value('id') ?? ''), 'work_date' => $today, 'hours' => '', 'quantity' => '', 'change_order_id' => '', 'note' => '', 'billable' => true, 'rate' => ''];
        $this->material = ['source' => 'FREE', 'article_id' => '', 'location_id' => '', 'supplier_invoice_row_id' => '', 'description' => '', 'quantity' => '', 'unit' => 'st', 'unit_cost' => '', 'unit_price' => '', 'change_order_id' => '', 'work_date' => $today, 'note' => '', 'billable' => true];
    }
}
