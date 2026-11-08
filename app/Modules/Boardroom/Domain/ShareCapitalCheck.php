<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

use App\Domain\Accounting\VoucherStatus;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\VoucherRow;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The register against the books (ADR-070 §3): the registered shares times
 * the quota value, which is the registered share capital, against the
 * balance on 2081 Aktiekapital on the same date. Only registered capital
 * counts (rule sheet AB-R42, G.12); a new issue not yet registered is shown
 * apart, as the usual BAS account for it is 2082. NONOX posts nothing: a
 * difference becomes a todo naming both figures. Three queries.
 * TODO(legal-verify): accounting-checker to confirm 2082 as the account for
 * unregistered share capital (BAS usage, not law).
 */
final readonly class ShareCapitalCheck
{
    public const int SHARE_CAPITAL_ACCOUNT = 2081;

    public const int UNREGISTERED_ACCOUNT = 2082;

    public function __construct(
        public CarbonImmutable $date,
        public int $registeredShares,
        public ?int $quotaValueOre,
        public bool $quotaIsExact,
        public int $registerCapitalOre,
        public int $pendingCapitalOre,
        public ?int $ledgerCapitalOre,
    ) {}

    public static function at(RegisterState $state, CarbonImmutable $date): self
    {
        return new self(
            $date,
            $state->registeredShares(),
            $state->quotaValueOre(),
            $state->quotaIsExact(),
            $state->capitalOre,
            $state->pendingCapitalOre(),
            self::ledgerCapital($date),
        );
    }

    /** The credit balance of 2081 on the date, in öre; null when no fiscal year covers it. */
    public static function ledgerCapital(CarbonImmutable $date): ?int
    {
        $year = FiscalYear::query()
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->first();

        if ($year === null) {
            return null;
        }

        $account = Account::query()->where('number', self::SHARE_CAPITAL_ACCOUNT)->value('id');

        if ($account === null) {
            return 0;
        }

        $opening = OpeningBalance::query()->where('fiscal_year_id', $year->id)->where('account_id', $account)->first()?->amount_ore;
        $movement = (int) VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->where('vouchers.fiscal_year_id', $year->id)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereDate('vouchers.voucher_date', '<=', $date->toDateString())
            ->where('voucher_rows.account_id', $account)
            ->sum(DB::raw('voucher_rows.debit_ore - voucher_rows.credit_ore'));

        // Equity is a credit balance: debit minus credit is negative.
        return -(($opening instanceof Money ? $opening->ore : 0) + $movement);
    }

    public function matches(): bool
    {
        return $this->ledgerCapitalOre === null || $this->ledgerCapitalOre === $this->registerCapitalOre;
    }

    public function differenceOre(): int
    {
        return (int) $this->ledgerCapitalOre - $this->registerCapitalOre;
    }
}
