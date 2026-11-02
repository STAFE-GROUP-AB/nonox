<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use App\Domain\Invoicing\ForeignSale;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Currency\EuroRate;
use App\Support\Currency\EuroReferenceRates;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * The basis for the OSS return (One Stop Shop, unionsordningen): sales to
 * consumers in other EU countries per country and VAT rate, with the VAT,
 * from the invoices issued in the quarter. Sales under the threshold, which
 * carry Swedish VAT (OssThreshold), are not in it.
 *
 * In kronor as booked (to reconcile with 2619), and in euro, the currency
 * the return is made in (Mervärdesskattelag (2023:200) 22 kap. 42 §): a sale
 * invoiced in euro counts with its euro amounts, any other currency is
 * converted to euro from the amounts as invoiced at the ECB reference rate
 * for the last day of the quarter (Skatteverket, "Deklarera och betala moms
 * i One Stop Shop", read 2026-11-02). The VAT in euro is calculated per
 * country and rate on the euro base, half up. Enligt AI-utredning
 * 2026-11-02, juristgranskning återstår.
 *
 * TODO(legal-verify): which rate applies when the ECB publishes none on the
 * quarter's last day (the next one published is used; Council Directive
 * 2006/112/EC art. 369h is understood to say so but was not read); rounding
 * of the euro VAT per country and rate; the period a sale belongs to (its
 * invoice date here); corrections of earlier quarters, which the e-service
 * handles separately.
 */
final class OssReport
{
    /**
     * @param  list<array{country: string, rate: int, net: int, vat: int, net_eur: ?int, vat_eur: ?int}>  $rows  öre and euro cents
     * @param  array<string, EuroRate>  $euroRates  the rate used per currency other than euro
     * @param  list<string>  $missingRates  currencies without an ECB rate yet; the euro amounts are then null
     */
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly array $rows,
        public readonly array $euroRates = [],
        public readonly array $missingRates = [],
    ) {}

    public static function for(CarbonImmutable $start, CarbonImmutable $end, ?EuroReferenceRates $rates = null): self
    {
        $rates ??= app(EuroReferenceRates::class);

        $invoices = Invoice::query()
            ->with('rows:id,invoice_id,net_ore,vat_rate,foreign_net_minor')
            ->where('status', '<>', InvoiceStatus::Draft->value)
            ->where('foreign_sale', ForeignSale::EuConsumerOss->value)
            ->where('swedish_vat_under_threshold', false)
            ->whereBetween('invoice_date', [$start->toDateString(), $end->toDateString()])
            ->get(['id', 'customer_snapshot', 'invoice_currency']);

        $groups = [];

        foreach ($invoices as $invoice) {
            $country = strtoupper((string) ($invoice->customer_snapshot['country'] ?? ''));
            $currency = $invoice->invoice_currency;

            foreach ($invoice->rows as $row) {
                $key = "{$country}|{$row->vat_rate}";
                $groups[$key] ??= ['country' => $country, 'rate' => $row->vat_rate, 'net' => 0, 'invoiced' => []];
                $groups[$key]['net'] += $row->net_ore->ore;
                $groups[$key]['invoiced'][$currency] = ($groups[$key]['invoiced'][$currency] ?? 0)
                    + ($currency === 'SEK' ? $row->net_ore->ore : (int) $row->foreign_net_minor);
            }
        }

        ksort($groups);

        $currencies = [];

        foreach ($groups as $group) {
            $currencies += array_fill_keys(array_keys($group['invoiced']), true);
        }

        unset($currencies['EUR']);
        $euroRates = [];
        $missing = [];

        foreach (array_keys($currencies) as $currency) {
            $currency = (string) $currency;
            $rate = $rates->onOrAfter($currency, $end->toDateString());
            $rate === null ? $missing[] = $currency : $euroRates[$currency] = $rate;
        }

        $rows = array_values(array_map(function (array $group) use ($euroRates, $missing): array {
            $netEur = null;

            if ($missing === []) {
                $netEur = 0;

                foreach ($group['invoiced'] as $currency => $amount) {
                    $netEur += $currency === 'EUR' ? $amount : $euroRates[$currency]->toCents($amount);
                }
            }

            return [
                'country' => $group['country'],
                'rate' => $group['rate'],
                'net' => $group['net'],
                'vat' => Money::ofOre($group['net'])->percent($group['rate'])->ore,
                'net_eur' => $netEur,
                'vat_eur' => $netEur === null ? null : BigDecimal::of($netEur)->multipliedBy($group['rate'])->dividedBy(10000, 0, RoundingMode::HalfUp)->toInt(),
            ];
        }, $groups));

        return new self($start, $end, $rows, $euroRates, $missing);
    }

    /** @return array{net: int, vat: int, net_eur: ?int, vat_eur: ?int} */
    public function totals(): array
    {
        $euro = $this->missingRates === [];

        return [
            'net' => array_sum(array_column($this->rows, 'net')),
            'vat' => array_sum(array_column($this->rows, 'vat')),
            'net_eur' => $euro ? array_sum(array_column($this->rows, 'net_eur')) : null,
            'vat_eur' => $euro ? array_sum(array_column($this->rows, 'vat_eur')) : null,
        ];
    }

    /** The report as a semicolon-separated file in UTF-8, amounts with a decimal comma. */
    public function csv(): string
    {
        $amount = fn (?int $hundredths): string => $hundredths === null ? '' : ($hundredths < 0 ? '-' : '').intdiv(abs($hundredths), 100).','.str_pad((string) (abs($hundredths) % 100), 2, '0', STR_PAD_LEFT);
        $lines = [implode(';', ['Land', 'Momssats', 'Beskattningsunderlag SEK', 'Moms SEK', 'Beskattningsunderlag EUR', 'Moms EUR'])];

        foreach ($this->rows as $row) {
            $lines[] = implode(';', [$row['country'], $amount($row['rate']), $amount($row['net']), $amount($row['vat']), $amount($row['net_eur']), $amount($row['vat_eur'])]);
        }

        $totals = $this->totals();
        $lines[] = implode(';', ['Summa', '', $amount($totals['net']), $amount($totals['vat']), $amount($totals['net_eur']), $amount($totals['vat_eur'])]);

        foreach ($this->euroRates as $rate) {
            $lines[] = "Kurs;{$rate->currency} per EUR;{$rate->format()};ECB {$rate->date}";
        }

        return implode("\r\n", $lines)."\r\n";
    }
}
