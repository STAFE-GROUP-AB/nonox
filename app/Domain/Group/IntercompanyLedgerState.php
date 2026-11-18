<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Domain\Accounting\FiscalYearStatus;
use App\Models\Company;
use App\Models\VoucherRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Where a tagged row sits in the books: in a closed fiscal year, in a locked
 * period (on or before the company's locked_until_date), or neither. Tags
 * may change there, since they are not bookkeeping, but every change says so
 * in the audit log and the page warns first. Also the per-company lock that
 * serialises every bulk write of tags.
 */
final class IntercompanyLedgerState
{
    /** First key of pg_advisory_xact_lock for intercompany tags (ADR-081 E23); the second is the company id. */
    public const int LOCK_KEY = 81023;

    /** Takes the company's tag lock for the rest of the transaction. */
    public static function lock(Company $company): void
    {
        DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_KEY, $company->id]);
    }

    public static function locked(Company $company, string $voucherDate): bool
    {
        return $company->locked_until_date !== null && $voucherDate <= $company->locked_until_date->toDateString();
    }

    public static function closed(string $fiscalYearStatus): bool
    {
        return $fiscalYearStatus === FiscalYearStatus::Closed->value;
    }

    /**
     * How many of the rows in a query over voucher_rows joined with vouchers
     * are in a closed year or a locked period; one query.
     *
     * @param  Builder<VoucherRow>  $rows
     * @return array{in_closed_year: int, in_locked_period: int}
     */
    public static function counts(Company $company, Builder $rows): array
    {
        $result = (clone $rows)
            ->join('fiscal_years', 'fiscal_years.id', '=', 'vouchers.fiscal_year_id')
            ->toBase()
            ->selectRaw('COUNT(*) FILTER (WHERE fiscal_years.status = ?) AS closed, COUNT(*) FILTER (WHERE ?::date IS NOT NULL AND vouchers.voucher_date <= ?::date) AS locked', [
                FiscalYearStatus::Closed->value,
                $company->locked_until_date?->toDateString(),
                $company->locked_until_date?->toDateString(),
            ])
            ->first();

        return ['in_closed_year' => (int) ($result->closed ?? 0), 'in_locked_period' => (int) ($result->locked ?? 0)];
    }
}
