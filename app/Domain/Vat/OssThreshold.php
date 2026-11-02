<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use App\Domain\Invoicing\ForeignSale;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use Carbon\CarbonImmutable;

/**
 * The threshold for distance sales of goods and telecom, broadcasting and
 * electronic services to consumers in other EU countries.
 *
 * Mervärdesskattelag (2023:200) 6 kap. 62 §: such sales by a seller
 * established only in Sweden are made in Sweden, with Swedish VAT, while
 * their total value excluding VAT is at most 99 680 kronor in the current
 * calendar year and was at most that in the previous one. 6 kap. 63 §: once
 * exceeded, the sale that exceeds it and every later one is made in the
 * buyer's country (OSS). 6 kap. 64-65 §§: the seller may ask Skatteverket to
 * have the sales taxed in the buyer's country anyway (Company::oss_elected).
 *
 * Applied enligt AI-utredning 2026-11-02, juristgranskning återstår.
 *
 * TODO(legal-verify): the amount and its source (below); that the year of a
 * sale is taken from its invoice date; that registration for OSS counts as
 * the request under 6 kap. 64 §; that all the company's invoices to EU
 * consumers marked for OSS are distance sales of goods or TBE services (other
 * services to consumers follow their own place-of-supply rules and are not
 * counted apart here).
 */
final class OssThreshold
{
    /** 99 680 kronor in öre. */
    public const int AMOUNT_ORE = 9_968_000;

    public const string SOURCE = 'Mervärdesskattelag (2023:200) 6 kap. 62 §, https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/mervardesskattelag-2023200_sfs-2023-200/ (läst 2026-11-02)';

    /** A warning is shown in the invoice editor once this share of the threshold is used. */
    public const int WARN_PERCENT = 80;

    /**
     * Whether a sale on $date of $net öre (excl. VAT, kronor) to a consumer in
     * another EU country carries Swedish VAT, and the totals it is judged by.
     * $exceptInvoiceId is the invoice itself, when it is already saved.
     *
     * @return array{elected: bool, previous: int, current: int, after: int, swedish_vat: bool, crosses: bool, near: bool}
     */
    public static function check(Company $company, CarbonImmutable $date, int $net, ?string $exceptInvoiceId = null): array
    {
        $elected = self::elected($company, $date);
        $totals = self::sales([$date->year - 1, $date->year], $exceptInvoiceId);
        $previous = $totals[$date->year - 1];
        $current = $totals[$date->year];
        $after = $current + $net;
        $under = $previous <= self::AMOUNT_ORE && $current <= self::AMOUNT_ORE;
        $swedishVat = ! $elected && $under && $after <= self::AMOUNT_ORE;

        return [
            'elected' => $elected,
            'previous' => $previous,
            'current' => $current,
            'after' => $after,
            'swedish_vat' => $swedishVat,
            'crosses' => ! $elected && $under && $after > self::AMOUNT_ORE,
            'near' => $swedishVat && $after * 100 >= self::AMOUNT_ORE * self::WARN_PERCENT,
        ];
    }

    /** Whether the company has elected taxation in the buyer's country on $date. */
    public static function elected(Company $company, CarbonImmutable $date): bool
    {
        return $company->oss_elected
            && ($company->oss_elected_from === null || $company->oss_elected_from->lessThanOrEqualTo($date));
    }

    /**
     * The value excluding VAT, in öre, of the issued invoices (credit
     * invoices deducted) to consumers in other EU countries per calendar
     * year, under the threshold or not. Scoped to the current company.
     *
     * @param  non-empty-list<int>  $years
     * @return array<int, int>
     */
    public static function sales(array $years, ?string $exceptInvoiceId = null): array
    {
        $totals = array_fill_keys($years, 0);

        $sums = Invoice::query()
            ->where('foreign_sale', ForeignSale::EuConsumerOss->value)
            ->where('status', '<>', InvoiceStatus::Draft->value)
            ->whereBetween('invoice_date', [min($years).'-01-01', max($years).'-12-31'])
            ->when($exceptInvoiceId !== null, fn ($query) => $query->whereKeyNot($exceptInvoiceId))
            ->selectRaw('EXTRACT(YEAR FROM invoice_date)::int AS year, SUM(net_ore) AS net')
            ->groupByRaw('EXTRACT(YEAR FROM invoice_date)')
            ->pluck('net', 'year');

        foreach ($sums as $year => $net) {
            $totals[(int) $year] = (int) $net;
        }

        return $totals;
    }
}
