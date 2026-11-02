<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use App\Domain\Invoicing\ForeignSale;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Periodisk sammanställning (AGENTS.md §8): VAT-free sales to businesses in
 * other EU countries per buyer VAT number, in three columns (goods,
 * triangulation, services). Credit invoices reduce the amounts. The file
 * follows Skatteverket's format SKV574008 for the e-service Filöverföring.
 *
 * The period a sale belongs to (SFL 35 kap. 2 § andra stycket):
 * - goods and triangulation: the invoice date (ML 7 kap. 29 §);
 * - services: when the service is performed (ML 7 kap. 30 §), taken as the
 *   invoice's delivery date when it has one, else its invoice date;
 * - a credit invoice: its invoice date.
 *
 * Which periods may be used (SFL 35 kap. 3–4 §§): goods are reported per
 * calendar month. A quarter is allowed for goods only after Skatteverket's
 * decision (Company::$eu_sales_quarterly_goods, from the quarter in
 * $eu_sales_quarterly_goods_from) and while the goods value is at most
 * 500 000 kr in the quarter and in each of the four quarters before it
 * (35 kap. 4 och 7 §§). Services alone are reported per quarter unless
 * Skatteverket has decided monthly (35 kap. 5 §); that is shown as a note,
 * not enforced. Enligt granskning 2026-11-02, juristgranskning återstår
 * (OSAKERT-MOMS-PS, #65).
 *
 * Only invoices count: a sale booked by hand on 3107, 3108 or 3308 has no
 * buyer VAT number and must be added in Skatteverket's e-service. The
 * 500 000 kr check therefore also sees only invoiced goods.
 *
 * TODO(legal-verify): goods invoiced later than the 15th of the month after
 * delivery belong to the month the invoice should have been issued (ML 7
 * kap. 29 § with 17 kap. 17 §), not handled; a continuing service spanning
 * several periods, and a service paid in advance; whether a quarter with
 * both goods (under a decision) and services may be one quarterly report;
 * whether amounts are whole kronor rounded half up; the file's character
 * encoding (ISO-8859-1 here; the format page does not say).
 */
final class EuSalesList
{
    public const array COLUMNS = ['goods', 'triangular', 'services'];

    /** SFL 35 kap. 4 § 2: the goods value per quarter that allows quarterly reporting, in öre. */
    public const int QUARTERLY_GOODS_LIMIT_ORE = 50_000_000;

    /**
     * @param  array<string, array{goods: int, triangular: int, services: int}>  $rows  öre per buyer VAT number
     */
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly array $rows,
        public readonly bool $hasGoods = false,
        public readonly bool $hasServices = false,
    ) {}

    public static function for(CarbonImmutable $start, CarbonImmutable $end): self
    {
        $range = [$start->toDateString(), $end->toDateString()];
        $services = ForeignSale::EuServices->value;

        $invoices = Invoice::query()
            ->where('status', '<>', InvoiceStatus::Draft->value)
            ->whereIn('foreign_sale', self::saleKinds())
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $byInvoiceDate) => $byInvoiceDate
                    ->whereBetween('invoice_date', $range)
                    ->where(fn (Builder $kind) => $kind->where('foreign_sale', '<>', $services)->orWhere('kind', Invoice::CREDIT)->orWhereNull('delivery_date')))
                ->orWhere(fn (Builder $byDelivery) => $byDelivery
                    ->whereBetween('delivery_date', $range)
                    ->where('foreign_sale', $services)
                    ->where('kind', '<>', Invoice::CREDIT)))
            ->orderBy('number')
            ->get(['id', 'net_ore', 'foreign_sale', 'customer_snapshot']);

        $rows = [];
        $hasGoods = false;
        $hasServices = false;

        foreach ($invoices as $invoice) {
            $vatNumber = strtoupper((string) preg_replace('/\s/', '', (string) ($invoice->customer_snapshot['vat_number'] ?? '')));
            $column = $invoice->foreign_sale?->euSalesListColumn();

            if ($vatNumber === '' || $column === null) {
                continue;
            }

            $hasGoods = $hasGoods || $column !== 'services';
            $hasServices = $hasServices || $column === 'services';
            $rows[$vatNumber] ??= ['goods' => 0, 'triangular' => 0, 'services' => 0];
            $rows[$vatNumber][$column] += $invoice->net_ore->ore;
        }

        ksort($rows);

        return new self($start, $end, $rows, $hasGoods, $hasServices);
    }

    public function isQuarter(): bool
    {
        return $this->start->month !== $this->end->month;
    }

    /**
     * Why this period may not be used for the list, in Swedish, or null when
     * it may (SFL 35 kap. 3, 4 and 7 §§).
     */
    public function periodProblem(Company $company): ?string
    {
        if (! $this->isQuarter() || ! $this->hasGoods) {
            return null;
        }

        $from = $company->eu_sales_quarterly_goods_from;

        if (! $company->eu_sales_quarterly_goods || ($from !== null && $from->greaterThan($this->start))) {
            return __('Försäljning av varor redovisas per månad. Kvartal får användas först när Skatteverket har beslutat det och beslutet är angivet under Företagsuppgifter. Välj en månad.');
        }

        foreach (self::goodsValuePerQuarter($this->start) as $quarter => $ore) {
            if ($ore > self::QUARTERLY_GOODS_LIMIT_ORE) {
                return __('Varorna var värda :amount under :quarter, mer än 500 000 kr. Då upphör beslutet om kvartal och varorna redovisas per månad. Välj en månad.', [
                    'amount' => Money::ofOre($ore)->format(),
                    'quarter' => $quarter,
                ]);
            }
        }

        return null;
    }

    /** A note when services alone are reported per month, which needs Skatteverket's decision (SFL 35 kap. 5 §). */
    public function periodNote(): ?string
    {
        if ($this->isQuarter() || $this->hasGoods || ! $this->hasServices) {
            return null;
        }

        return __('Tjänster redovisas per kvartal. Välj en månad bara om Skatteverket har beslutat att företaget ska lämna sammanställningen per månad.');
    }

    /**
     * The invoiced value of goods and triangulation (öre, credits deducted)
     * in the quarter of $date and in each of the four quarters before it,
     * keyed by quarter label ("kvartal 1 2026"), newest first.
     *
     * @return array<string, int>
     */
    public static function goodsValuePerQuarter(CarbonImmutable $date): array
    {
        $quarterStart = $date->firstOfQuarter();
        $values = [];

        for ($i = 0; $i < 5; $i++) {
            $values[self::quarterLabel($quarterStart->subMonthsNoOverflow(3 * $i))] = 0;
        }

        $invoices = Invoice::query()
            ->where('status', '<>', InvoiceStatus::Draft->value)
            ->whereIn('foreign_sale', [ForeignSale::EuGoods->value, ForeignSale::EuTriangular->value])
            ->whereBetween('invoice_date', [$quarterStart->subMonthsNoOverflow(12)->toDateString(), $quarterStart->addMonthsNoOverflow(2)->endOfMonth()->toDateString()])
            ->get(['id', 'net_ore', 'invoice_date']);

        foreach ($invoices as $invoice) {
            $values[self::quarterLabel($invoice->invoice_date)] += $invoice->net_ore->ore;
        }

        return $values;
    }

    private static function quarterLabel(CarbonImmutable $date): string
    {
        return __('kvartal :quarter :year', ['quarter' => $date->quarter, 'year' => $date->year]);
    }

    /** @return list<string> */
    private static function saleKinds(): array
    {
        return array_values(array_map(fn (ForeignSale $sale): string => $sale->value, array_filter(ForeignSale::cases(), fn (ForeignSale $sale): bool => $sale->isReportedOnEuSalesList())));
    }

    /** @return array{goods: int, triangular: int, services: int} öre */
    public function totals(): array
    {
        $totals = ['goods' => 0, 'triangular' => 0, 'services' => 0];

        foreach ($this->rows as $row) {
            foreach (self::COLUMNS as $column) {
                $totals[$column] += $row[$column];
            }
        }

        return $totals;
    }

    /** Whole kronor, rounded half up away from zero. */
    public static function kronor(int $ore): int
    {
        return (int) round($ore / 100, 0, PHP_ROUND_HALF_UP);
    }

    /** ÅÅMM for a month, ÅÅ-K for a quarter. */
    public function periodCode(): string
    {
        if (! $this->isQuarter()) {
            return $this->start->format('ym');
        }

        return $this->start->format('y').'-'.$this->start->quarter;
    }

    /**
     * The reporting company's VAT number as line 2 of the file wants it: 12
     * digits. Skatteverket accepts it with or without "SE" and writes it
     * without in its examples, so it is written without; null when the
     * number is not SE + 12 digits.
     */
    public static function reporterVatNumber(Company $company): ?string
    {
        $vatNumber = strtoupper((string) preg_replace('/[\s-]/', '', (string) $company->vat_number));

        return preg_match('/^(?:SE)?(\d{12})$/', $vatNumber, $match) === 1 ? $match[1] : null;
    }

    /** The file for Skatteverket's Filöverföring, in UTF-8 (convert before saving). */
    public function file(Company $company, string $contactName, string $phone, ?string $email): string
    {
        $vatNumber = self::reporterVatNumber($company) ?? throw new \InvalidArgumentException('The company has no Swedish VAT number of SE + 12 digits.');
        $clean = fn (string $value): string => trim(str_replace([';', "\r", "\n"], ' ', $value));
        $lines = ['SKV574008;', implode(';', [$vatNumber, $this->periodCode(), mb_substr($clean($contactName), 0, 35), mb_substr($clean($phone), 0, 17), $clean((string) $email)])];

        foreach ($this->rows as $buyer => $row) {
            $cells = array_map(fn (string $column): string => $row[$column] === 0 ? '' : (string) self::kronor($row[$column]), self::COLUMNS);

            // A buyer whose sales net to zero (for example fully credited) is still reported, with 0.
            if (array_filter($cells) === []) {
                $cells[0] = '0';
            }

            $lines[] = "{$buyer};".implode(';', $cells).';';
        }

        return implode("\r\n", $lines)."\r\n";
    }

    public function filename(Company $company): string
    {
        return 'periodisksammanstallning'.preg_replace('/[^0-9]/', '', (string) $company->org_number).str_replace('-', 'K', $this->periodCode()).'.txt';
    }
}
