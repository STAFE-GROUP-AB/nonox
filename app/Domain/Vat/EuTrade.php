<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Models\VoucherRow;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether a company had EU trade in a fiscal year, which moves the deadline
 * of yearly VAT (SFL 26 kap. 33 a § andra stycket, 33 b § tredje stycket):
 * goods transported between EU countries and services under article 196 of
 * the VAT directive, bought or sold. Read from the ledger: a posted row on an
 * account that feeds box 20, 21, 22, 35, 37, 38 or 39 (#60).
 *
 * TODO(legal-verify): whether distance sales of goods through OSS (3106)
 * also count as "leveranser av varor som transporteras mellan EU-länder";
 * they are not counted here.
 */
final class EuTrade
{
    /**
     * TODO(legal-verify): box 22, services bought from outside the EU, is
     * counted because art. 196 covers any supplier not established in
     * Sweden (REGLER.md §1); domestic reverse charge (24, 41; art. 199) is not.
     */
    public const array BOXES = [20, 21, 22, 35, 37, 38, 39];

    /**
     * The fiscal years with EU trade, for many companies in one query, as
     * "company id:fiscal year start" (the start of a yearly VAT period).
     * Runs under the caller's tenancy scope.
     *
     * @param  list<int>  $companyIds
     * @return list<string>
     */
    public static function years(array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }

        return array_values(VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->join('fiscal_years', 'fiscal_years.id', '=', 'vouchers.fiscal_year_id')
            ->whereIn('vouchers.company_id', $companyIds)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->where('vouchers.source', '<>', VoucherSource::Vat->value)
            ->where(function (Builder $query): void {
                $query->whereIn('accounts.vat_box', self::BOXES);

                foreach (self::BOXES as $box) {
                    foreach (VatBoxes::BOXES[$box]['accounts'] as [$from, $to]) {
                        $query->orWhereBetween('accounts.number', [$from, $to]);
                    }
                }
            })
            ->distinct()
            ->get(['vouchers.company_id', 'fiscal_years.start_date'])
            ->map(fn (VoucherRow $row): string => $row->getAttribute('company_id').':'.substr((string) $row->getAttribute('start_date'), 0, 10))
            ->all());
    }

    public static function key(int $companyId, string $periodStart): string
    {
        return "{$companyId}:{$periodStart}";
    }
}
