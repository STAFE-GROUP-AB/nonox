<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use App\Domain\Invoicing\ForeignSale;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The basis for the OSS return (One Stop Shop, unionsordningen): sales to
 * consumers in other EU countries per country and VAT rate, with the VAT, in
 * SEK from the invoices issued in the quarter. The return itself is filed in
 * Skatteverket's e-service in euro.
 *
 * TODO(legal-verify): conversion to euro at the ECB rate of the quarter's
 * last day, the period a sale belongs to, and corrections of earlier
 * quarters, which the e-service handles separately.
 */
final class OssReport
{
    /**
     * @param  list<array{country: string, rate: int, net: int, vat: int}>  $rows  öre
     */
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly array $rows,
    ) {}

    public static function for(CarbonImmutable $start, CarbonImmutable $end): self
    {
        $invoices = Invoice::query()
            ->with('rows:id,invoice_id,net_ore,vat_rate')
            ->where('status', '<>', InvoiceStatus::Draft->value)
            ->where('foreign_sale', ForeignSale::EuConsumerOss->value)
            ->whereBetween('invoice_date', [$start->toDateString(), $end->toDateString()])
            ->get(['id', 'customer_snapshot']);

        $groups = [];

        foreach ($invoices as $invoice) {
            $country = strtoupper((string) ($invoice->customer_snapshot['country'] ?? ''));

            foreach ($invoice->rows as $row) {
                $key = "{$country}|{$row->vat_rate}";
                $groups[$key] ??= ['country' => $country, 'rate' => $row->vat_rate, 'net' => 0, 'vat' => 0];
                $groups[$key]['net'] += $row->net_ore->ore;
            }
        }

        ksort($groups);

        $rows = array_values(array_map(fn (array $group): array => [...$group, 'vat' => Money::ofOre($group['net'])->percent($group['rate'])->ore], $groups));

        return new self($start, $end, $rows);
    }

    /** @return array{net: int, vat: int} */
    public function totals(): array
    {
        return ['net' => array_sum(array_column($this->rows, 'net')), 'vat' => array_sum(array_column($this->rows, 'vat'))];
    }
}
