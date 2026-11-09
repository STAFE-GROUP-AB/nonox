<?php

declare(strict_types=1);

namespace App\Actions\AnnualReport;

use App\Actions\Accounting\BookVoucher;
use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\Closing\YearEndClosing;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\VoucherSource;
use App\Domain\AnnualReport\K3Rules;
use App\Domain\AnnualReport\K3TransitionProposal;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\K3Transition;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Books the K2-to-K3 transition once, after a person has confirmed the
 * proposal they saw (ADR-059 A.2.8, human decision H7 (a), S1-P12):
 *
 * - one voucher through BookVoucher and PostVoucher, source K3_TRANSITION,
 *   dated the transition date (the first day of the first K3 year), every
 *   difference against 2091 (K3 35.11 "direkt mot eget kapital"); no voucher
 *   when nothing changes the books;
 * - the opening balances are not touched: they stay equal to the last K2
 *   year's closing balances, and the K2 years and their reports are left as
 *   they are (AGENTS §1.2);
 * - a K3Transition row (append-only) and every item frozen with the rate.
 *
 * Needs fiscal-years:manage; refused while the proposal has a blocker
 * (chapter 35 used before, K3 35.1; comparatives restated; an unsplit
 * building; opening balances that differ from the last K2 year's closing
 * balances; …), when the transition is already booked, or when the proposal
 * changed since it was shown.
 */
final class BookK3Transition
{
    public function __construct(private readonly BookVoucher $book) {}

    public function __invoke(User $user, FiscalYear $fiscalYear, string $confirmedFingerprint): K3Transition
    {
        $company = Company::query()->findOrFail($fiscalYear->company_id);

        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, fn (): K3Transition => DB::transaction(function () use ($user, $company, $fiscalYear, $confirmedFingerprint): K3Transition {
            // One transition at a time per company: the 35.1 check reads the company's other transitions,
            // and the framework change and the creation of years take the same lock.
            CreateNextFiscalYear::lock($company->id);
            $year = FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();

            if (($refusal = K3TransitionProposal::refusals($company, $year)[0] ?? null) !== null) {
                throw ValidationException::withMessages(['transition' => $refusal]);
            }

            if (K3Transition::query()->where('fiscal_year_id', $year->id)->exists()) {
                throw ValidationException::withMessages(['transition' => __('Övergången till K3 är redan bokförd. Rätta verifikationen om något blev fel.')]);
            }

            $proposal = K3TransitionProposal::for($company, $year);

            if (($blocker = $proposal->blockers[0] ?? null) !== null) {
                throw ValidationException::withMessages(['transition' => $blocker]);
            }

            if (! hash_equals($proposal->fingerprint(), $confirmedFingerprint)) {
                throw ValidationException::withMessages(['transition' => __('Underlaget har ändrats sedan det visades. Granska det igen innan du bekräftar.')]);
            }

            $rows = $proposal->voucherRows();
            $voucher = null;

            if ($rows !== []) {
                $this->ensureAccounts($rows, $user, $company);
                $voucher = $this->book->rows(
                    $user,
                    $company,
                    $proposal->transitionDate,
                    __('Övergång till K3 den :date (K3 kapitel 35)', ['date' => $proposal->transitionDate->toDateString()]),
                    YearEndClosing::SERIES,
                    $rows,
                    VoucherSource::K3Transition,
                    $year,
                );
            }

            $transition = K3Transition::create([
                'fiscal_year_id' => $year->id,
                'transition_date' => $proposal->transitionDate->toDateString(),
                'voucher_id' => $voucher?->id,
                'rate_bp' => $proposal->rateBp,
                'equity_effect_ore' => $proposal->equityEffectOre(),
                'comparatives_restated' => false,
                'chapter_35_repeated' => $proposal->chapter35Repeated,
                'confirmed_by' => $user->id,
                'opening_balances' => $proposal->openingByNumber,
                'deferred_tax_set_off' => $proposal->setOff,
            ]);

            foreach ($proposal->lines as $line) {
                $line['item']->forceFill(['k3_transition_id' => $transition->id, 'rate_bp' => $line['item']->relief_35_27 ? null : $proposal->rateBp])->save();
            }

            // Ids, enums and amounts only; descriptions and reasons stay in their rows.
            Audit::record('k3_transition.booked', $year, [
                'transition_id' => $transition->id,
                'transition_date' => $proposal->transitionDate->toDateString(),
                'equity_effect_ore' => $proposal->equityEffectOre(),
                'rate_bp' => $proposal->rateBp,
                'items' => array_map(fn (array $line): int => $line['item']->id, $proposal->lines),
                'relief_35_27_items' => array_values(array_map(fn (array $line): int => $line['item']->id, array_filter($proposal->lines, fn (array $line): bool => $line['item']->relief_35_27))),
                'buildings' => array_map(fn (array $building): int => $building['asset']->id, $proposal->buildings),
                'chapter_35_repeated' => $proposal->chapter35Repeated,
                'deferred_tax_set_off' => $proposal->setOff,
                'voucher' => $voucher?->reference(),
            ], $company, $user);

            return $transition;
        }));
    }

    /**
     * The deferred tax accounts are added from BAS when the chart lacks them,
     * as in the closing; every other account must be in the chart.
     *
     * @param  list<array{account: int, amount: int, description: string}>  $rows
     */
    private function ensureAccounts(array $rows, User $user, Company $company): void
    {
        $numbers = array_values(array_unique(array_column($rows, 'account')));
        $existing = Account::query()->whereIn('number', $numbers)->where('is_active', true)->pluck('number')->map(fn (mixed $number): int => (int) $number)->all();
        $created = [
            K3Rules::int('deferred_tax.liability_account') => __('Avsättningar för uppskjutna skatter'),
            K3Rules::int('deferred_tax.asset_account') => __('Uppskjuten skattefordran'),
        ];

        foreach (array_diff($numbers, $existing) as $number) {
            if (! isset($created[$number]) || Account::query()->where('number', $number)->exists()) {
                throw ValidationException::withMessages(['transition' => __('Konto :account finns inte i kontoplanen eller är inaktivt. Lägg till eller aktivera det först.', ['account' => $number])]);
            }

            $account = Account::create(['number' => $number, 'name' => $created[$number], 'type' => AccountType::forNumber($number)]);
            Audit::record('account.created', $account, ['number' => $number, 'reason' => 'k3_transition'], $company, $user);
        }
    }
}
