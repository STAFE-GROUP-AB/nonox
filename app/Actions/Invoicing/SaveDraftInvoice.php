<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Domain\Invoicing\ForeignSale;
use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Invoicing\SalesRowInput;
use App\Domain\Invoicing\TaxReduction;
use App\Domain\Invoicing\VatExemptionReason;
use App\Domain\Vat\OssRates;
use App\Domain\Vat\OssThreshold;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\InvoiceTaxReductionBuyer;
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
            'unregistered_vat_confirmed' => ['nullable', 'boolean'],
            'foreign_sale' => ['nullable', Rule::enum(ForeignSale::class)],
            'tax_reduction' => ['nullable', Rule::in([TaxReduction::ROT, TaxReduction::RUT])],
            'property_designation' => ['nullable', 'string', 'max:255'],
            'labour_hours' => ['nullable', 'string', 'max:20'],
            'labour_hours_milli' => ['nullable', 'integer', 'min:0'],
            'labour_hours_reported_on' => ['nullable', 'array'],
            'labour_hours_reported_on.*' => ['uuid'],
            'work_description' => ['nullable', 'string', 'max:255'],
            'tax_reduction_buyers' => ['nullable', 'array'],
            'tax_reduction_buyers.*.customer_id' => ['required', 'integer'],
            'tax_reduction_buyers.*.share_bp' => ['required', 'integer', 'min:1', 'max:10000'],
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

            // A credit invoice follows the invoice it credits: same ROT or RUT, or none.
            $taxReduction = $original instanceof Invoice ? $original->tax_reduction : ($validated['tax_reduction'] ?? null);

            foreach ($rows as $index => $row) {
                $rows[$index]['is_labour'] = $taxReduction !== null && (bool) ($rowInput[$index]['labour'] ?? false);
            }

            // A row sold in Sweden at 0 % says why, unless the company's own exemption covers the invoice (#54, #51).
            $domestic = ! $reverseCharge && $foreignSale === null;
            $companyExempt = ! $company->isVatRegistered() && $company->vat_exemption?->invoiceText() !== null;

            foreach ($rows as $index => $row) {
                $reason = $domestic && ! $companyExempt && $row['vat_rate'] === 0
                    ? (VatExemptionReason::tryFrom((string) ($rowInput[$index]['vat_exemption'] ?? '')) ?? VatExemptionReason::Other)
                    : null;
                $rows[$index]['vat_exemption'] = $reason?->value;
                $rows[$index]['vat_exemption_text'] = $reason?->invoiceText();
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

            $buyers = $taxReduction === null ? [] : $this->buyers($validated, $input, $customer, $draft, $original);

            [$deduction, $buyerDeductions] = match (true) {
                $taxReduction === null => [0, []],
                $original instanceof Invoice => $this->creditedSplit($this->creditedDeduction($rows, $original, $draft), $buyers, $original, $draft),
                $buyers !== [] => $this->splitDeduction($taxReduction, $rows, $buyers, $dueDate, $reverseCharge, $draft),
                default => [$this->deduction($taxReduction, $rows, $customer, $dueDate, $reverseCharge, $draft), []],
            };

            [$labourHours, $workDescription] = $this->workDetails($validated, $input, $taxReduction, $draft, $original);

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
                'labour_hours_milli' => $labourHours,
                'labour_hours_reported_on' => $this->reportedOn($input, $validated, $labourHours, $taxReduction, $draft, $original),
                'work_description' => $workDescription,
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
                'unregistered_vat_confirmed' => (bool) ($validated['unregistered_vat_confirmed'] ?? ($draft !== null && $draft->exists && $draft->unregistered_vat_confirmed)),
            ])->save();

            InvoiceRow::query()->where('invoice_id', $invoice->id)->delete();

            foreach ($rows as $position => $row) {
                InvoiceRow::create(['invoice_id' => $invoice->id, 'position' => $position, ...$row]);
            }

            InvoiceTaxReductionBuyer::query()->where('invoice_id', $invoice->id)->delete();

            foreach ($buyerDeductions as $buyer) {
                InvoiceTaxReductionBuyer::create(['invoice_id' => $invoice->id, ...$buyer]);
            }

            return $invoice->load('rows', 'taxReductionBuyers');
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
     * The hours worked and the kind of work on a ROT or RUT invoice
     * (lag (2009:194) 9 § p. 4, ADR-074 §5): given in milli-hours by a work
     * order, or typed as hours ("4" or "1,5") in the invoice form. Kept
     * from the draft when the input does not name them; none without a tax
     * reduction. A credit invoice records none: the hours belong to the
     * work, which the credit does not undo.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $input
     * @return array{0: ?int, 1: ?string}
     */
    private function workDetails(array $validated, array $input, ?string $taxReduction, ?Invoice $draft, ?Invoice $original): array
    {
        if ($taxReduction === null || $original instanceof Invoice) {
            return [null, null];
        }

        $kept = $draft !== null && $draft->exists;
        $hours = $kept ? $draft->labour_hours_milli : null;

        if (array_key_exists('labour_hours_milli', $input)) {
            $hours = isset($validated['labour_hours_milli']) ? (int) $validated['labour_hours_milli'] : null;
        } elseif (array_key_exists('labour_hours', $input)) {
            $typed = str_replace([' ', "\u{a0}", ','], ['', '', '.'], trim((string) ($validated['labour_hours'] ?? '')));

            if ($typed !== '' && preg_match('/^\d{1,6}(\.\d{1,3})?$/', $typed) !== 1) {
                throw ValidationException::withMessages(['labour_hours' => __('Skriv antalet arbetade timmar, till exempel 4 eller 1,5.')]);
            }

            $hours = $typed === '' ? null : (int) round(((float) $typed) * 1000);
        }

        $description = array_key_exists('work_description', $input)
            ? (trim((string) ($validated['work_description'] ?? '')) ?: null)
            : ($kept ? $draft->work_description : null);

        return [$hours, $description];
    }

    /**
     * The earlier invoices that already report the hours of a ROT or RUT
     * invoice without new hours of its own (re-check of ADR-074): printed as
     * "Arbetade timmar: redovisade på faktura N" instead of 0. Only invoices
     * of the company; none once the invoice records hours itself.
     *
     * TODO(legal-verify): whether a ROT/RUT invoice without new hours is
     * accepted in the claim (lag (2009:194) 9 § p. 4).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $validated
     * @return ?list<string>
     */
    private function reportedOn(array $input, array $validated, ?int $labourHours, ?string $taxReduction, ?Invoice $draft, ?Invoice $original): ?array
    {
        if ($taxReduction === null || $original instanceof Invoice || ($labourHours ?? 0) > 0) {
            return null;
        }

        $ids = array_key_exists('labour_hours_reported_on', $input)
            ? array_values(array_map('strval', (array) ($validated['labour_hours_reported_on'] ?? [])))
            : ($draft !== null && $draft->exists ? ($draft->labour_hours_reported_on ?? []) : []);

        if ($ids === []) {
            return null;
        }

        /** @var list<string> $known */
        $known = Invoice::query()->whereKey($ids)->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all();

        return $known === [] ? null : $known;
    }

    /**
     * The buyers who share the ROT or RUT deduction (ADR-074 O6): given in
     * the input, otherwise kept from the draft, or the credited invoice's.
     * Empty for the usual single buyer, the invoice's customer.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $input
     * @return list<array{customer: Customer, share_bp: int}>
     */
    private function buyers(array $validated, array $input, Customer $customer, ?Invoice $draft, ?Invoice $original): array
    {
        /** @var list<array{customer_id: int|string, share_bp: int|string}> $source */
        $source = match (true) {
            $original instanceof Invoice => $original->taxReductionBuyers()->get(['customer_id', 'share_bp'])->map(fn (InvoiceTaxReductionBuyer $buyer): array => ['customer_id' => $buyer->customer_id, 'share_bp' => $buyer->share_bp])->all(),
            array_key_exists('tax_reduction_buyers', $input) => array_values((array) ($validated['tax_reduction_buyers'] ?? [])),
            $draft !== null && $draft->exists => $draft->taxReductionBuyers()->get(['customer_id', 'share_bp'])->map(fn (InvoiceTaxReductionBuyer $buyer): array => ['customer_id' => $buyer->customer_id, 'share_bp' => $buyer->share_bp])->all(),
            default => [],
        };

        if ($source === []) {
            return [];
        }

        $customers = Customer::query()->whereKey(array_map(fn (array $buyer): int => (int) $buyer['customer_id'], $source))->get()->keyBy('id');
        $buyers = [];

        foreach ($source as $index => $buyer) {
            $each = $customers->get((int) $buyer['customer_id'])
                ?? throw ValidationException::withMessages(["tax_reduction_buyers.{$index}.customer_id" => __('Köparen finns inte i kundregistret.')]);

            if (blank($each->personal_number)) {
                throw ValidationException::withMessages(["tax_reduction_buyers.{$index}.customer_id" => __('ROT och RUT gäller privatpersoner. Fyll i personnummer för :name.', ['name' => $each->name])]);
            }

            $buyers[] = ['customer' => $each, 'share_bp' => (int) $buyer['share_bp']];
        }

        if (array_sum(array_column($buyers, 'share_bp')) !== 10_000) {
            throw ValidationException::withMessages(['tax_reduction_buyers' => __('Köparnas andelar ska tillsammans vara 100 procent.')]);
        }

        $ids = array_map(fn (array $buyer): int => $buyer['customer']->id, $buyers);

        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['tax_reduction_buyers' => __('Samma köpare kan bara finnas med en gång.')]);
        }

        if (! in_array($customer->id, $ids, true)) {
            throw ValidationException::withMessages(['tax_reduction_buyers' => __('Fakturans kund ska vara en av köparna.')]);
        }

        return $buyers;
    }

    /**
     * The deduction split between several buyers (ADR-074 O6): each buyer's
     * share of the labour cost including VAT, with the rate on that, within
     * the buyer's own ceilings (Inkomstskattelag (1999:1229) 67 kap. 19 §:
     * the ceilings are per person). The last buyer takes the öre left of
     * the labour, so the shares add up.
     *
     * TODO(legal-verify): whether the shares must follow what each buyer
     * paid, and how one invoice with several buyers is claimed in
     * Skatteverket's e-service (ADR-074 O6).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{customer: Customer, share_bp: int}>  $buyers
     * @return array{0: int, 1: list<array{customer_id: int, share_bp: int, deduction_ore: int}>}
     */
    private function splitDeduction(string $type, array $rows, array $buyers, CarbonImmutable $date, bool $reverseCharge, ?Invoice $draft): array
    {
        if ($reverseCharge) {
            throw ValidationException::withMessages(['tax_reduction' => __('En faktura med omvänd betalningsskyldighet kan inte ha ROT- eller RUT-avdrag.')]);
        }

        $labour = array_values(array_filter($rows, fn (array $row): bool => (bool) $row['is_labour']));

        if ($labour === []) {
            throw ValidationException::withMessages(['tax_reduction' => __('Markera vilka rader som är arbetskostnad.')]);
        }

        $labourTotals = InvoiceCalculator::totals(array_map(fn (array $row): array => ['net' => (int) $row['net_ore'], 'vat_rate' => (int) $row['vat_rate']], $labour));
        $gross = $labourTotals['net'] + $labourTotals['vat'];
        $left = $gross;
        $last = array_key_last($buyers);
        $split = [];

        foreach ($buyers as $index => $buyer) {
            $share = $index === $last ? $left : intdiv($gross * $buyer['share_bp'], 10_000);
            $left -= $share;
            $allowed = TaxReduction::allowed($type, $share, $buyer['customer'], $date, $draft?->exists === true ? $draft->id : null)
                ?? throw ValidationException::withMessages(['tax_reduction' => __('Det finns ingen regel för :type på förfallodagen.', ['type' => TaxReduction::label($type)])]);
            $split[] = ['customer_id' => $buyer['customer']->id, 'share_bp' => $buyer['share_bp'], 'deduction_ore' => $allowed];
        }

        return [array_sum(array_column($split, 'deduction_ore')), $split];
    }

    /**
     * A credit invoice's deduction split between the buyers of the invoice
     * it credits: each buyer's own deduction on the original, net of that
     * buyer's earlier credits, reversed in proportion to the credit in whole
     * kronor; the last buyer with something left takes the remainder. A
     * buyer who got no deduction gets none back.
     *
     * @param  list<array{customer: Customer, share_bp: int}>  $buyers
     * @return array{0: int, 1: list<array{customer_id: int, share_bp: int, deduction_ore: int}>}
     */
    private function creditedSplit(int $deduction, array $buyers, Invoice $original, ?Invoice $draft): array
    {
        if ($buyers === []) {
            return [$deduction, []];
        }

        // What each buyer still has of the original deduction: the original's own row, net of earlier issued credits.
        $original->loadMissing(['taxReductionBuyers', 'creditInvoices.taxReductionBuyers']);
        $remaining = [];

        foreach ($buyers as $buyer) {
            $id = $buyer['customer']->id;
            $remaining[$id] = $original->taxReductionBuyers->firstWhere('customer_id', $id)?->deduction_ore->ore ?? 0;

            foreach ($original->creditInvoices as $earlier) {
                if ($earlier->isDraft() || ($draft !== null && $earlier->id === $draft->id)) {
                    continue;
                }

                $remaining[$id] += $earlier->taxReductionBuyers->firstWhere('customer_id', $id)?->deduction_ore->ore ?? 0;
            }

            $remaining[$id] = max(0, $remaining[$id]);
        }

        $total = array_sum($remaining);
        $credited = -$deduction;
        $lastWithRoom = array_key_last(array_filter($remaining, fn (int $left): bool => $left > 0));
        $left = $credited;
        $split = [];

        foreach ($buyers as $buyer) {
            $id = $buyer['customer']->id;
            $share = match (true) {
                $total <= 0 || $remaining[$id] <= 0 => 0,
                $id === $lastWithRoom => min($left, $remaining[$id]),
                default => min(intdiv(intdiv($credited * $remaining[$id], $total), 100) * 100, $remaining[$id]),
            };
            $left -= $share;
            $split[] = ['customer_id' => $id, 'share_bp' => $buyer['share_bp'], 'deduction_ore' => -$share];
        }

        return [$deduction, $split];
    }

    /**
     * The ROT or RUT deduction on a credit invoice: the credited share of
     * the deduction on the invoice it credits, never recomputed at the
     * credit's date, rate or ceiling. A credit note changes the original by
     * what it credits (Mervärdesskattelag (2023:200) 17 kap. 22 §), and
     * Skatteverket pays the difference between what was charged and what
     * the buyer paid (lag (2009:194) om förfarandet vid skattereduktion för
     * hushållsarbete 7 §), so the deduction is reversed in proportion to
     * the labour credited (labour including VAT, rounded down to whole
     * kronor). The credit that credits the last of the labour takes the
     * rest of the deduction, and no credit takes more than is left of it
     * (enligt granskning 2026-11-02, juristgranskning återstår (AUD-FAKT-1,
     * #48)).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function creditedDeduction(array $rows, Invoice $original, ?Invoice $draft): int
    {
        $gross = function (iterable $labourRows): int {
            $totals = InvoiceCalculator::totals(array_values(array_map(fn (array $row): array => ['net' => (int) $row['net_ore'], 'vat_rate' => (int) $row['vat_rate']], [...$labourRows])), roundToKronor: false);

            return $totals['net'] + $totals['vat'];
        };

        $credited = -$gross(array_filter($rows, fn (array $row): bool => (bool) $row['is_labour']));

        if ($credited <= 0) {
            return 0;
        }

        $original->loadMissing(['rows', 'creditInvoices.rows']);
        $labour = $gross($original->rows->where('is_labour', true)->map(fn (InvoiceRow $row): array => ['net_ore' => $row->net_ore->ore, 'vat_rate' => $row->vat_rate])->all());
        $deductionLeft = $original->tax_reduction_ore->ore;
        $labourLeft = $labour;

        foreach ($original->creditInvoices as $earlier) {
            if ($earlier->isDraft() || ($draft !== null && $earlier->id === $draft->id)) {
                continue;
            }

            $deductionLeft += $earlier->tax_reduction_ore->ore;
            $labourLeft -= -$gross($earlier->rows->where('is_labour', true)->map(fn (InvoiceRow $row): array => ['net_ore' => $row->net_ore->ore, 'vat_rate' => $row->vat_rate])->all());
        }

        if ($deductionLeft <= 0 || $labour <= 0) {
            return 0;
        }

        if ($credited >= $labourLeft) {
            return -$deductionLeft;
        }

        $share = intdiv(intdiv($original->tax_reduction_ore->ore * $credited, $labour), 100) * 100;

        return -min($share, $deductionLeft);
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

        $labour = array_values(array_filter($rows, fn (array $row): bool => (bool) $row['is_labour']));

        if ($labour === []) {
            throw ValidationException::withMessages(['tax_reduction' => __('Markera vilka rader som är arbetskostnad.')]);
        }

        $labourTotals = InvoiceCalculator::totals(array_map(fn (array $row): array => ['net' => (int) $row['net_ore'], 'vat_rate' => (int) $row['vat_rate']], $labour));

        // The year the buyer pays decides the share and the ceiling; the due date is the expected day (#56).
        return TaxReduction::allowed($type, $labourTotals['net'] + $labourTotals['vat'], $customer, $date, $draft?->exists === true ? $draft->id : null)
            ?? throw ValidationException::withMessages(['tax_reduction' => __('Det finns ingen regel för :type på förfallodagen.', ['type' => TaxReduction::label($type)])]);
    }
}
