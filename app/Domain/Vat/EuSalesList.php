<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use App\Domain\Invoicing\ForeignSale;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use Carbon\CarbonImmutable;

/**
 * Periodisk sammanställning (AGENTS.md §8): VAT-free sales to businesses in
 * other EU countries per buyer VAT number, in three columns (goods,
 * triangulation, services), from the invoices issued in the period. Credit
 * invoices reduce the amounts. The file follows Skatteverket's format
 * SKV574008 for the e-service Filöverföring.
 *
 * Only invoices count: a sale booked by hand on 3107, 3108 or 3308 has no
 * buyer VAT number and must be added in Skatteverket's e-service.
 *
 * TODO(legal-verify): the period a sale belongs to (invoice date, or the
 * 15th of the month after delivery for goods), whether amounts are whole
 * kronor rounded half up, and the file's character encoding (ISO-8859-1
 * here; the format page does not say).
 */
final class EuSalesList
{
    public const array COLUMNS = ['goods', 'triangular', 'services'];

    /**
     * @param  array<string, array{goods: int, triangular: int, services: int}>  $rows  öre per buyer VAT number
     */
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly array $rows,
    ) {}

    public static function for(CarbonImmutable $start, CarbonImmutable $end): self
    {
        $sales = array_map(fn (ForeignSale $sale): string => $sale->value, array_filter(ForeignSale::cases(), fn (ForeignSale $sale): bool => $sale->isReportedOnEuSalesList()));

        $invoices = Invoice::query()
            ->where('status', '<>', InvoiceStatus::Draft->value)
            ->whereIn('foreign_sale', $sales)
            ->whereBetween('invoice_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('number')
            ->get(['id', 'net_ore', 'foreign_sale', 'customer_snapshot']);

        $rows = [];

        foreach ($invoices as $invoice) {
            $vatNumber = strtoupper((string) preg_replace('/\s/', '', (string) ($invoice->customer_snapshot['vat_number'] ?? '')));
            $column = $invoice->foreign_sale?->euSalesListColumn();

            if ($vatNumber === '' || $column === null) {
                continue;
            }

            $rows[$vatNumber] ??= ['goods' => 0, 'triangular' => 0, 'services' => 0];
            $rows[$vatNumber][$column] += $invoice->net_ore->ore;
        }

        ksort($rows);

        return new self($start, $end, $rows);
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
        if ($this->start->month === $this->end->month) {
            return $this->start->format('ym');
        }

        return $this->start->format('y').'-'.$this->start->quarter;
    }

    /** The file for Skatteverket's Filöverföring, in UTF-8 (convert before saving). */
    public function file(Company $company, string $contactName, string $phone, ?string $email): string
    {
        $vatNumber = (string) preg_replace('/^SE/i', '', (string) preg_replace('/\s/', '', (string) $company->vat_number));
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
