<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\CarriedBalances;
use App\Domain\Accounting\FiscalYearRules;
use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\Accounting\LedgerBalances;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Closing\ClosingAccounts;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Year-end rollover (årsskifte, AGENTS.md §7): the closing balance of every
 * balance-sheet account becomes its opening balance in the next fiscal year,
 * which is created if needed. A result not yet booked to equity is carried
 * to the year's result account, so the opening balances always balance.
 *
 * Can be run again whenever the year changes (a late voucher, a correction):
 * the next year's opening balances are replaced, as long as it is open and,
 * outside the year-end closing, its first day is not in a locked period
 * (StaleOpeningBalances finds when that is needed; UpdateOpeningBalances
 * does it on a person's decision).
 *
 * The closing (YearEndClosing::close) carries forward even when the next
 * year's first months are already locked, as they usually are when the
 * year-end is done after the VAT returns of the new year: establishing the
 * opening balances is part of closing the old year, and they are not a
 * posting in a period.
 *
 * TODO(legal-verify): that replacing the opening balances while the first
 * period of the next year is locked is refused outside the closing, and
 * allowed as part of it, is a design choice (ÅRL 2 kap. 4 § första stycket
 * 7 requires them to agree; no rule we found addresses the period lock).
 */
class CarryForwardBalances
{
    public function __invoke(User $user, FiscalYear $fiscalYear, bool $partOfClosing = false): FiscalYear
    {
        Gate::forUser($user)->authorize('update', $fiscalYear);

        return CurrentCompany::run($fiscalYear->company_id, fn (): FiscalYear => DB::transaction(function () use ($user, $fiscalYear, $partOfClosing): FiscalYear {
            $company = Company::query()->findOrFail($fiscalYear->company_id);
            // Lock order (ADR-010): the year-creation advisory lock, then the source year, as SIE import does;
            // a posting in the source year (which locks it first) finishes before its balances are read.
            CreateNextFiscalYear::lock($company->id);
            FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();
            $opening = LedgerBalances::opening($fiscalYear);
            $movements = LedgerBalances::movements($fiscalYear);

            if ($opening->isEmpty() && ! Voucher::query()->where('fiscal_year_id', $fiscalYear->id)->where('status', VoucherStatus::Posted->value)->exists()) {
                throw ValidationException::withMessages(['fiscal_year' => __('Räkenskapsåret :label har inget bokfört att föra över.', ['label' => $fiscalYear->label()])]);
            }

            $next = $this->nextYear($fiscalYear, $company, $user);

            if (! $next->isOpen()) {
                throw ValidationException::withMessages(['fiscal_year' => __('Räkenskapsåret :label är stängt, så dess ingående balanser kan inte ändras.', ['label' => $next->label()])]);
            }

            if (! $partOfClosing && self::lockedFirstDay($company, $next)) {
                throw ValidationException::withMessages(['fiscal_year' => self::lockedMessage($company, $fiscalYear, $next)]);
            }

            $accounts = Account::query()->get(['id', 'number', 'type']);
            $numbers = $accounts->pluck('number', 'id')->map(fn (mixed $number): int => (int) $number);
            $byNumber = function (iterable $amounts) use ($numbers): array {
                $result = [];

                foreach ($amounts as $id => $amount) {
                    $result[(int) $numbers[$id]] = (int) $amount;
                }

                return $result;
            };
            $types = $accounts->mapWithKeys(fn (Account $account): array => [$account->number => $account->type])->all();
            $openingByNumber = $byNumber($opening);
            $movementsByNumber = $byNumber($movements);
            $unbooked = -CarriedBalances::unbookedResult($types, $openingByNumber, $movementsByNumber);
            $carried = CarriedBalances::closing($types, $openingByNumber, $movementsByNumber, ClosingAccounts::resultNumber($company));
            $ids = $numbers->flip();
            $balances = [];

            foreach ($carried as $number => $amount) {
                $balances[$ids[$number] ?? $this->resultAccount($company)->id] = $amount;
            }

            $before = OpeningBalance::query()->where('fiscal_year_id', $next->id)->count();
            OpeningBalance::query()->where('fiscal_year_id', $next->id)->delete();

            foreach ($balances as $accountId => $amount) {
                OpeningBalance::create(['fiscal_year_id' => $next->id, 'account_id' => $accountId, 'amount_ore' => $amount]);
            }

            Audit::record('fiscal_year.balances_carried_forward', $next, [
                'from' => $fiscalYear->label(),
                'to' => $next->label(),
                'accounts' => count($balances),
                'replaced' => $before,
                'unbooked_result_ore' => -$unbooked,
            ], $company, $user);

            return $next;
        }));
    }

    /** Whether the period lock covers the first day of the year, the day its opening balances apply to. */
    public static function lockedFirstDay(Company $company, FiscalYear $year): bool
    {
        return $company->locked_until_date !== null && $year->start_date->lessThanOrEqualTo($company->locked_until_date);
    }

    public static function lockedMessage(Company $company, FiscalYear $from, FiscalYear $to): string
    {
        $message = __('Bokföringen är låst till och med :date, och de ingående balanserna för :to gäller från årets första dag. De kan därför inte ändras här.', [
            'date' => $company->locked_until_date?->toDateString(),
            'to' => $to->label(),
        ]);

        return $from->isOpen()
            ? $message.' '.__('De förs över på nytt när du stänger :from under Bokslut.', ['from' => $from->label()])
            : $message;
    }

    private function nextYear(FiscalYear $fiscalYear, Company $company, User $user): FiscalYear
    {
        $start = $fiscalYear->end_date->addDay();
        // The same lock as every other creation of a year, so this never races the morning run or a booking.
        CreateNextFiscalYear::lock($company->id);
        $next = FiscalYear::query()->whereDate('start_date', $start)->lockForUpdate()->first();

        if ($next instanceof FiscalYear) {
            return $next;
        }

        [, $end] = FiscalYearRules::nextYear($start, FiscalYearRules::requiresCalendarYear($company->legal_form));

        $next = FiscalYear::create([
            'start_date' => $start,
            'end_date' => $end,
            'status' => FiscalYearStatus::Open,
        ]);

        Audit::record('fiscal_year.created', $next, [
            'start_date' => $next->start_date->toDateString(),
            'end_date' => $next->end_date->toDateString(),
            'source' => 'carry_forward',
        ], $company, $user);

        return $next;
    }

    /**
     * Where an unbooked result goes: 2099 Årets resultat for companies and
     * associations, 2019 for sole traders and partnerships.
     *
     * TODO(legal-verify): confirm the BAS result account per legal form,
     * especially for partnerships (HB/KB), where equity is kept per partner.
     */
    private function resultAccount(Company $company): Account
    {
        return Account::query()->firstOrCreate(['number' => ClosingAccounts::resultNumber($company)], [
            'name' => __('Årets resultat'),
            'type' => AccountType::LiabilityEquity,
        ]);
    }
}
