<?php

declare(strict_types=1);

namespace App\Actions\TaxAccount;

use App\Domain\TaxAccount\TaxAccountLedger;
use App\Domain\TaxAccount\TaxAccountMatcher;
use App\Domain\TaxAccount\TaxAccountProposals;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Support\Skatteverket\Redovisare;
use App\Support\Skatteverket\Skattekonto\SkattekontoApi;
use App\Support\Skatteverket\Skatteverket;
use App\Support\Skatteverket\SkatteverketAuth;
use App\Support\Skatteverket\SkatteverketFailed;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Reads the company's tax account at Skatteverket and puts it next to
 * account 1630 (ADR-054 stage 3): the balance at Skatteverket and in the
 * books on the same day, which transactions are not in the books (with a
 * proposed counter account), which postings on 1630 are not on the tax
 * account, the coming transactions and the payment blocks.
 *
 * Nothing from Skatteverket is stored: it is fetched when the person asks
 * and shown. Reading needs reports:view.
 *
 * @phpstan-type Reconciliation array{date: string, latest: bool, from: string, balance: array{skatteverket: int, kronofogden: int, interestSkatteverket: int, interestKronofogden: int, ocr: string, nextReconciliation: ?string, updatedAt: ?string, information: list<string>}, ledger_ore: ?int, difference_ore: ?int, transactions: list<array{id: int, date: string, interestDate: ?string, text: string, skatteverket: ?int, kronofogden: ?int, status: string, reference: ?string, previous: ?string, proposal: ?int, typical: ?int}>, unmatched: list<array{voucher_id: string, reference: string, date: string, amount_ore: int}>, coming: list<array{id: ?int, date: string, dueDate: ?string, decidedAt: ?string, text: string, skatteverket: ?int, kronofogden: ?int}>, blocks: list<array{id: int, from: string, until: ?string, amount: ?int, total: bool}>, information: list<string>}
 */
class ReconcileTaxAccount
{
    /**
     * @return Reconciliation
     *
     * @throws AuthorizationException|ValidationException|SkatteverketFailed
     */
    public function __invoke(User $user, Company $company, CarbonImmutable $date, CarbonImmutable $from): array
    {
        self::authorize($user, $company, 'reports:view');
        [$api, $omfragad] = self::connect($user, $company, $date, $from);

        // A day from today on is the balance when the tax account was last updated (RAML: the latest allowed date).
        $latest = $date->greaterThanOrEqualTo(CarbonImmutable::today());
        $balance = $api->balance($omfragad, $latest ? null : $date);
        $transactions = $api->transactions($omfragad, $from);
        $blocks = $api->paymentBlocks($omfragad);

        $day = $latest && $balance['updatedAt'] !== null
            ? CarbonImmutable::parse($balance['updatedAt'])->setTimezone((string) config('app.timezone'))->startOfDay()
            : $date->startOfDay();
        $start = $transactions['from'] !== null ? CarbonImmutable::parse($transactions['from']) : $from;

        $reconciliation = CurrentCompany::run($company, fn (): array => self::reconcile($company, $transactions['earlier'], $start, $day));
        $ledger = CurrentCompany::run($company, fn (): ?int => TaxAccountLedger::balance($day));

        return [
            'date' => $day->toDateString(),
            'latest' => $latest,
            'from' => $start->toDateString(),
            'balance' => $balance,
            'ledger_ore' => $ledger,
            // TODO(legal-verify): 1630 is compared with Skatteverket's part of the tax account only; whether a debt handed to Kronofogden stays on 1630 or is booked elsewhere is left to the person.
            'difference_ore' => $ledger === null ? null : $ledger - $balance['skatteverket'] * 100,
            ...$reconciliation,
            'coming' => $transactions['coming'],
            'blocks' => $blocks['blocks'],
            'information' => array_values(array_unique([...$balance['information'], ...$transactions['information'], ...$blocks['information']])),
        ];
    }

