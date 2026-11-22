<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Domain\Invoicing\AgreementStatus;
use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Invoicing\SalesRowInput;
use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\RecurringPriceMode;
use App\Events\RecurringInvoiceChanged;
use App\Models\Article;
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
     * @param  array<string, mixed>  $input  customer_id, name, interval_months, next_invoice_date, end_date, send_automatically, price_mode (FIXED or FOLLOW_PRICES, ADR-082 decision 9; empty keeps the agreement's), your_reference, our_reference, notes, rows,
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
            'price_mode' => ['nullable', Rule::enum(RecurringPriceMode::class)],
            // "Gäller från" within a sane horizon (ADR-080 review 3): at most ten years back and two years ahead.
            'effective_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.CarbonImmutable::today()->subYears(10)->toDateString(), 'before_or_equal:'.CarbonImmutable::today()->addYears(2)->toDateString()],
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
            'effective_from.after_or_equal' => __('Gäller från kan vara högst tio år tillbaka.'),
            'effective_from.before_or_equal' => __('Gäller från kan vara högst två år framåt.'),
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
            $mode = RecurringPriceMode::tryFrom((string) ($validated['price_mode'] ?? '')) ?? ($agreement !== null ? $agreement->price_mode : RecurringPriceMode::Fixed);
            $rows = $mode === RecurringPriceMode::FollowPrices ? $this->marked($rows, array_values(array_filter((array) $input['rows'], is_array(...))), $agreement) : $rows;
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
                'price_mode' => $validated['price_mode'] ?? ($isNew ? RecurringPriceMode::Fixed->value : $agreement->price_mode->value),
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
                'price_mode' => $agreement->price_mode->value,
            ], $company, $user);

            Event::dispatch(new RecurringInvoiceChanged($company->id, $agreement->id, $user->id, $isNew, $validated['effective_from'] ?? null));

            return $agreement->load('rows');
        }));
    }

    /**
     * On an agreement that follows the price lists, an article row whose
     * price a person typed is MANUAL and is never re-priced by a run. A
     * price equal to the article's base price, or left empty, is not typed
     * by hand and follows the lists; a price equal to a stored row's keeps
     * that row's source, none included (a row that has not run yet, or one saved
     * while the agreement had fixed prices, so switching to FOLLOW_PRICES makes
     * the unchanged rows follow), and a changed price is MANUAL.
     *
     * @param  list<array<string, mixed>>  $rows  parsed rows
     * @param  list<array<string, mixed>>  $input  the rows as sent, in the same order
     * @return list<array<string, mixed>>
     */
    private function marked(array $rows, array $input, ?RecurringInvoice $agreement): array
    {
        $articleIds = array_values(array_filter(array_map(fn (array $row): ?int => $row['article_id'], $rows)));

        if ($articleIds === []) {
            return $rows;
        }

        $base = Article::query()->whereIn('id', $articleIds)->get(['id', 'company_id', 'price_ore'])->mapWithKeys(fn (Article $article): array => [$article->id => $article->price_ore->ore]);
        $stored = $agreement?->exists === true ? $agreement->rows()->whereIn('article_id', $articleIds)->get() : collect();

        foreach ($rows as $index => $row) {
            if ($row['article_id'] === null) {
                continue;
            }

            $price = (int) $row['unit_price_ore'];
            $typedManual = ($input[$index]['price_source'] ?? '') === PriceSource::Manual->value;
            $same = $stored->first(fn (RecurringInvoiceRow $old): bool => $old->article_id === $row['article_id'] && $old->unit_price_ore->ore === $price);

            if ($same !== null && ($same->price_source === PriceSource::Manual || ! $typedManual)) {
                $rows[$index] = [...$row, 'price_source' => $same->price_source?->value, 'price_explanation' => $same->price_explanation];

                continue;
            }

            $followsLists = $price === 0 || (int) ($base[$row['article_id']] ?? -1) === $price;
            $rows[$index] = [...$row, 'price_source' => $followsLists && ! $typedManual ? null : PriceSource::Manual->value, 'price_explanation' => $followsLists && ! $typedManual ? null : ['source' => PriceSource::Manual->value]];
        }

        return $rows;
    }
}
