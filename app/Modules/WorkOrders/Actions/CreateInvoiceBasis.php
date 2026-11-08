<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Actions;

use App\Actions\Invoicing\SaveDraftInvoice;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Modules\WorkOrders\Actions\Concerns\GuardsWorkOrders;
use App\Modules\WorkOrders\Domain\PriceForm;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\MaterialEntry;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderInvoice;
use App\Modules\WorkOrders\Support\InvoiceDraft;
use App\Modules\WorkOrders\Support\Uninvoiced;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Skapa fakturaunderlag" (ADR-074 §5): a draft invoice, never an issued
 * one, built through SaveDraftInvoice from the uninvoiced entries up to a
 * date. Creating it needs work-orders:manage and invoices:create; issuing
 * it stays with the invoicing core.
 *
 * - Rows: main work first, then each decided ÄTA with its number in the
 *   row text; per group labour per kind of work (hours × rate), travel and
 *   machines, and material. A fixed price takes the contract sum not yet
 *   invoiced (and a fixed-price ÄTA its amounts); its recorded hours still
 *   go on the invoice.
 * - ÄTA that are not approved are held back ("price before work").
 * - ROT/RUT: only kinds of type LABOUR become labour rows, never travel,
 *   machines or material (AO-R13); an ÄTA can opt out (AO-R17). The hours
 *   and the kind of work go on the invoice, and a ROT/RUT basis without
 *   recorded hours is refused, fixed price included (AO-R14, F10).
 * - Reverse charge is inherited from the work order (AO-R20).
 * - The entries are reserved by the draft and become invoiced when it is
 *   issued; deleting the draft releases them.
 */
class CreateInvoiceBasis
{
    use GuardsWorkOrders;

    public function __construct(private readonly SaveDraftInvoice $save) {}

    public function __invoke(User $user, Company $company, WorkOrder $workOrder, ?CarbonImmutable $upTo = null, ?CarbonImmutable $invoiceDate = null): Invoice
    {
        $this->guard($user, $company);
        Gate::forUser($user)->authorize('create', [Invoice::class, $company]);

        $upTo ??= CarbonImmutable::today();
        $invoiceDate ??= CarbonImmutable::today();

        return DB::transaction(fn (): Invoice => CurrentCompany::run($company, function () use ($user, $company, $workOrder, $upTo, $invoiceDate): Invoice {
            $workOrder = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if (! $workOrder->status->isOpen()) {
                throw ValidationException::withMessages(['work_order' => __('Arbetsordern är avslutad.')]);
            }

            $changeOrders = ChangeOrder::query()->where('work_order_id', $workOrder->id)->orderBy('number')->get()->keyBy('id');
            $invoiceable = fn (TimeEntry|MaterialEntry $entry): bool => $entry->change_order_id === null || ($changeOrders->get($entry->change_order_id)?->status->allowsInvoicing() ?? false);

            /** @var Collection<int, TimeEntry> $time */
            $time = Uninvoiced::free(TimeEntry::query()->where('work_order_id', $workOrder->id))->whereDate('work_date', '<=', $upTo->toDateString())->with('labourKind')->orderBy('work_date')->get()->filter($invoiceable);
            /** @var Collection<int, MaterialEntry> $material */
            $material = Uninvoiced::free(MaterialEntry::query()->where('work_order_id', $workOrder->id))->whereDate('work_date', '<=', $upTo->toDateString())->orderBy('work_date')->get()->filter($invoiceable);

            $draft = new InvoiceDraft($workOrder);
            $descriptions = [];
            $includesContract = false;

            // Main work.
            $mainTime = $time->whereNull('change_order_id');
            $mainMaterial = $material->whereNull('change_order_id');

            if ($workOrder->price_form === PriceForm::Fixed) {
                $remaining = InvoiceDraft::contractRemaining($workOrder);

                if ($remaining['net'] > 0) {
                    $draft->fixedRows('', $remaining['net'], $remaining['labour'], true);
                    $draft->contractNet = $remaining['net'];
                    $includesContract = true;
                }

                // Recorded hours and material are part of the fixed price: consumed, not billed.
                $draft->consume($mainTime, $mainMaterial, true);
            } else {
                $draft->entryRows('', $mainTime, $mainMaterial, true);
            }

            if ($draft->hasRows()) {
                $descriptions[] = $workOrder->title;
            }

            // Each decided ÄTA, in number order.
            foreach ($changeOrders as $changeOrder) {
                if (! $changeOrder->status->allowsInvoicing()) {
                    continue;
                }

                $prefix = $changeOrder->label().': ';
                $coTime = $time->where('change_order_id', $changeOrder->id);
                $coMaterial = $material->where('change_order_id', $changeOrder->id);
                $before = $draft->rowCount();

                if ($changeOrder->price_form === PriceForm::Fixed) {
                    if (! InvoiceDraft::changeOrderInvoiced($changeOrder)) {
                        $draft->changeOrderRows($prefix, $changeOrder);
                    }

                    $draft->consume($coTime, $coMaterial, $changeOrder->tax_reduction_applies);
                } else {
                    $draft->entryRows($prefix, $coTime, $coMaterial, $changeOrder->tax_reduction_applies);
                }

                if ($draft->rowCount() > $before) {
                    $descriptions[] = $changeOrder->title;
                }
            }

            if (! $draft->hasRows()) {
                throw ValidationException::withMessages(['work_order' => __('Det finns inget att fakturera fram till :date. ÄTA som inte är godkända hålls tillbaka.', ['date' => $upTo->toDateString()])]);
            }

            if ($workOrder->tax_reduction !== null && $draft->hasLabour() && $draft->labourMinutes() <= 0) {
                throw ValidationException::withMessages(['work_order' => __('Registrera arbetade timmar. Skatteverket kräver antalet timmar även vid fast pris.')]);
            }

            $invoice = ($this->save)($user, $company, $draft->input($invoiceDate, implode(', ', array_unique($descriptions))));

            WorkOrderInvoice::create([
                'work_order_id' => $workOrder->id,
                'invoice_id' => $invoice->id,
                'kind' => $includesContract ? WorkOrderInvoice::FINAL : WorkOrderInvoice::BASIS,
                'contract_net_ore' => $draft->contractNet,
                'change_order_ids' => $draft->changeOrderIds,
            ]);

            $draft->reserve($invoice);

            Audit::record('work_orders.invoice_basis_created', $workOrder, [
                'invoice_id' => $invoice->id,
                'time_entries' => $draft->timeIds(),
                'material_entries' => $draft->materialIds(),
                'up_to' => $upTo->toDateString(),
            ], $company, $user);

            return $invoice;
        }));
    }
}
