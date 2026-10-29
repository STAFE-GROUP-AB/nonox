<?php

declare(strict_types=1);

namespace App\Domain\Accounting\SieImport;

use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\Dimension;
use App\Domain\Accounting\FiscalYearRules;
use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Company\OrganisationNumber;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\ImportedVoucher;
use App\Models\OpeningBalance;
use App\Models\SieImport;
use App\Models\Voucher;
use App\Models\VoucherSeries;
use App\Support\Money\Money;
use App\Support\Sie\SieAmount;
use App\Support\Sie\SieChecksum;
use App\Support\Sie\SieDate;
use App\Support\Sie\SieReader;
use App\Support\Sie\SieRecord;
use App\Support\Tenancy\CurrentCompany;

/**
 * Reads a SIE file once, without changing anything, and works out what an
 * import would do: the fiscal year, the accounts and series to create, how
 * vouchers are numbered, and every reason the file cannot be read in
 * (AGENTS.md §9.2). Streams the file, so its size does not matter.
 */
final class SieImportAnalyser
{
    /** Voucher problems listed one by one before the rest are counted. */
    private const int LISTED_PROBLEMS = 10;

    private SieImportPlan $plan;

    /** @var list<string> */
    private array $voucherProblems = [];

    /** @var array<int, array{name: ?string, type: ?string, sru: ?string}> */
    private array $fileAccounts = [];

    /** @var array<int, true> */
    private array $usedAccounts = [];

    /** @var array<string, list<string>> series code => the file's numbers */
    private array $numbers = [];

    private ?string $sieType = null;

    private int $truncatedTexts = 0;

    /** @var array<string, array<string, ?string>> dimension => code => name from #OBJEKT */
    private array $objects = [];

    /** @var array<string, true> SIE dimension numbers this program does not have */
    private array $otherDimensions = [];

    private int $rowsWithOtherObjects = 0;

    private ?string $earliestDate = null;

    public function analyse(Company $company, string $path, ?string $sha256 = null, ?int $ignoreImportId = null): SieImportPlan
    {
        return CurrentCompany::run($company, function () use ($company, $path, $sha256, $ignoreImportId): SieImportPlan {
            $this->reset();

            $defaultSeries = (string) (VoucherSeries::query()->where('is_default', true)->value('code') ?? 'A');
            $reader = new SieReader($path);

            foreach ($reader->records() as $record) {
                $this->read($record, $defaultSeries);
            }

            $this->checkFile($reader, $company, $sha256, $ignoreImportId);
            $fiscalYear = $this->planFiscalYear();
            $this->planAccounts();
            $this->planDimensions();
            $this->planSeries($fiscalYear);
            $this->planOpeningBalances($fiscalYear);
            $this->checkLockedPeriod($company);
            $this->listVoucherProblems();

            return $this->plan;
        });
    }

    private function reset(): void
    {
        $this->plan = new SieImportPlan;
        $this->voucherProblems = [];
        $this->fileAccounts = [];
        $this->usedAccounts = [];
        $this->numbers = [];
        $this->sieType = null;
        $this->truncatedTexts = 0;
        $this->objects = [];
        $this->otherDimensions = [];
        $this->rowsWithOtherObjects = 0;
        $this->earliestDate = null;
    }

    private function read(SieRecord $record, string $defaultSeries): void
    {
        $number = (int) $record->field(0);

        match ($record->label) {
            '#SIETYP' => $this->sieType = $record->field(0),
            '#PROGRAM' => $this->plan->program = trim($record->field(0).' '.$record->field(1)),
            '#ORGNR' => $this->plan->organisationNumber = $record->field(0),
            '#FNAMN' => $this->plan->companyName = $record->field(0),
            '#RAR' => $this->readYear($record),
            '#KONTO' => $this->describeAccount($number, 'name', $record->field(1)),
            '#KTYP' => $this->describeAccount($number, 'type', $record->field(1)),
            '#SRU' => $this->describeAccount($number, 'sru', $record->field(1)),
            '#IB', '#UB', '#RES' => $this->readBalance($record),
            '#VER' => $this->readVoucher(SieVoucher::fromRecord($record), $defaultSeries),
            '#DIM', '#UNDERDIM' => $this->readDimension((string) $record->field(0)),
            '#OBJEKT' => $this->readObject((string) $record->field(0), (string) $record->field(1), $record->field(2)),
            default => null,
        };
    }

