<?php

declare(strict_types=1);

namespace App\Actions\TaxAccount;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\VoucherSource;
use App\Domain\TaxAccount\TaxAccountMatcher;
use App\Domain\TaxAccount\TaxAccountProposals;
use App\Models\Account;
use App\Models\Company;
use App\Models\TaxAccountPosting;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Skatteverket\SkatteverketFailed;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Books one transaction from the tax account that is not in the books,
 * after a person has chosen the counter account and confirmed it (ADR-054
 * stage 3). The transaction is read again from Skatteverket, never taken
 * from the browser, and must still be missing from 1630 in the same
 * reconciliation. One voucher in series A, dated the day the transaction
 * went onto the tax account: 1630 against the chosen account, through
 * SaveDraftVoucher and PostVoucher like every other voucher. A transaction
 * is booked at most once (one row in force per transaction in
 * tax_account_postings); only when that voucher has been corrected may it be
 * booked again, and the earlier row is then marked superseded.
 *
 * TODO(legal-verify): the voucher's underlag is the tax account statement at
 * Skatteverket; whether the person must also keep a skattekontoutdrag as
 * verifikation (BFL 5 kap. 6–7 §§) is not yet verified, so the manual says to.
 */
class BookTaxAccountTransaction
{
    public const string SERIES = 'A';

    public function __construct(private readonly BookVoucher $bookVoucher) {}

    /**
     * @throws AuthorizationException|ValidationException|SkatteverketFailed
     */
    public function __invoke(User $user, Company $company, int $transactionId, int $accountNumber, CarbonImmutable $date, CarbonImmutable $from): Voucher
    {
        ReconcileTaxAccount::authorize($user, $company, 'reports:view', 'vouchers:post');
        [$api, $omfragad] = ReconcileTaxAccount::connect($user, $company, $date, $from);

        if ($accountNumber === TaxAccountProposals::TAX_ACCOUNT) {
            throw ValidationException::withMessages(['account' => __('Välj ett annat konto än 1630.')]);
        }

        $account = CurrentCompany::run($company, fn (): ?Account => Account::query()->where('number', $accountNumber)->where('is_active', true)->first())
            ?? throw ValidationException::withMessages(['account' => __('Konto :number finns inte eller är inaktivt i kontoplanen.', ['number' => $accountNumber])]);

        $transactions = $api->transactions($omfragad, $from);
        $start = $transactions['from'] !== null ? CarbonImmutable::parse($transactions['from']) : $from;
        $day = $date->greaterThan(CarbonImmutable::today()) ? CarbonImmutable::today() : $date;

        try {
            return CurrentCompany::run($company, fn (): Voucher => DB::transaction(function () use ($user, $company, $transactionId, $account, $transactions, $start, $day): Voucher {
                // One booking at a time per company, so the check below and the insert cannot race.
                Company::query()->whereKey($company->id)->lockForUpdate()->first();

                // A booking in force blocks a new one, unless its voucher has since been corrected.
                $earlier = TaxAccountPosting::query()->where('transaction_id', $transactionId)->whereNull('superseded_at')->first();

                if ($earlier !== null && Voucher::query()->where('source_type', $earlier->getMorphClass())->where('source_id', (string) $earlier->id)->whereNotNull('corrected_by_voucher_id')->doesntExist()) {
                    throw ValidationException::withMessages(['transaction' => __('Transaktionen är redan bokförd från skattekontot.')]);
                }

                $row = collect(ReconcileTaxAccount::reconcile($company, $transactions['earlier'], $start, $day)['transactions'])->firstWhere('id', $transactionId)
                    ?? throw ValidationException::withMessages(['transaction' => __('Skatteverket visar inte transaktionen för perioden längre. Hämta skattekontot på nytt.')]);

                if ($row['status'] !== TaxAccountMatcher::MISSING) {
                    throw ValidationException::withMessages(['transaction' => $row['status'] === TaxAccountMatcher::MANUAL
                        ? __('Transaktionen gäller Kronofogden eller saknar belopp hos Skatteverket. Bokför den för hand.')
                        : __('Transaktionen finns redan i bokföringen.')]);
                }

                $amount = TaxAccountMatcher::ore($row);
                $earlier?->markSuperseded();
                $posting = TaxAccountPosting::create([
                    'transaction_id' => $transactionId,
                    'transaction_date' => $row['date'],
                    'amount_ore' => Money::ofOre($amount),
                    'account_number' => $account->number,
                    'confirmed_by' => $user->id,
                ]);

                $voucher = ($this->bookVoucher)(
                    $user,
                    $company,
                    CarbonImmutable::parse($row['date']),
                    $row['text'] === '' ? __('Skattekontot') : __('Skattekontot: :text', ['text' => $row['text']]),
                    self::SERIES,
                    [TaxAccountProposals::TAX_ACCOUNT => $amount, $account->number => -$amount],
                    VoucherSource::Manual,
                    $posting,
                );

                Audit::record('tax_account.transaction_booked', $posting, [
                    'transaction_id' => $transactionId,
                    'amount_ore' => $amount,
                    'account' => $account->number,
                    'proposed_account' => $row['proposal'],
                    'voucher' => $voucher->reference(),
                    'supersedes' => $earlier?->id,
                ], $company, $user);

                return $voucher;
            }));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['transaction' => __('Transaktionen är redan bokförd från skattekontot.')]);
        }
    }
}
