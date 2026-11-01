<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\LedgerHash;
use App\Domain\Accounting\VoucherStatus;
use App\Events\VoucherPosted;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherNumberCounter;
use App\Models\VoucherRow;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Posts (bokför) a draft voucher: the most important code in the system
 * (AGENTS.md §6.4). All or nothing:
 *
 * 1. Lock the fiscal year row: serialises numbering and the hash chain.
 *    Then take a key-share lock on the company row, so a period lock
 *    (LockPeriod, closing a year), which locks the same row for update,
 *    cannot change locked_until_date between our check and our commit.
 * 2. Validate: at least two rows, balance, an open fiscal year, not a locked
 *    period, active accounts, required dimensions.
 * 3. Take the next gapless number in the series for the fiscal year.
 * 4. Chain the hash to the previous posted voucher in the fiscal year.
 * 5. Mark as posted (a database trigger checks the balance again).
 * 6. Write the audit log.
 *
 * Lock order, always the same to avoid deadlocks: voucher → fiscal year →
 * company (FOR KEY SHARE) → number counter. YearEndClosing (bookTax,
 * bookResult, close) also locks the fiscal year before the company.
 * FOR KEY SHARE is the weakest row lock that conflicts with FOR UPDATE, and
 * the same lock PostgreSQL already takes on the company row for the foreign
 * key of every row this transaction inserts (the audit log, for one), so it
 * adds no new kind of wait: it does not block other postings, ordinary
 * updates of the company row or other inserts; only actions that lock the
 * company row for update (LockPeriod, closing a year, SIE import, numbering
 * new accruals and assets) wait for it.
 */
class PostVoucher
{
    /**
     * @throws ValidationException
     */
    public function __invoke(User $user, Voucher $voucher): Voucher
    {
        Gate::forUser($user)->authorize('post', $voucher);

        return CurrentCompany::run($voucher->company_id, fn (): Voucher => DB::transaction(function () use ($user, $voucher): Voucher {
            /** @var Voucher $voucher */
            $voucher = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($voucher->status === VoucherStatus::Posted) {
                throw ValidationException::withMessages(['voucher' => __('Verifikationen är redan bokförd.')]);
            }

            $fiscalYear = $this->lockFiscalYear($voucher->voucher_date);
            $company = $this->lockCompany($voucher->company_id);

            $voucher->load(['rows.account', 'series']);
            $this->validate($voucher, $company);

            $counter = $this->lockCounter($fiscalYear->id, $voucher->series_id);
            $number = $counter->next_number;
            $counter->update(['next_number' => $number + 1]);

            $voucher->forceFill([
                'fiscal_year_id' => $fiscalYear->id,
                'number' => $number,
                'status' => VoucherStatus::Posted,
                'posted_at' => CarbonImmutable::now()->startOfSecond(),
                'posted_by' => $user->id,
                'previous_hash' => $fiscalYear->ledger_head_hash,
            ]);

            $voucher->hash = LedgerHash::of($voucher, $voucher->previous_hash);
            $voucher->save();

            $fiscalYear->forceFill(['ledger_head_hash' => $voucher->hash])->save();

            Audit::record('voucher.posted', $voucher, [
                'reference' => $voucher->reference(),
                'voucher_date' => $voucher->voucher_date->toDateString(),
                'amount_ore' => $voucher->rows->sum(fn (VoucherRow $row): int => $row->debit_ore->ore),
                'hash' => $voucher->hash,
            ], $voucher->company_id, $user);

            event(new VoucherPosted($voucher->company_id, $voucher->id));

            return $voucher;
        }));
    }

    private function lockFiscalYear(CarbonImmutable $date): FiscalYear
    {
        $fiscalYear = FiscalYear::query()
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->lockForUpdate()
            ->first();

        if (! $fiscalYear instanceof FiscalYear) {
            throw ValidationException::withMessages([
                'voucher_date' => __('Det finns inget räkenskapsår som omfattar :date.', ['date' => $date->toDateString()]),
            ]);
        }

        if (! $fiscalYear->isOpen()) {
            throw ValidationException::withMessages([
                'voucher_date' => __('Räkenskapsåret :label är stängt. Det går inte att bokföra i det.', ['label' => $fiscalYear->label()]),
            ]);
        }

        return $fiscalYear;
    }

    /**
     * The company row with a key-share lock held until commit: a period lock
     * waits for this posting, and this posting sees a period lock that was
     * committed before it.
     */
    private function lockCompany(int $companyId): Company
    {
        /** @var Company */
        return Company::query()->whereKey($companyId)->lock('for key share')->firstOrFail();
    }

    private function validate(Voucher $voucher, Company $company): void
    {
        $rows = $voucher->rows;

        if ($rows->count() < 2) {
            throw ValidationException::withMessages(['rows' => __('En verifikation behöver minst två rader.')]);
        }

        $debit = $rows->sum(fn (VoucherRow $row): int => $row->debit_ore->ore);
        $credit = $rows->sum(fn (VoucherRow $row): int => $row->credit_ore->ore);

        if ($debit !== $credit) {
            throw ValidationException::withMessages(['rows' => __('Debet och kredit är inte lika stora. Skillnaden är :difference öre.', [
                'difference' => abs($debit - $credit),
            ])]);
        }

        if ($debit === 0) {
            throw ValidationException::withMessages(['rows' => __('Verifikationen saknar belopp.')]);
        }

        if ($company->locked_until_date !== null && $voucher->voucher_date->lessThanOrEqualTo($company->locked_until_date)) {
            throw ValidationException::withMessages(['voucher_date' => __('Perioden till och med :date är låst. Välj ett senare datum.', [
                'date' => $company->locked_until_date->toDateString(),
            ])]);
        }

        foreach ($rows as $index => $row) {
            $account = $row->account;
            $line = $index + 1;

            if (! $account instanceof Account || ! $account->is_active) {
                throw ValidationException::withMessages(["rows.{$index}.account" => __('Rad :line: kontot är inaktiverat.', ['line' => $line])]);
            }

            if ($account->requires_cost_center && $row->cost_center_id === null) {
                throw ValidationException::withMessages(["rows.{$index}.cost_center_id" => __('Rad :line: konto :number kräver ett kostnadsställe.', ['line' => $line, 'number' => $account->number])]);
            }

            if ($account->requires_project && $row->project_id === null) {
                throw ValidationException::withMessages(["rows.{$index}.project_id" => __('Rad :line: konto :number kräver ett projekt.', ['line' => $line, 'number' => $account->number])]);
            }
        }
    }

    private function lockCounter(int $fiscalYearId, int $seriesId): VoucherNumberCounter
    {
        $counter = VoucherNumberCounter::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('series_id', $seriesId)
            ->lockForUpdate()
            ->first();

        // Safe without a race: the fiscal year row lock is held, so no other
        // posting in this year can create the same counter concurrently.
        return $counter ?? VoucherNumberCounter::create([
            'fiscal_year_id' => $fiscalYearId,
            'series_id' => $seriesId,
            'next_number' => 1,
        ]);
    }
}