    /**
     * @param  'name'|'type'|'sru'  $key
     */
    private function describeAccount(int $number, string $key, ?string $value): void
    {
        $this->fileAccounts[$number] ??= ['name' => null, 'type' => null, 'sru' => null];
        $this->fileAccounts[$number][$key] = $value;
    }

    private function readDimension(string $number): void
    {
        if (preg_match('/^\d+$/', $number) === 1 && Dimension::fromSieNumber((int) $number) === null) {
            $this->otherDimensions[$number] = true;
        }
    }

    private function readObject(string $number, string $code, ?string $name): void
    {
        $dimension = preg_match('/^\d+$/', $number) === 1 ? Dimension::fromSieNumber((int) $number) : null;

        if ($dimension === null || $code === '') {
            return;
        }

        $this->objects[$dimension->value][$code] = $name;
    }

    private function useObjects(SieTransaction $row): void
    {
        $other = false;

        foreach ($row->objects as $number => $code) {
            $dimension = Dimension::fromSieNumber((int) $number);

            if ($dimension === null) {
                $this->otherDimensions[(string) $number] = true;
                $other = true;

                continue;
            }

            $this->objects[$dimension->value][$code] ??= null;
        }

        if ($other) {
            $this->rowsWithOtherObjects++;
        }
    }

    private function readYear(SieRecord $record): void
    {
        if ($record->field(0) !== '0') {
            return;
        }

        $this->plan->start = SieDate::parse((string) $record->field(1));
        $this->plan->end = SieDate::parse((string) $record->field(2));
    }

    private function readBalance(SieRecord $record): void
    {
        if ($record->field(0) !== '0') {
            return;
        }

        $account = (string) $record->field(1);
        $amount = SieAmount::toOre((string) $record->field(2));

        if (preg_match('/^\d{4}$/', $account) !== 1 || $amount === null) {
            $this->voucherProblems[] = __('Rad :line: saldot för konto ":account" går inte att läsa.', ['line' => $record->line, 'account' => $account]);

            return;
        }

        $number = (int) $account;
        $this->usedAccounts[$number] = true;

        match ($record->label) {
            '#IB' => $this->plan->openingBalances[$number] = $amount,
            '#UB' => $this->plan->closingBalances[$number] = $amount,
            default => $this->plan->results[$number] = $amount,
        };
    }

    private function readVoucher(SieVoucher $voucher, string $defaultSeries): void
    {
        $series = $voucher->series !== '' ? $voucher->series : $defaultSeries;
        $reference = __('Verifikation :reference', ['reference' => $voucher->reference()]);

        $this->plan->vouchers++;
        $this->plan->rows += count($voucher->rows);
        $this->plan->addedRows += count(array_filter($voucher->rows, fn (SieTransaction $row): bool => $row->label === '#RTRANS'));
        $this->plan->removedRows += count($voucher->removed);
        $this->numbers[$series][] = $voucher->number;

        if ($voucher->truncated) {
            $this->truncatedTexts++;
        }

        foreach ($voucher->problems as $problem) {
            $this->voucherProblems[] = "{$reference}: {$problem}";
        }

        foreach ($voucher->rows as $row) {
            $this->usedAccounts[$row->account] = true;
            $this->useObjects($row);
        }

        foreach ($voucher->removed as $removed) {
            $this->useObjects($removed['row']);
        }

        if (count($voucher->rows) < 2) {
            $this->voucherProblems[] = __(':reference har färre än två rader.', ['reference' => $reference]);
        } elseif ($voucher->debit() !== $voucher->credit()) {
            $this->voucherProblems[] = __(':reference balanserar inte: debet :debit och kredit :credit.', [
                'reference' => $reference,
                'debit' => SieAmount::format($voucher->debit()),
                'credit' => SieAmount::format($voucher->credit()),
            ]);
        } elseif ($voucher->debit() === 0) {
            $this->voucherProblems[] = __(':reference saknar belopp.', ['reference' => $reference]);
        }

        if ($voucher->date === null) {
            return;
        }

        if ($this->plan->start === null || $this->plan->end === null) {
            $this->voucherProblems[] = __(':reference kommer före räkenskapsåret (#RAR) i filen.', ['reference' => $reference]);

            return;
        }

        if (! $voucher->date->betweenIncluded($this->plan->start, $this->plan->end)) {
            $this->voucherProblems[] = __(':reference har datumet :date, som ligger utanför räkenskapsåret.', [
                'reference' => $reference,
                'date' => $voucher->date->toDateString(),
            ]);
        }

        $date = $voucher->date->toDateString();
        $this->earliestDate = $this->earliestDate === null ? $date : min($this->earliestDate, $date);
    }

