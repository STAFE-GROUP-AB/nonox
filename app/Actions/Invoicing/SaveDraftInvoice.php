<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Domain\Invoicing\ForeignSale;
use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Invoicing\SalesRowInput;
use App\Domain\Invoicing\TaxReduction;
use App\Domain\Vat\OssRates;
use App\Domain\Vat\OssThreshold;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\User;
use App\Support\Currency\ExchangeRate;
use App\Support\Currency\RiksbankRates;
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
 *
 * An invoice in another currency (ADR-025) is written in that currency: the
 * row prices are hundredths of it, and its totals are kept as written,
 * without öresavrundning. Every row is also converted to kronor at the
 * booking rate (half up), and the kronor amounts, with the VAT calculated on
 * the kronor base per rate, are what is booked. One rate serves both the
 * booking and the VAT base.
 *
 * The rate is taken on the day the VAT arises (Mervärdesskattelag
 * (2023:200) 8 kap. 21 §, 7 kap. 3-4 §§): the delivery date when the
 * invoice has one, otherwise the invoice date, which then stands in for
 * the delivery (enligt AI-utredning 2026-11-02, juristgranskning återstår).
 * It is Riksbanken's mid rate on that day (or the last banking day before;
 * today's when the day is in the future), or the rate the person typed. A
 * credit invoice always takes the currency and rate of the invoice it
 * credits.
 *
 * A sale to a consumer in another EU country (OSS) carries Swedish VAT while
 * the company is under the threshold in OssThreshold and has not elected
 * taxation in the buyer's country; otherwise that country's rate. A credit
 * invoice follows the invoice it credits.
 *
 * TODO(legal-verify): whether Riksbanken's mid rate counts as "the latest
 * average rate on the most representative currency market in Sweden" (8 kap.
 * 21 §); Skatteverket names the ECB rate and Nasdaq OMX Stockholm's fixed
 * mid rate. The invoice date is used for advance invoices (7 kap. 7 §: the
 * day the payment is received) and for construction services under
 * omvänd betalningsskyldighet (7 kap. 8 §: the invoice date) alike.
 */
class SaveDraftInvoice
{
    public function __construct(private readonly RiksbankRates $rates) {}

