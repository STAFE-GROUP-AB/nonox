<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Domain\Invoicing\AgreementStatus;
use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Invoicing\SalesRowInput;
use App\Events\RecurringInvoiceChanged;
use App\Models\Company;
use App\Models\Customer;
use App\Models\NumberSeries;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceRow;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Peppol\PeppolModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes an invoicing agreement: the customer, how often, the
 * next invoice date and the rows (as on an invoice). Invoices already made
 * are not affected by a change.
 */
class SaveRecurringInvoice
{
    /**
     * @param  array<string, mixed>  $input  customer_id, name, interval_months, next_invoice_date, end_date, send_automatically, your_reference, our_reference, notes, rows,
     *                                       effective_from (optional "Gäller från" for NONOX SaaS, ADR-080; passed on in the event only)
     */
    public function __invoke(User $user, Company $company, array $input, ?RecurringInvoice $agreement = null): RecurringInvoice
    {
        $agreement === null
            ? Gate::forUser($user)->authorize('create', [RecurringInvoice::class, $company])
            : Gate::forUser($user)->authorize('update', $agreement);

        $validated = Validator::make($input, [
            'customer_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'interval_months' => ['required', 'integer', Rule::in(RecurringInvoice::INTERVALS)],
            'next_invoice_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:next_invoice_date'],
            'send_automatically' => ['boolean'],
            'your_reference' => ['nullable', 'string', 'max:255'],
            'our_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.vat_rate' => ['required', 'integer', Rule::in(InvoiceCalculator::VAT_RATES)],
        ], [
            'customer_id.required' => __('Välj en kund.'),
            'name.required' => __('Ge avtalet ett namn, till exempel Lunchavtal 2027.'),
            'rows.required' => __('Lägg till minst en rad.'),
            'rows.min' => __('Lägg till minst en rad.'),
            'rows.*.description.required' => __('Varje rad behöver en benämning.'),
            'end_date.after_or_equal' => __('Slutdatumet kan inte vara före nästa fakturadatum.'),
        ], [
            'next_invoice_date' => __('nästa fakturadatum'),
            'interval_months' => __('intervall'),
            'effective_from' => __('gäller från'),
        ])->validate();

        return DB::transaction(fn (): RecurringInvoice => CurrentCompany::run($company, function () use ($validated, $input, $user, $company, $agreement): RecurringInvoice {
            $customer = Customer::query()->find((int) $validated['customer_id'])
                ?? throw ValidationException::withMessages(['customer_id' => __('Välj en kund.')]);

            $customer->ensureNotRestricted('customer_id');

            // A customer invoiced via Peppol needs no e-mail address (ADR-050).
            $viaPeppol = $customer->invoice_delivery === 'PEPPOL' && filled($customer->peppol_id) && app(ModuleManager::class)->isEnabled(PeppolModule::KEY, $company);

            if ((bool) ($validated['send_automatically'] ?? false) && blank($customer->email) && ! $viaPeppol) {
                throw ValidationException::withMessages(['send_automatically' => __(':name har ingen e-postadress, så fakturorna kan inte skickas automatiskt.', ['name' => $customer->name])]);
            }

            // validate() keeps only fields with rules, so the rows are read from the input itself.
            $rows = SalesRowInput::parse(array_values(array_filter((array) $input['rows'], is_array(...))));
            $totals = InvoiceCalculator::totals(array_map(fn (array $row): array => ['net' => $row['net_ore'], 'vat_rate' => $row['vat_rate']], $rows));
            $next = CarbonImmutable::parse($validated['next_invoice_date']);

            $isNew = $agreement === null;
            $agreement ??= new RecurringInvoice(['created_by' => $user->id]);

            $agreement->fill([
                'customer_id' => $customer->id,
                'name' => $validated['name'],
                'interval_months' => (int) $validated['interval_months'],
                'invoice_day' => $next->day,
                'next_invoice_date' => $next,
                'end_date' => $validated['end_date'] ?? null,
                'send_automatically' => (bool) ($validated['send_automatically'] ?? false),
                'your_reference' => $validated['your_reference'] ?? null,
                'our_reference' => $validated['our_reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ])->forceFill([
                'net_ore' => $totals['net'],
                'total_ore' => $totals['total'],
            ]);

            if ($isNew) {
                $agreement->number = NumberSeries::take(NumberSeries::AGREEMENTS);
            }

            if (! $isNew && $agreement->status === AgreementStatus::Ended && $agreement->end_date === null) {
                $agreement->status = AgreementStatus::Active;
            }

            $agreement->save();

            RecurringInvoiceRow::query()->where('recurring_invoice_id', $agreement->id)->delete();

            foreach ($rows as $position => $row) {
                RecurringInvoiceRow::create(['recurring_invoice_id' => $agreement->id, 'position' => $position, ...$row]);
            }

            Audit::record($isNew ? 'agreement.created' : 'agreement.updated', $agreement, [
                'number' => $agreement->number,
                'customer' => $customer->name,
                'interval_months' => $agreement->interval_months,
                'next_invoice_date' => $next->toDateString(),
                'total_ore' => $totals['total'],
            ], $company, $user);

            Event::dispatch(new RecurringInvoiceChanged($company->id, $agreement->id, $user->id, $isNew, $validated['effective_from'] ?? null));

            return $agreement->load('rows');
        }));
    }
}