    private function checkFile(SieReader $reader, Company $company, ?string $sha256, ?int $ignoreImportId): void
    {
        $this->plan->checksum = match ($reader->checksum()) {
            SieChecksum::Valid => 'valid',
            SieChecksum::Invalid => 'invalid',
            SieChecksum::Unterminated => 'unterminated',
            SieChecksum::Absent => 'absent',
        };

        foreach ($reader->problems() as $problem) {
            $this->plan->block($problem);
        }

        if ($reader->checksum() === SieChecksum::Absent) {
            $this->plan->warn(__('Filen saknar kontrollsumma, så det går inte att se om den har ändrats på vägen. Kontrollera avstämningen efteråt extra noga.'));
        }

        if ($reader->utf8Lines() > 0) {
            $this->plan->warn(__(':count rader är skrivna i UTF-8 i stället för teckentabell 437. De läses ändå rätt.', ['count' => $reader->utf8Lines()]));
        }

        if ($this->sieType !== '4') {
            $this->plan->block($this->sieType === null
                ? __('Filen saknar uppgift om SIE-typ. Det verkar inte vara en SIE-fil.')
                : __('Filen är av SIE-typ :type. Bara typ 4 innehåller verifikationerna, så ta fram en SIE4-fil i stället.', ['type' => $this->sieType]));
        }

        $theirs = OrganisationNumber::tryFrom((string) $this->plan->organisationNumber);
        $ours = OrganisationNumber::tryFrom((string) $company->org_number);

        if ($this->plan->organisationNumber === null) {
            $this->plan->warn(__('Filen anger inget organisationsnummer. Kontrollera att den gäller rätt företag.'));
        } elseif ($theirs !== null && $ours !== null && $theirs->digits !== $ours->digits) {
            $this->plan->block(__('Filen gäller organisationsnummer :theirs, men det här företaget har :ours. Ladda upp filen i rätt företag.', [
                'theirs' => $theirs->formatted(),
                'ours' => $ours->formatted(),
            ]));
        }

        if ($sha256 !== null && SieImport::query()
            ->where('sha256', $sha256)
            ->where('status', SieImportStatus::Completed->value)
            ->when($ignoreImportId !== null, fn ($query) => $query->whereKeyNot($ignoreImportId))
            ->exists()) {
            $this->plan->block(__('Den här filen är redan inläst.'));
        }

        if ($this->truncatedTexts > 0) {
            $this->plan->warn(__(':count verifikationer har texter längre än 255 tecken. Texterna kortas.', ['count' => $this->truncatedTexts]));
        }

        if ($this->otherDimensions !== []) {
            $this->plan->warn(__('Filen har dimension :dimensions. Här finns bara kostnadsställen (1) och projekt (6), så :count rader läses in utan de objekten.', [
                'dimensions' => implode(', ', array_keys($this->otherDimensions)),
                'count' => $this->rowsWithOtherObjects,
            ]));
        }
    }

    private function planFiscalYear(): ?FiscalYear
    {
        $start = $this->plan->start;
        $end = $this->plan->end;

        if ($start === null || $end === null) {
            $this->plan->block(__('Filen saknar räkenskapsår (#RAR 0).'));

            return null;
        }

        $label = (string) $this->plan->fiscalYearLabel();
        $existing = FiscalYear::query()->whereDate('start_date', $start)->whereDate('end_date', $end)->first();

        if ($existing instanceof FiscalYear) {
            if ($existing->status === FiscalYearStatus::Closed) {
                $this->plan->block(__('Räkenskapsåret :label är stängt här. Det går inte att läsa in något i ett stängt år.', ['label' => $label]));
            }

            $this->plan->fiscalYearId = $existing->id;

            return $existing;
        }

        $overlapping = FiscalYear::query()->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->first();

        if ($overlapping instanceof FiscalYear) {
            $this->plan->block(__('Filens räkenskapsår :start – :end överlappar räkenskapsåret :label här, som har andra datum. Ändra datumen under Räkenskapsår så att de stämmer med filen.', [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'label' => $overlapping->label(),
            ]));

            return null;
        }

        $earliest = FiscalYear::query()->orderBy('start_date')->first();
        $latest = FiscalYear::query()->orderByDesc('end_date')->first();
        $adjacent = $earliest === null
            || $end->addDay()->isSameDay($earliest->start_date)
            || ($latest !== null && $start->subDay()->isSameDay($latest->end_date));

        if (! $adjacent) {
            $this->plan->block(__('Räkenskapsåret :label ligger inte direkt före eller efter dina räkenskapsår här. Läs in åren i tur och ordning, närmast först.', ['label' => $label]));

            return null;
        }

        $problem = FiscalYearRules::problem($start, $end, mayDeviate: true);

        if ($problem !== null) {
            $this->plan->block($problem);

            return null;
        }

        $this->plan->createFiscalYear = true;

        return null;
    }

