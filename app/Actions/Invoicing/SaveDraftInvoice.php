<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Domain\Invoicing\ForeignSale;
use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Invoicing\SalesRowInput;
use App\Domain\Invoicing\TaxReduction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a draft invoice and recalculates its amounts. Nothing
 * is booked until the invoice is issued.
 */
class SaveDraftInvoice
{
    /**
     * @param  array<string, mixed>  $input  customer_id, invoice_date, due_date (optional), delivery_date,
     *                                       your_reference, our_reference, notes, reverse_charge and
     *                                       foreign_sale (optional, otherwise the draft's or the customer's
     *                                       setting; an empty foreign_sale means a domestic sale),
     *                                       rows (list of article (number, optional), description,
     *                                       quantity, unit, price (kronor), discount (percent),
     *                                       vat_rate, account (number, optional))
     */
    public function __invoke(User $user, Company $company, array $input, ?Invoice $draft = null): Invoice
    {
        $draft === null
            ? Gate::forUser($user)->authorize('create', [Invoice::class, $company])
            : Gate::forUser($user)->authorize('update', $draft);

        $validated = Validator::make($input, [
            'customer_id' => ['required', 'integer'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'your_reference' => ['nullable', 'string', 'max:255'],
            'our_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reverse_charge' => ['nullable', 'boolean'],
            'foreign_sale' => ['nullable', Rule::enum(ForeignSale::class)],
            'tax_reduction' => ['nullable', Rule::in([TaxReduction::ROT, TaxReduction::RUT])],
            'property_designation' => ['nullable', 'string', 'max:255'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.vat_rate' => ['required', 'integer', Rule::in(InvoiceCalculator::VAT_RATES)],
        ], [
            'customer_id.required' => __('Välj en kund.'),
            'rows.required' => __('Lägg till minst en rad.'),
            'rows.min' => __('Lägg till minst en rad.'),
            'rows.*.description.required' => __('Varje rad behöver en benämning.'),
            'due_date.after_or_equal' => __('Förfallodagen kan inte vara före fakturadatum.'),
        ], [
            'invoice_date' => __('fakturadatum'),
            'due_date' => __('förfallodag'),
        ])->validate();

        return DB::transaction(fn (): Invoice => CurrentCompany::run($company, function () use ($validated, $input, $user, $draft): Invoice {
            $customer = Customer::query()->find((int) $validated['customer_id'])
                ?? throw ValidationException::withMessages(['customer_id' => __('Välj en kund.')]);

            $invoiceDate = CarbonImmutable::createFromFormat('!Y-m-d', (string) $validated['invoice_date']) ?: CarbonImmutable::today();
            $dueDate = isset($validated['due_date'])
                ? (CarbonImmutable::createFromFormat('!Y-m-d', (string) $validated['due_date']) ?: $invoiceDate)
                : $invoiceDate->addDays($customer->payment_terms_days);

            // validate() keeps only fields with rules, so the rows are read from the input itself.
            /** @var list<array<string, mixed>> $rowInput */
            $rowInput = array_values(array_filter((array) $input['rows'], is_array(...)));
            $rows = SalesRowInput::parse($rowInput, $draft?->isCredit() ?? false);
            $reverseCharge = isset($validated['reverse_charge'])
                ? (bool) $validated['reverse_charge']
                : ($draft !== null && $draft->exists ? $draft->reverse_charge : $customer->reverse_charge_construction);

            $foreignSale = array_key_exists('foreign_sale', $input)
                ? ForeignSale::tryFrom((string) ($validated['foreign_sale'] ?? ''))
                : ($draft !== null && $draft->exists ? $draft->foreign_sale : $customer->foreign_sale);

            if ($foreignSale !== null && $reverseCharge) {
                throw ValidationException::withMessages(['foreign_sale' => __('Välj antingen omvänd betalningsskyldighet för byggtjänster eller försäljning utomlands, inte båda.')]);
            }

            // The buyer accounts for the VAT, or none is due in Sweden, so the invoice carries none.
            if ($reverseCharge || $foreignSale !== null) {
                foreach ($rows as $index => $row) {
                    $rows[$index]['vat_rate'] = 0;
                }
            }

            $taxReduction = $validated['tax_reduction'] ?? null;

            foreach ($rows as $index => $row) {
                $rows[$index]['is_labour'] = $taxReduction !== null && (bool) ($rowInput[$index]['labour'] ?? false);
            }

            $totals = InvoiceCalculator::totals(array_map(fn (array $row): array => ['net' => $row['net_ore'], 'vat_rate' => $row['vat_rate']], $rows));

            if ($taxReduction !== null && $foreignSale !== null) {
                throw ValidationException::withMessages(['tax_reduction' => __('ROT- och RUT-avdrag gäller arbete i Sverige och kan inte kombineras med försäljning utomlands.')]);
            }

            $deduction = $taxReduction === null ? 0 : $this->deduction($taxReduction, $rows, $customer, $invoiceDate, $reverseCharge, $draft);

            $invoice = $draft ?? new Invoice(['created_by' => $user->id]);
            $invoice->fill([
                'customer_id' => $customer->id,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'delivery_date' => $validated['delivery_date'] ?? null,
                'your_reference' => $validated['your_reference'] ?? null,
                'our_reference' => $validated['our_reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'reverse_charge' => $reverseCharge,
                'foreign_sale' => $foreignSale,
                'tax_reduction' => $taxReduction,
                'property_designation' => $taxReduction === TaxReduction::ROT ? ($validated['property_designation'] ?? null) : null,
            ]);
            $invoice->forceFill([
                'net_ore' => $totals['net'],
                'vat_ore' => $totals['vat'],
                'rounding_ore' => $totals['rounding'],
                'total_ore' => $totals['total'] - $deduction,
                'tax_reduction_ore' => $deduction,
            ])->save();

            InvoiceRow::query()->where('invoice_id', $invoice->id)->delete();

            foreach ($rows as $position => $row) {
                InvoiceRow::create(['invoice_id' => $invoice->id, 'position' => $position, ...$row]);
            }

            return $invoice->load('rows');
        }));
    }

    /**
     * The ROT or RUT deduction: the share of the labour rows including VAT,
     * within what is left of the buyer's ceilings this calendar year on the
     * company's other invoices.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function deduction(string $type, array $rows, Customer $customer, CarbonImmutable $date, bool $reverseCharge, ?Invoice $draft): int
    {
        if ($reverseCharge) {
            throw ValidationException::withMessages(['tax_reduction' => __('En faktura med omvänd betalningsskyldighet kan inte ha ROT- eller RUT-avdrag.')]);
        }

        if (blank($customer->personal_number)) {
            throw ValidationException::withMessages(['tax_reduction' => __('ROT och RUT gäller privatpersoner. Fyll i kundens personnummer.')]);
        }

        $rate = TaxReduction::rate($type, $date)
            ?? throw ValidationException::withMessages(['tax_reduction' => __('Det finns ingen regel för :type på fakturadatumet.', ['type' => TaxReduction::label($type)])]);

        $labour = array_values(array_filter($rows, fn (array $row): bool => (bool) $row['is_labour']));

        if ($labour === []) {
            throw ValidationException::withMessages(['tax_reduction' => __('Markera vilka rader som är arbetskostnad.')]);
        }

        $labourTotals = InvoiceCalculator::totals(array_map(fn (array $row): array => ['net' => (int) $row['net_ore'], 'vat_rate' => (int) $row['vat_rate']], $labour));
        $used = Invoice::query()->where('customer_id', $customer->id)->whereNotNull('tax_reduction')
            ->whereYear('invoice_date', $date->year)->where('status', '<>', 'DRAFT')
            ->when($draft !== null && $draft->exists, fn ($query) => $query->whereKeyNot($draft?->id))
            ->selectRaw('tax_reduction, SUM(tax_reduction_ore) AS used')->groupBy('tax_reduction')->pluck('used', 'tax_reduction')
            ->map(fn (mixed $used): int => (int) $used);
        $room = TaxReduction::CEILINGS['TOTAL'] - $used->sum();

        if ($type === TaxReduction::ROT) {
            $room = min($room, TaxReduction::CEILINGS[TaxReduction::ROT] - (int) ($used[TaxReduction::ROT] ?? 0));
        }

        return TaxReduction::amount($labourTotals['net'] + $labourTotals['vat'], $rate, $room);
    }
}
