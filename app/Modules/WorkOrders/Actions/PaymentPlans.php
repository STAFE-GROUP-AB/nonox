<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Actions;

use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Company\AccountingMethod;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Modules\WorkOrders\Actions\Concerns\GuardsWorkOrders;
use App\Modules\WorkOrders\Domain\Advice;
use App\Modules\WorkOrders\Domain\PriceForm;
use App\Modules\WorkOrders\Models\PaymentInstalment;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderInvoice;
use App\Modules\WorkOrders\Support\Input;
use App\Modules\WorkOrders\Support\InvoiceDraft;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A conto and payment plans (ADR-074 §6). A fixed-price work order can have
 * a plan of instalments (amount excluding VAT, with a date or a
 * milestone). Each instalment becomes an ordinary draft invoice through
 * SaveDraftInvoice with its own ROT or RUT on its own labour share
 * (AO-R16); the final invoice ("Skapa fakturaunderlag") then takes the rest
 * of the contract as rows of its own, never a negative a conto row (L14).
 *
 * Not offered under kontantmetoden until L10 is answered. For a consumer
 * the plan must be agreed with the customer (KtjL 41 §, AO-R9). Plans on
 * an approximate price (löpande räkning) wait for L14.
 */
class PaymentPlans
{
    use GuardsWorkOrders;

    public function __construct(private readonly SaveDraftInvoice $save) {}

    /**
     * @param  array<int, array<string, mixed>>  $instalments  label, amount (kronor ex VAT), due_on, milestone
     */
    public function save(User $user, Company $company, WorkOrder $workOrder, array $instalments, bool $agreedWithCustomer): void
    {
        $this->guard($user, $company);

        if ($company->accounting_method === AccountingMethod::Kontantmetoden) {
            throw ValidationException::withMessages(['plan' => __('A conto-planer finns än så länge bara med fakturametoden.')]);
        }

        if ($workOrder->price_form !== PriceForm::Fixed) {
            throw ValidationException::withMessages(['plan' => __('En betalningsplan kräver fast pris.')]);
        }

        CurrentCompany::run($company, function () use ($user, $company, $workOrder, $instalments, $agreedWithCustomer): void {
            $customer = Customer::query()->findOrFail($workOrder->customer_id);

            if (Advice::isConsumer($customer) && ! $agreedWithCustomer) {
                throw ValidationException::withMessages(['agreed' => __('Förskott och a conto kräver en särskild överenskommelse med konsumenten. Bekräfta att planen är avtalad med kunden.')]);
            }

            $rows = [];

            foreach ($instalments as $index => $instalment) {
                $amount = Input::money($instalment['amount'] ?? null, "instalments.{$index}.amount");
                $label = trim((string) ($instalment['label'] ?? ''));

                if ($amount === null || $amount <= 0) {
                    throw ValidationException::withMessages(["instalments.{$index}.amount" => __('Skriv beloppet exklusive moms.')]);
                }

                $due = filled($instalment['due_on'] ?? null) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $instalment['due_on']) : null;

                $rows[] = [
                    'position' => $index,
                    'label' => mb_substr($label ?: __('Delbetalning :number', ['number' => $index + 1]), 0, 255),
                    'amount_ore' => $amount,
                    'due_on' => $due ?: null,
                    'milestone' => filled($instalment['milestone'] ?? null) ? mb_substr((string) $instalment['milestone'], 0, 255) : null,
                ];
            }

            if (array_sum(array_column($rows, 'amount_ore')) >= (int) $workOrder->contract_sum_ore?->ore) {
                throw ValidationException::withMessages(['plan' => __('Delbetalningarna ska tillsammans vara mindre än det fasta priset. Resten tas på slutfakturan.')]);
            }

            DB::transaction(function () use ($user, $company, $workOrder, $rows): void {
                $invoiced = PaymentInstalment::query()->where('work_order_id', $workOrder->id)->whereHas('invoiceLink')->exists();

                if ($invoiced) {
                    throw ValidationException::withMessages(['plan' => __('En delbetalning är redan fakturerad. Planen kan inte längre ändras.')]);
                }

                PaymentInstalment::query()->where('work_order_id', $workOrder->id)->delete();

                foreach ($rows as $row) {
                    PaymentInstalment::create(['work_order_id' => $workOrder->id, ...$row]);
                }

                Audit::record('work_orders.payment_plan_saved', $workOrder, ['instalments' => count($rows), 'total_ore' => array_sum(array_column($rows, 'amount_ore'))], $company, $user);
            });
        });
    }

    /**
     * The instalment as a draft invoice: its share of the contract, labour
     * apart for ROT or RUT, with the hours recorded so far. An a conto
     * invoice may come before the work, so the hours are not required here;
     * the claim to Skatteverket needs them (AO-R14) and the final invoice
     * refuses without them.
     */
    public function invoice(User $user, Company $company, PaymentInstalment $instalment, ?CarbonImmutable $invoiceDate = null): Invoice
    {
        $this->guard($user, $company);
        Gate::forUser($user)->authorize('create', [Invoice::class, $company]);

        return DB::transaction(fn (): Invoice => CurrentCompany::run($company, function () use ($user, $company, $instalment, $invoiceDate): Invoice {
            $workOrder = WorkOrder::query()->whereKey($instalment->work_order_id)->lockForUpdate()->firstOrFail();

            if (WorkOrderInvoice::query()->where('instalment_id', $instalment->id)->exists()) {
                throw ValidationException::withMessages(['instalment' => __('Delbetalningen är redan fakturerad.')]);
            }

            $remaining = InvoiceDraft::contractRemaining($workOrder);
            $net = min($instalment->amount_ore->ore, $remaining['net']);

            if ($net <= 0) {
                throw ValidationException::withMessages(['instalment' => __('Hela det fasta priset är redan fakturerat.')]);
            }

            $labour = min($remaining['labour'], InvoiceDraft::labourShare($net, (int) $workOrder->contract_sum_ore?->ore, (int) $workOrder->contract_labour_ore?->ore));
            $draft = new InvoiceDraft($workOrder);
            $draft->fixedRows('', $net, $labour, true, __('A conto: :label', ['label' => $instalment->label]));
            $draft->contractNet = $net;
            // An a conto invoice reserves no time entries; it claims the labour hours recorded and not yet claimed (review P1-2).
            $draft->claimLabourMinutes($invoiceDate ?? CarbonImmutable::today());

            $invoice = ($this->save)($user, $company, $draft->input($invoiceDate ?? CarbonImmutable::today(), $workOrder->title));

            WorkOrderInvoice::create([
                'work_order_id' => $workOrder->id,
                'invoice_id' => $invoice->id,
                'kind' => WorkOrderInvoice::INSTALMENT,
                'instalment_id' => $instalment->id,
                'contract_net_ore' => $net,
                'labour_minutes' => $invoice->tax_reduction === null ? 0 : $draft->labourMinutes(),
            ]);

            Audit::record('work_orders.instalment_invoiced', $workOrder, ['invoice_id' => $invoice->id, 'instalment_id' => $instalment->id, 'net_ore' => $net], $company, $user);

            return $invoice;
        }));
    }
}