    /**
     * The tax account's earlier transactions from a day up to a day,
     * matched against 1630, each missing one with a proposed account.
     *
     * @param  list<array{id: int, date: string, interestDate: ?string, text: string, skatteverket: ?int, kronofogden: ?int}>  $earlier
     * @return array{transactions: list<array{id: int, date: string, interestDate: ?string, text: string, skatteverket: ?int, kronofogden: ?int, status: string, reference: ?string, previous: ?string, proposal: ?int, typical: ?int}>, unmatched: list<array{voucher_id: string, reference: string, date: string, amount_ore: int}>}
     */
    public static function reconcile(Company $company, array $earlier, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $window = array_values(array_filter($earlier, fn (array $row): bool => $row['date'] >= $from->toDateString() && $row['date'] <= $to->toDateString()));
        $matched = TaxAccountMatcher::match($window, TaxAccountLedger::entries($from, $to), TaxAccountLedger::booked(array_column($window, 'id')));

        $typical = array_map(fn (array $row): ?int => $row['status'] === TaxAccountMatcher::MISSING ? TaxAccountProposals::account($row['text'], (int) $row['skatteverket'], $company->legal_form) : null, $matched['transactions']);
        $proposals = self::proposals($company, array_values(array_filter($typical)), $to);

        return [
            'transactions' => array_map(fn (array $row, ?int $usual): array => [
                ...$row,
                'proposal' => $usual === null ? null : $proposals[$usual],
                // The usual BAS account, named when it cannot be preselected.
                'typical' => $usual !== null && $proposals[$usual] === null ? $usual : null,
            ], $matched['transactions'], $typical),
            'unmatched' => $matched['unmatched'],
        ];
    }

    /**
     * What to preselect for each usual BAS account: the account itself when
     * it is active in the company's chart, else nothing; for employer
     * contributions the account the books use (TaxAccountProposals::contributions).
     *
     * @param  list<int>  $usual
     * @return array<int, ?int>
     */
    private static function proposals(Company $company, array $usual, CarbonImmutable $to): array
    {
        if ($usual === []) {
            return [];
        }

        $contributionAccounts = [TaxAccountProposals::EMPLOYER_CONTRIBUTIONS_PAYROLL, TaxAccountProposals::EMPLOYER_CONTRIBUTIONS];
        $active = Account::query()->whereIn('number', array_values(array_unique([...$usual, ...$contributionAccounts])))->where('is_active', true)->pluck('number')->map(fn (mixed $number): int => (int) $number)->all();
        $proposals = [];

        foreach (array_unique($usual) as $account) {
            $proposals[$account] = in_array($account, $active, true) ? $account : null;
        }

        if (in_array(TaxAccountProposals::EMPLOYER_CONTRIBUTIONS, $usual, true)) {
            $both = array_values(array_intersect($contributionAccounts, $active));
            $proposals[TaxAccountProposals::EMPLOYER_CONTRIBUTIONS] = TaxAccountProposals::contributions(
                $both,
                app(ModuleManager::class)->isEnabled(PayrollModule::KEY, $company),
                count($both) === 2 ? TaxAccountLedger::balances($both, $to) : [],
            );
        }

        return $proposals;
    }

    /** @throws AuthorizationException */
    public static function authorize(User $user, Company $company, string ...$permissions): void
    {
        if (! Skatteverket::isAvailableTo($user)) {
            throw new AuthorizationException;
        }

        foreach ($permissions as $permission) {
            if (! $user->hasTeamPermission($company, $permission)) {
                throw new AuthorizationException;
            }
        }
    }

    /**
     * @return array{0: SkattekontoApi, 1: string}
     *
     * @throws ValidationException|SkatteverketFailed
     */
    public static function connect(User $user, Company $company, CarbonImmutable $date, CarbonImmutable $from): array
    {
        if ($from->greaterThan($date)) {
            throw ValidationException::withMessages(['from' => __('Från och med-datumet ska vara före avstämningsdagen.')]);
        }

        $omfragad = Redovisare::of((string) $company->org_number)
            ?? throw ValidationException::withMessages(['company' => __('Företagets organisationsnummer saknas eller är fel. Fyll i det i företagets uppgifter först.')]);
        $token = SkatteverketAuth::accessToken($user, Skatteverket::SCOPE_SKATTEKONTO)
            ?? throw ValidationException::withMessages(['skatteverket' => __('Logga in hos Skatteverket med e-legitimation först.')]);

        return [new SkattekontoApi($token), $omfragad];
    }
}