    /**
     * @param  array<string, mixed>  $input  customer_id, invoice_date, due_date (optional), delivery_date,
     *                                       your_reference, our_reference, notes, reverse_charge and
     *                                       foreign_sale (optional, otherwise the draft's or the customer's
     *                                       setting; an empty foreign_sale means a domestic sale),
     *                                       rows (list of article (number, optional), description,
     *                                       quantity, unit, price (kronor), discount (percent),
     *                                       vat_rate, account (number, optional)),
     *                                       currency (ISO 4217, optional: the draft's, otherwise SEK;
     *                                       prices are then in that currency) and exchange_rate
     *                                       (kronor per unit as typed, optional: Riksbanken's rate)
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
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'exchange_rate' => ['nullable', 'string', 'max:20'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.vat_rate' => ['required', 'integer', Rule::in(InvoiceCalculator::VAT_RATES)],
        ], [
            'customer_id.required' => __('Välj en kund.'),
            'rows.required' => __('Lägg till minst en rad.'),
            'rows.min' => __('Lägg till minst en rad.'),
            'rows.*.description.required' => __('Varje rad behöver en benämning.'),
            'due_date.after_or_equal' => __('Förfallodagen kan inte vara före fakturadatum.'),
            'currency.regex' => __('Valutan skrivs med tre bokstäver, till exempel EUR eller USD.'),
        ], [
            'invoice_date' => __('fakturadatum'),
            'due_date' => __('förfallodag'),
        ])->validate();

        $original = $draft !== null && $draft->credits_invoice_id !== null
            ? CurrentCompany::run($company, fn (): ?Invoice => Invoice::query()->find($draft->credits_invoice_id))
            : null;

        [$currency, $bookingRate] = $this->currency($validated, $draft, $original);

        return DB::transaction(fn (): Invoice => CurrentCompany::run($company, function () use ($validated, $input, $user, $company, $draft, $original, $currency, $bookingRate): Invoice {
            $customer = Customer::query()->find((int) $validated['customer_id'])
                ?? throw ValidationException::withMessages(['customer_id' => __('Välj en kund.')]);

            $customer->ensureNotRestricted('customer_id');

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

            // A sale to a consumer elsewhere in the EU carries Swedish VAT under the threshold, else that country's VAT (OSS).
            $swedishVat = false;

            if ($foreignSale === ForeignSale::EuConsumerOss) {
                $net = array_sum(array_map(fn (array $row): int => $bookingRate?->toOre((int) $row['net_ore']) ?? (int) $row['net_ore'], $rows));
                $swedishVat = $original instanceof Invoice
                    ? $original->swedish_vat_under_threshold
                    : OssThreshold::check($company, $invoiceDate, $net, $draft?->exists === true ? $draft->id : null)['swedish_vat'];
            }

            if ($foreignSale === ForeignSale::EuConsumerOss && ! $swedishVat) {
                $rate = OssRates::standard($customer->country, $invoiceDate)
                    ?? throw ValidationException::withMessages(['foreign_sale' => __('Det finns ingen momssats för OSS i kundens land (:country) på fakturadatumet.', ['country' => $customer->country])]);

                foreach ($rows as $index => $row) {
                    $rows[$index]['vat_rate'] = $rate;
                }
            }

            // The buyer accounts for the VAT, or none is due in Sweden, so the invoice carries none.
            if ($reverseCharge || ($foreignSale !== null && $foreignSale->isVatFree())) {
                foreach ($rows as $index => $row) {
                    $rows[$index]['vat_rate'] = 0;
                }
            }

            $taxReduction = $validated['tax_reduction'] ?? null;

            foreach ($rows as $index => $row) {
                $rows[$index]['is_labour'] = $taxReduction !== null && (bool) ($rowInput[$index]['labour'] ?? false);
            }

            if ($taxReduction !== null && $foreignSale !== null) {
                throw ValidationException::withMessages(['tax_reduction' => __('ROT- och RUT-avdrag gäller arbete i Sverige och kan inte kombineras med försäljning utomlands.')]);
            }

            if ($taxReduction !== null && $bookingRate !== null) {
                throw ValidationException::withMessages(['tax_reduction' => __('ROT- och RUT-avdrag räknas i kronor. Fakturera i SEK för att använda avdraget.')]);
            }

            $foreignTotals = null;

            if ($bookingRate !== null) {
                $foreignTotals = InvoiceCalculator::totals(array_map(fn (array $row): array => ['net' => $row['net_ore'], 'vat_rate' => $row['vat_rate']], $rows), roundToKronor: false);

                foreach ($rows as $index => $row) {
                    $rows[$index] = [
                        ...$row,
                        'foreign_unit_price_minor' => $row['unit_price_ore'],
                        'foreign_net_minor' => $row['net_ore'],
                        'unit_price_ore' => $bookingRate->toOre($row['unit_price_ore']),
                        'net_ore' => $bookingRate->toOre($row['net_ore']),
                    ];
                }
            }

            $totals = InvoiceCalculator::totals(array_map(fn (array $row): array => ['net' => $row['net_ore'], 'vat_rate' => $row['vat_rate']], $rows), roundToKronor: $bookingRate === null);

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
                'invoice_currency' => $currency,
                'exchange_rate_micros' => $bookingRate?->micros,
                'exchange_rate_date' => $bookingRate?->date,
                'exchange_rate_source' => $bookingRate?->source,
                'foreign_net_minor' => $foreignTotals['net'] ?? null,
                'foreign_vat_minor' => $foreignTotals['vat'] ?? null,
                'foreign_total_minor' => $foreignTotals['total'] ?? null,
                'swedish_vat_under_threshold' => $swedishVat,
            ])->save();

            InvoiceRow::query()->where('invoice_id', $invoice->id)->delete();

            foreach ($rows as $position => $row) {
                InvoiceRow::create(['invoice_id' => $invoice->id, 'position' => $position, ...$row]);
            }

            return $invoice->load('rows');
        }));
    }

    /**
     * The invoice's currency and its booking rate; null for kronor. A credit
     * invoice keeps the currency and rate of the invoice it credits.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: string, 1: ?ExchangeRate}
     */
    private function currency(array $validated, ?Invoice $draft, ?Invoice $original): array
    {
        if ($original instanceof Invoice) {
            return [$original->invoice_currency, $original->bookingRate()];
        }

        $currency = strtoupper(trim((string) ($validated['currency'] ?? '')));
        $currency = $currency !== '' ? $currency : ($draft !== null && $draft->exists ? $draft->invoice_currency : 'SEK');

        if ($currency === 'SEK') {
            return ['SEK', null];
        }

        $date = self::vatDate($validated);
        $riksbank = $this->rates->rate($currency, $date);
        $typed = trim((string) ($validated['exchange_rate'] ?? ''));

        if ($typed === '') {
            return [$currency, $riksbank ?? throw ValidationException::withMessages(['exchange_rate' => __('Riksbanken har ingen kurs för :currency den :date. Skriv kursen i kronor per :currency, till exempel 11,2345.', ['currency' => $currency, 'date' => $date])])];
        }

        $rate = ExchangeRate::parse($currency, $typed, $date)
            ?? throw ValidationException::withMessages(['exchange_rate' => __('Skriv kursen i kronor per :currency, till exempel 11,2345.', ['currency' => $currency])]);

        return [$currency, $riksbank !== null && $riksbank->micros === $rate->micros ? $riksbank : $rate];
    }

    /**
     * The day the VAT arises, which the exchange rate is taken for: the
     * delivery date, or the invoice date when there is none.
     *
     * @param  array<string, mixed>  $input  delivery_date and invoice_date as Y-m-d
     */
    public static function vatDate(array $input): string
    {
        $delivery = (string) ($input['delivery_date'] ?? '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery) === 1 ? $delivery : (string) $input['invoice_date'];
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
