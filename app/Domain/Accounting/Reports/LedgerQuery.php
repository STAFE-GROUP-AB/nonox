<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reports;

use App\Domain\Accounting\LedgerBalances;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Models\FiscalYear;
use App\Models\VoucherRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reads the posted ledger of one fiscal year for reports: balances at a date
 * and movements over a period, optionally for one cost centre or project.
 * Amounts are signed öre (debit positive), keyed by account id. Every method
 * is one query, whatever the number of accounts.
 *
 * With a cost centre or project filter the opening balance is zero: opening
 * balances are kept per account, not per object (ADR-008).
 */
final class LedgerQuery
{
    public function __construct(
        public readonly FiscalYear $fiscalYear,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?int $costCenterId = null,
        public readonly ?int $projectId = null,
        public readonly bool $excludeVatSettlements = false,
    ) {}

    public static function forYear(FiscalYear $fiscalYear): self
    {
        return new self($fiscalYear, $fiscalYear->start_date, $fiscalYear->end_date);
    }

    public function filtered(): bool
    {
        return $this->costCenterId !== null || $this->projectId !== null;
    }

    /**
     * The balance of each account at the start of the period: the opening
     * balance plus everything posted in the year before $from.
     *
     * @return Collection<int, int>
     */
    public function openingBalances(): Collection
    {
        $opening = $this->filtered() ? collect() : LedgerBalances::opening($this->fiscalYear);

        if ($this->from->lessThanOrEqualTo($this->fiscalYear->start_date)) {
            return $opening;
        }

        $before = $this->rows($this->fiscalYear->start_date, $this->from->subDay())
            ->groupBy('voucher_rows.account_id')
            ->selectRaw('voucher_rows.account_id, SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) AS amount')
            ->pluck('amount', 'account_id');

        foreach ($before as $accountId => $amount) {
            $opening[(int) $accountId] = (int) ($opening[(int) $accountId] ?? 0) + (int) $amount;
        }

        return $opening;
    }

    /**
     * Debit and credit per account over the period.
     *
     * @return Collection<int, array{debit: int, credit: int}>
     */
    public function movements(): Collection
    {
        return $this->rows($this->from, $this->to)
            ->groupBy('voucher_rows.account_id')
            ->selectRaw('voucher_rows.account_id, SUM(voucher_rows.debit_ore) AS debit, SUM(voucher_rows.credit_ore) AS credit')
            ->get()
            ->mapWithKeys(fn (VoucherRow $row): array => [(int) $row->account_id => [
                'debit' => (int) $row->getAttribute('debit'),
                'credit' => (int) $row->getAttribute('credit'),
            ]]);
    }

    /**
     * The posted rows of the period, joined with their voucher.
     *
     * @return Builder<VoucherRow>
     */
    public function rows(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Builder
    {
        return VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->where('vouchers.fiscal_year_id', $this->fiscalYear->id)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereBetween('vouchers.voucher_date', [($from ?? $this->from)->toDateString(), ($to ?? $this->to)->toDateString()])
            ->when($this->costCenterId !== null, fn (Builder $query) => $query->where('voucher_rows.cost_center_id', $this->costCenterId))
            ->when($this->projectId !== null, fn (Builder $query) => $query->where('voucher_rows.project_id', $this->projectId))
            ->when($this->excludeVatSettlements, fn (Builder $query) => $query->where('vouchers.source', '<>', VoucherSource::Vat->value));
    }
}
