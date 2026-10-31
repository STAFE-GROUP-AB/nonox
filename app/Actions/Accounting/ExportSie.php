<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\Dimension;
use App\Domain\Accounting\LedgerBalances;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Company\LegalForm;
use App\Domain\Company\OrganisationNumber;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Models\VoucherRowHistory;
use App\Models\VoucherSeries;
use App\Support\Audit\Audit;
use App\Support\Build\BuildInfo;
use App\Support\Sie\SieAmount;
use App\Support\Sie\SieDate;
use App\Support\Sie\SieText;
use App\Support\Sie\SieWriter;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Exports one fiscal year as a SIE 4E file (SIE 4C): identification, the
 * chart of accounts, opening and closing balances and results for the year
 * and the year before, and every posted voucher. Drafts are not part of the
 * bookkeeping and are left out.
 */
class ExportSie
{
    /** @var array<string, array<int, string>> row column => object id => code */
    private array $objectCodes = [];

    /**
     * @param  resource  $stream
     */
    public function __invoke(User $user, FiscalYear $fiscalYear, $stream): void
    {
        Gate::forUser($user)->authorize('exportSie', $fiscalYear);

        CurrentCompany::run($fiscalYear->company_id, function () use ($user, $fiscalYear, $stream): void {
            $company = Company::query()->findOrFail($fiscalYear->company_id);
            $previous = FiscalYear::query()->whereDate('end_date', $fiscalYear->start_date->subDay())->first();
            $accounts = Account::query()->orderBy('number')->get();

            $writer = new SieWriter($stream);
            $writer->record('#FLAGGA', [0]);
            $writer->beginChecksum();

            $this->identification($writer, $company, $user, $fiscalYear, $previous);
            $this->chartOfAccounts($writer, $accounts);
            $this->dimensions($writer);
            $this->balances($writer, $accounts, $fiscalYear, $previous);
            $vouchers = $this->vouchers($writer, $accounts, $fiscalYear);

            $writer->finish();

            Audit::record('sie.exported', $fiscalYear, [
                'fiscal_year' => $fiscalYear->label(),
                'vouchers' => $vouchers,
            ], $company->id, $user);
        });
    }

    public static function filename(Company $company, FiscalYear $fiscalYear): string
    {
        $organisationNumber = OrganisationNumber::tryFrom((string) $company->org_number)->digits ?? (string) $company->id;

        return "SIE4_{$organisationNumber}_".str_replace('/', '-', $fiscalYear->label()).'.se';
    }

    private function identification(SieWriter $writer, Company $company, User $user, FiscalYear $fiscalYear, ?FiscalYear $previous): void
    {
        $writer->record('#FORMAT', ['PC8']);
        $writer->record('#SIETYP', [4]);
        $writer->record('#PROGRAM', [new SieText((string) config('app.name')), BuildInfo::version()]);
        $writer->record('#GEN', [SieDate::format(CarbonImmutable::now()), new SieText($user->name)]);

        $organisationNumber = OrganisationNumber::tryFrom((string) $company->org_number);

        if ($organisationNumber !== null) {
            $writer->record('#ORGNR', [$organisationNumber->formatted()]);
        }

        $writer->record('#FNAMN', [new SieText($company->legal_name ?: $company->name)]);

        $postalAddress = trim("{$company->postal_code} {$company->city}");

        if ($company->address_line1 || $postalAddress !== '' || $company->phone) {
            $writer->record('#ADRESS', [
                new SieText(''),
                new SieText((string) $company->address_line1),
                new SieText($postalAddress),
                new SieText((string) $company->phone),
            ]);
        }

        $writer->record('#FTYP', [$this->companyType($company->legal_form)]);

        $writer->record('#RAR', [0, SieDate::format($fiscalYear->start_date), SieDate::format($fiscalYear->end_date)]);

        if ($previous !== null) {
            $writer->record('#RAR', [-1, SieDate::format($previous->start_date), SieDate::format($previous->end_date)]);
        }

        // SIE 4C: a chart type starting with "BAS2", such as BAS2026, is read as EUBAS97.
        if (preg_match('/^BAS ?(\d{4})$/', (string) $company->bas_version, $match)) {
            $writer->record('#KPTYP', ["BAS{$match[1]}"]);
        }

        $writer->record('#VALUTA', [$company->base_currency ?: 'SEK']);
    }