    private function planAccounts(): void
    {
        $numbers = array_keys($this->usedAccounts);
        $outOfRange = array_values(array_filter($numbers, fn (int $number): bool => $number < 1000 || $number > 9999));

        if ($outOfRange !== []) {
            $this->plan->block(__('Konto :accounts har inte fyra siffror och kan inte läsas in.', ['accounts' => implode(', ', $outOfRange)]));
        }

        $existing = Account::query()->whereIn('number', $numbers)->get()->keyBy('number');
        $renamed = 0;
        $inactive = [];

        sort($numbers);

        foreach ($numbers as $number) {
            $file = $this->fileAccounts[$number] ?? ['name' => null, 'type' => null, 'sru' => null];
            $account = $existing->get($number);

            if ($account instanceof Account) {
                if ($file['name'] !== null && $file['name'] !== '' && mb_strtolower($file['name']) !== mb_strtolower($account->name)) {
                    $renamed++;
                }

                if (! $account->is_active) {
                    $inactive[] = $number;
                }

                continue;
            }

            if (in_array($number, $outOfRange, true)) {
                continue;
            }

            $this->plan->accountsToCreate[$number] = [
                'name' => $file['name'] !== null && $file['name'] !== '' ? mb_substr($file['name'], 0, 255) : __('Konto :number', ['number' => $number]),
                'type' => match ($file['type']) {
                    'T' => AccountType::Asset,
                    'S' => AccountType::LiabilityEquity,
                    'I' => AccountType::Revenue,
                    'K' => AccountType::Expense,
                    default => AccountType::forNumber($number),
                },
                'sru' => $file['sru'] !== null && preg_match('/^\d{1,10}$/', $file['sru']) === 1 ? $file['sru'] : null,
            ];
        }

        $this->plan->unusedAccounts = count(array_diff(array_keys($this->fileAccounts), $numbers));

        if ($renamed > 0) {
            $this->plan->warn(__(':count konton heter något annat i filen än här. Namnen här behålls.', ['count' => $renamed]));
        }

        if ($inactive !== []) {
            $this->plan->warn(__('Konto :accounts är inaktiverat här men används i filen. Verifikationerna läses in ändå.', ['accounts' => implode(', ', $inactive)]));
        }
    }

    /**
     * Every cost centre and project the file defines or uses that does not
     * exist here is created, so the register comes along whole.
     */
    private function planDimensions(): void
    {
        foreach (Dimension::cases() as $dimension) {
            $codes = $this->objects[$dimension->value] ?? [];

            if ($codes === []) {
                continue;
            }

            $tooLong = array_filter(array_keys($codes), fn (int|string $code): bool => mb_strlen((string) $code) > 20);

            if ($tooLong !== []) {
                $this->plan->block(__(':label :code har en för lång kod. En kod får ha högst 20 tecken.', ['label' => $dimension->label(), 'code' => (string) reset($tooLong)]));

                continue;
            }

            $existing = $dimension->model()::query()->whereIn('code', array_map(strval(...), array_keys($codes)))->pluck('code')->all();

            foreach ($codes as $code => $name) {
                $code = (string) $code;

                if (! in_array($code, $existing, true)) {
                    $this->plan->objectsToCreate[$dimension->value][$code] = $name !== null && $name !== '' ? mb_substr($name, 0, 255) : $code;
                }
            }
        }
    }

