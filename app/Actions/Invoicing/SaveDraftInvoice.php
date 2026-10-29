<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Invoicing\SalesRowInput;
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
     *                                       your_reference, our_reference, notes,
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
            $totals = InvoiceCalculator::totals(array_map(fn (array $row): array => ['net' => $row['net_ore'], 'vat_rate' => $row['vat_rate']], $rows));

            $invoice = $draft ?? new Invoice(['created_by' => $user->id]);
            $invoice->fill([
                'customer_id' => $customer->id,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'delivery_date' => $validated['delivery_date'] ?? null,
                'your_reference' => $validated['your_reference'] ?? null,
                'our_reference' => $validated['our_reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);
            $invoice->forceFill([
                'net_ore' => $totals['net'],
                'vat_ore' => $totals['vat'],
                'rounding_ore' => $totals['rounding'],
                'total_ore' => $totals['total'],
            ])->save();

            InvoiceRow::query()->where('invoice_id', $invoice->id)->delete();

            foreach ($rows as $position => $row) {
                InvoiceRow::create(['invoice_id' => $invoice->id, 'position' => $position, ...$row]);
            }

            return $invoice->load('rows');
        }));
    }
}