    /**
     * The SIE company types are Bolagsverket's (#FTYP).
     */
    private function companyType(LegalForm $legalForm): string
    {
        return match ($legalForm) {
            LegalForm::Ab => 'AB',
            LegalForm::Ef => 'E',
            LegalForm::Hb => 'HB',
            LegalForm::Kb => 'KB',
            LegalForm::EkonomiskForening => 'EK',
            // TODO(legal-verify): SIE's "I" and "S" are associations and foundations that conduct business (bedriver näring).
            LegalForm::IdeellForening => 'I',
            LegalForm::Stiftelse => 'S',
        };
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function chartOfAccounts(SieWriter $writer, Collection $accounts): void
    {
        foreach ($accounts as $account) {
            $writer->record('#KONTO', [$account->number, new SieText($account->name)]);
        }

        foreach ($accounts as $account) {
            $writer->record('#KTYP', [$account->number, match ($account->type) {
                AccountType::Asset => 'T',
                AccountType::LiabilityEquity => 'S',
                AccountType::Revenue => 'I',
                AccountType::Expense => 'K',
            }]);
        }

        foreach ($accounts as $account) {
            if ($account->sru_code) {
                $writer->record('#SRU', [$account->number, (string) $account->sru_code]);
            }
        }
    }

    /**
     * #DIM and #OBJEKT for cost centres (1) and projects (6), when the
     * company has any (SIE 4C §8).
     */
    private function dimensions(SieWriter $writer): void
    {
        $this->objectCodes = [];
        $objects = [];

        foreach (Dimension::cases() as $dimension) {
            $objects[$dimension->value] = $dimension->model()::query()->orderBy('code')->get();
            $this->objectCodes[$dimension->column()] = $objects[$dimension->value]->pluck('code', 'id')->all();
        }

        foreach (Dimension::cases() as $dimension) {
            if ($objects[$dimension->value]->isNotEmpty()) {
                $writer->record('#DIM', [$dimension->sieNumber(), new SieText($dimension->label())]);
            }
        }

        foreach (Dimension::cases() as $dimension) {
            foreach ($objects[$dimension->value] as $object) {
                $writer->record('#OBJEKT', [$dimension->sieNumber(), new SieText($object->code), new SieText($object->name)]);
            }
        }
    }

    /**
     * The object list of a row: {1 "10" 6 "P1"}.
     *
     * @return list<int|SieText>
     */
    private function objects(?int $costCenterId, ?int $projectId): array
    {
        $objects = [];

        foreach ([[Dimension::CostCenter, $costCenterId], [Dimension::Project, $projectId]] as [$dimension, $id]) {
            $code = $id === null ? null : ($this->objectCodes[$dimension->column()][$id] ?? null);

            if ($code !== null) {
                $objects[] = $dimension->sieNumber();
                $objects[] = new SieText($code);
            }
        }

        return $objects;
    }

    /**
     * #IB and #UB for balance-sheet accounts, #RES for the income statement,
     * for the year (0) and the year before (-1). Zero balances are left out.
     *
     * @param  Collection<int, Account>  $accounts
     */
    private function balances(SieWriter $writer, Collection $accounts, FiscalYear $fiscalYear, ?FiscalYear $previous): void
    {
        $years = array_filter([0 => $fiscalYear, -1 => $previous]);
        $figures = [];

        foreach ($years as $index => $year) {
            $figures[$index] = [
                'opening' => LedgerBalances::opening($year),
                'movements' => LedgerBalances::movements($year),
            ];
        }

        foreach (['#IB', '#UB', '#RES'] as $label) {
            foreach ($years as $index => $year) {
                foreach ($accounts as $account) {
                    $balanceSheet = LedgerBalances::isBalanceSheet($account->type);
                    $opening = (int) ($figures[$index]['opening'][$account->id] ?? 0);
                    $movement = (int) ($figures[$index]['movements'][$account->id] ?? 0);

                    $amount = match ($label) {
                        '#IB' => $balanceSheet ? $opening : 0,
                        '#UB' => $balanceSheet ? $opening + $movement : 0,
                        default => $balanceSheet ? 0 : $movement,
                    };

                    if ($amount !== 0) {
                        $writer->record($label, [$index, $account->number, SieAmount::format($amount)]);
                    }
                }
            }
        }
    }

    /**
     * Every posted voucher, series by series in number order (§11 #VER.7).
     * Read in chunks so a large year never has to fit in memory.
     *
     * @param  Collection<int, Account>  $accounts
     */
    private function vouchers(SieWriter $writer, Collection $accounts, FiscalYear $fiscalYear): int
    {
        $numbers = $accounts->pluck('number', 'id');
        $posted = Voucher::query()->where('fiscal_year_id', $fiscalYear->id)->where('status', VoucherStatus::Posted->value);
        $signatures = User::query()->whereIn('id', (clone $posted)->select('posted_by'))->pluck('name', 'id');
        $count = 0;

        foreach (VoucherSeries::query()->orderBy('code')->get() as $series) {
            (clone $posted)
                ->where('series_id', $series->id)
                ->orderBy('number')
                ->with(['rows', 'importedFrom', 'rowHistory'])
                ->chunk(500, function (Collection $vouchers) use ($writer, $series, $numbers, $signatures, &$count): void {
                    /** @var Collection<int, Voucher> $vouchers */
                    foreach ($vouchers as $voucher) {
                        $writer->voucher([
                            $series->code,
                            (int) $voucher->number,
                            SieDate::format($voucher->voucher_date),
                            new SieText($voucher->description),
                            ...$this->registration($voucher, $signatures),
                        ], $this->transactions($voucher, $numbers));

                        $count++;
                    }
                });
        }

        return $count;
    }

    /**
     * Regdatum and sign: when and by whom the voucher was registered. For a
     * voucher read in from a SIE file, the file's own values.
     *
     * @param  SupportCollection<int, string>  $signatures
     * @return list<string|SieText|null>
     */
    private function registration(Voucher $voucher, SupportCollection $signatures): array
    {
        $imported = $voucher->importedFrom;

        if ($imported !== null) {
            return [
                $imported->registered_on ? SieDate::format($imported->registered_on) : null,
                $imported->signature !== null ? new SieText($imported->signature) : null,
            ];
        }

        return [
            $voucher->posted_at ? SieDate::format($voucher->posted_at->setTimezone(config('app.timezone'))) : null,
            new SieText((string) ($signatures[$voucher->posted_by] ?? '')),
        ];
    }

    /**
     * The voucher's rows, with rows read in as added (#RTRANS) or removed
     * (#BTRANS) written back where they were.
     *
     * @param  SupportCollection<int, int>  $numbers
     * @return list<array{0: string, 1: list<int|string|SieText|list<int|string|SieText>|null>}>
     */
    private function transactions(Voucher $voucher, SupportCollection $numbers): array
    {
        $added = $voucher->rowHistory->where('change', VoucherRowHistory::ADDED)->keyBy('voucher_row_id');
        $removed = $voucher->rowHistory->where('change', VoucherRowHistory::REMOVED)->groupBy('position');
        $records = [];

        foreach ($voucher->rows->values() as $position => $row) {
            foreach ($removed->get($position, []) as $entry) {
                $records[] = ['#BTRANS', $this->historyFields($entry)];
            }

            $entry = $added->get($row->id);

            if ($entry instanceof VoucherRowHistory) {
                $fields = $this->historyFields($entry);
                $records[] = ['#RTRANS', $fields];
                $records[] = ['#TRANS', array_slice($fields, 0, 6)];

                continue;
            }

            $records[] = $this->transaction($row, $voucher, $numbers);
        }

        foreach ($removed->get($voucher->rows->count(), []) as $entry) {
            $records[] = ['#BTRANS', $this->historyFields($entry)];
        }

        return $records;
    }

    /**
     * @return list<int|string|SieText|list<int|string|SieText>|null>
     */
    private function historyFields(VoucherRowHistory $entry): array
    {
        return [
            $entry->account_number,
            $this->objects($entry->cost_center_id, $entry->project_id),
            SieAmount::format($entry->amount_ore->ore),
            $entry->transaction_date ? SieDate::format($entry->transaction_date) : null,
            $entry->description !== null ? new SieText($entry->description) : null,
            $entry->quantity !== null ? (string) $entry->quantity : null,
            $entry->signature !== null ? new SieText($entry->signature) : null,
        ];
    }

    /**
     * @param  SupportCollection<int, int>  $numbers
     * @return array{0: string, 1: list<int|string|SieText|list<int|string|SieText>|null>}
     */
    private function transaction(VoucherRow $row, Voucher $voucher, SupportCollection $numbers): array
    {
        $fields = [
            (int) $numbers[$row->account_id],
            $this->objects($row->cost_center_id, $row->project_id),
            SieAmount::format($row->debit_ore->ore - $row->credit_ore->ore),
        ];

        $quantity = $row->getAttribute('quantity');
        $hasText = $row->description !== null && $row->description !== '';

        if ($hasText || $quantity !== null) {
            $fields[] = SieDate::format($voucher->voucher_date);
            $fields[] = new SieText((string) $row->description);
        }

        if ($quantity !== null) {
            $fields[] = (string) $quantity;
        }

        return ['#TRANS', $fields];
    }
}