    private function planSeries(?FiscalYear $fiscalYear): void
    {
        $existingSeries = VoucherSeries::query()->get()->keyBy('code');

        if ($fiscalYear !== null) {
            $this->plan->existingVouchers = Voucher::query()
                ->where('fiscal_year_id', $fiscalYear->id)
                ->where('status', VoucherStatus::Posted->value)
                ->count();
        }

        if ($this->plan->existingVouchers > 0) {
            $this->plan->warn(__('Räkenskapsåret har redan :count bokförda verifikationer här. Verifikationerna i filen läggs till efter dem. Kontrollera att samma affärer inte blir bokförda två gånger.', ['count' => $this->plan->existingVouchers]));
        }

        foreach ($this->numbers as $code => $numbers) {
            $code = (string) $code;

            if (mb_strlen($code) > 3) {
                $this->plan->block(__('Serien ":code" har ett för långt namn. En serie får ha högst tre tecken.', ['code' => $code]));

                continue;
            }

            if (count($numbers) !== count(array_unique($numbers))) {
                $this->plan->block(__('Serie :code har samma verifikationsnummer flera gånger i filen.', ['code' => $code]));
            }

            $series = $existingSeries->get($code);
            $counter = 1;

            if ($fiscalYear !== null && $series instanceof VoucherSeries) {
                $counter = 1 + Voucher::query()
                    ->where('fiscal_year_id', $fiscalYear->id)
                    ->where('series_id', $series->id)
                    ->where('status', VoucherStatus::Posted->value)
                    ->count();

                $this->checkAlreadyImported($fiscalYear, $code, $numbers);
            }

            $sorted = $numbers;
            sort($sorted, SORT_NUMERIC);
            $gapless = $sorted === array_map(strval(...), range(1, count($numbers)));

            $this->plan->series[$code] = [
                'vouchers' => count($numbers),
                'create' => ! $series instanceof VoucherSeries,
                'keep' => $counter === 1 && $gapless,
                'first' => $counter,
            ];
        }

        ksort($this->plan->series);
    }

    /**
     * @param  list<string>  $numbers
     */
    private function checkAlreadyImported(FiscalYear $fiscalYear, string $code, array $numbers): void
    {
        $imported = ImportedVoucher::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('original_series', $code)
            ->whereIn('original_number', $numbers)
            ->orderBy('id')
            ->pluck('original_number');

        if ($imported->isNotEmpty()) {
            $this->plan->block(__(':count verifikationer i serie :code är redan inlästa, till exempel :code:number.', [
                'count' => $imported->count(),
                'code' => $code,
                'number' => $imported->first(),
            ]));
        }
    }

    private function planOpeningBalances(?FiscalYear $fiscalYear): void
    {
        $this->plan->openingBalances = array_filter($this->plan->openingBalances, fn (int $amount): bool => $amount !== 0);

        if ($this->plan->openingBalances === []) {
            return;
        }

        $existing = $fiscalYear === null ? [] : OpeningBalance::query()
            ->where('opening_balances.fiscal_year_id', $fiscalYear->id)
            ->join('accounts', 'accounts.id', '=', 'opening_balances.account_id')
            ->pluck('opening_balances.amount_ore', 'accounts.number')
            ->map(fn (Money $amount): int => $amount->ore)
            ->filter()
            ->all();

        if ($existing === []) {
            $this->plan->writeOpeningBalances = true;

            return;
        }

        $file = $this->plan->openingBalances;
        ksort($file);
        ksort($existing);

        if ($file !== $existing) {
            $this->plan->block(__('Räkenskapsåret har redan ingående balanser här, och de skiljer sig från filens.'));
        }
    }

    private function checkLockedPeriod(Company $company): void
    {
        if ($company->locked_until_date === null || $this->earliestDate === null) {
            return;
        }

        if ($this->earliestDate <= $company->locked_until_date->toDateString()) {
            $this->plan->block(__('Perioden till och med :date är låst, och filen har verifikationer i den.', ['date' => $company->locked_until_date->toDateString()]));
        }
    }

    private function listVoucherProblems(): void
    {
        foreach (array_slice($this->voucherProblems, 0, self::LISTED_PROBLEMS) as $problem) {
            $this->plan->block($problem);
        }

        $more = count($this->voucherProblems) - self::LISTED_PROBLEMS;

        if ($more > 0) {
            $this->plan->block(__('… och :count problem till av samma slag.', ['count' => $more]));
        }
    }
}
