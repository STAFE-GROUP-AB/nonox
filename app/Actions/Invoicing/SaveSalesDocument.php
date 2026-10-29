<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Invoicing\SalesRowInput;
use App\Models\Company;
use App\Models\Customer;
use App\Models\NumberSeries;
use App\Models\SalesDocument;
use App\Models\SalesDocumentRow;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a quote or an order while it is open. Quotes and
 * orders each have their own number series.
 */
class SaveSalesDocument
{
    /**
     * @param  array<string, mixed>  $input  customer_id, document_date, until_date, your_reference, our_reference, notes, rows (as for invoices)
     */
    public function __invoke(User $user, Company $company, string $kind, array $input, ?SalesDocument $document = null): SalesDocument
    {
        $document === null
            ? Gate::forUser($user)->authorize('create', [SalesDocument::class, $company])
            : Gate::forUser($user)->authorize('update', $document);

        $validated = Validator::make([...$input, 'kind' => $kind], [
            'kind' => ['required', Rule::in([SalesDocument::QUOTE, SalesDocument::ORDER])],
            'customer_id' => ['required', 'integer'],
            'document_date' => ['required', 'date_format:Y-m-d'],
            'until_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:document_date'],
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
            'until_date.after_or_equal' => __('Datumet kan inte vara före dokumentets datum.'),
        ], [
            'document_date' => __('datum'),
        ])->validate();

        return DB::transaction(fn (): SalesDocument => CurrentCompany::run($company, function () use ($validated, $input, $kind, $user, $company, $document): SalesDocument {
            $customer = Customer::query()->find((int) $validated['customer_id'])
                ?? throw ValidationException::withMessages(['customer_id' => __('Välj en kund.')]);

            // validate() keeps only fields with rules, so the rows are read from the input itself.
            $rows = SalesRowInput::parse(array_values(array_filter((array) $input['rows'], is_array(...))));
            $totals = InvoiceCalculator::totals(array_map(fn (array $row): array => ['net' => $row['net_ore'], 'vat_rate' => $row['vat_rate']], $rows));

            $isNew = $document === null;
            $document ??= new SalesDocument(['kind' => $kind, 'created_by' => $user->id]);

            $document->fill([
                'customer_id' => $customer->id,
                'document_date' => $validated['document_date'],
                'until_date' => $validated['until_date'] ?? null,
                'your_reference' => $validated['your_reference'] ?? null,
                'our_reference' => $validated['our_reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ])->forceFill([
                'net_ore' => $totals['net'],
                'vat_ore' => $totals['vat'],
                'rounding_ore' => $totals['rounding'],
                'total_ore' => $totals['total'],
            ]);

            if ($isNew) {
                $document->number = NumberSeries::take($kind === SalesDocument::QUOTE ? NumberSeries::QUOTES : NumberSeries::ORDERS);
            }

            $document->save();

            SalesDocumentRow::query()->where('sales_document_id', $document->id)->delete();

            foreach ($rows as $position => $row) {
                SalesDocumentRow::create(['sales_document_id' => $document->id, 'position' => $position, ...$row]);
            }

            if ($isNew) {
                Audit::record($kind === SalesDocument::QUOTE ? 'quote.created' : 'order.created', $document, [
                    'number' => $document->number,
                    'customer' => $customer->name,
                    'total_ore' => $totals['total'],
                ], $company, $user);
            }

            return $document->load('rows');
        }));
    }
}
