<?php

declare(strict_types=1);

namespace App\Domain\Budget\Sie;

use App\Domain\Accounting\Dimension;
use App\Domain\Budget\BudgetMonths;
use App\Support\Money\Money;
use App\Support\Sie\SieAmount;
use App\Support\Sie\SieDate;
use App\Support\Sie\SieRecord;
use Carbon\CarbonImmutable;

/**
 * The budget of a SIE file: its #PBUDGET records (SIE filformat utgåva 4C,
 * 2025-08-06, "#PBUDGET Periodbudgetpost", p. 29) and the way they map to
 * budget lines here (ADR-081 gap 2, package E4). Pure: no database.
 *
 *   #PBUDGET årsnr period konto {dimensionsnr objektnr} saldo kvantitet
 *
 * - The amount is the period's change on the account, credit negative (item
 *   1), the same sign as budget_lines.amount_ore.
 * - årsnr 0 is the fiscal year of #RAR 0 (item 2). Other years are counted
 *   and left out: a year's budget comes in with that year's file.
 * - The period is ÅÅÅÅMM, a calendar month (item 3).
 * - A type 2 file has no objects, and a reader of type 2 ignores records
 *   that have one (item 4). A type 3 file gives the account as a whole ({})
 *   and each object on its own (item 5). The object specification is one
 *   dimension and one object, not a list as on #TRANS (§6 summary).
 * - The quantity is optional (item 6) and not kept: budgets here are amounts.
 *
 * Here a line has an account and optionally both a cost centre and a
 * project, while SIE gives the whole account and each object separately.
 * So a file says, per account and month, the whole amount T, the amount per
 * cost centre and the amount per project. Reading in writes one line per
 * cost centre, one per project and one without objects for T minus the
 * objects' sum; that keeps all three figures exact, which is all a SIE file
 * can carry. Writing out sums the lines the same three ways, so export →
 * import → export gives the same file.
 */
final class SieBudget
{
    /** Problems and notes listed one by one before the rest are counted. */
    private const int LISTED = 10;

    private ?string $type = null;

    private ?CarbonImmutable $start = null;

    private ?CarbonImmutable $end = null;

    /**
     * @var array<string, array{account: int, period: string, dimension: ?int, code: ?string, amount: int, line: int}>
     *                                                                                                                 keyed by account|period|dimension|code
     */
    private array $entries = [];

    /** @var list<string> */
    private array $problems = [];

    private int $records = 0;

    private int $otherYears = 0;

    private int $quantities = 0;

    /** @var array<int, true> accounts outside 3000–8998 */
    private array $skippedAccounts = [];

    /** @var array<string, true> SIE dimensions other than 1 and 6 */
    private array $otherDimensions = [];

    private int $skippedObjectRecords = 0;

    private int $multipleObjects = 0;

    public function read(SieRecord $record): void
    {
        match ($record->label) {
            '#SIETYP' => $this->type = $record->field(0),
            '#RAR' => $this->year($record),
            '#PBUDGET' => $this->budget($record),
            default => null,
        };
    }

    /** Whether the file has any #PBUDGET record for the fiscal year (årsnr 0). */
    public function present(): bool
    {
        return $this->records > 0;
    }

    /** #PBUDGET records for the fiscal year, including those left out. */
    public function records(): int
    {
        return $this->records;
    }

    public function otherYears(): int
    {
        return $this->otherYears;
    }

    /** The file's #SIETYP, null when it has none. */
    public function type(): ?string
    {
        return $this->type;
    }

    /** The first day of the file's fiscal year (#RAR 0). */
    public function start(): ?CarbonImmutable
    {
        return $this->start;
    }

    /** The last day of the file's fiscal year (#RAR 0). */
    public function end(): ?CarbonImmutable
    {
        return $this->end;
    }

    /**
     * What makes the budget impossible to read in reliably.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = $this->problems;

        if ($this->entries !== [] && ($this->start === null || $this->end === null)) {
            $problems[] = __('Filen har budget men inget räkenskapsår (#RAR 0).');
        }

        if ($this->start !== null && $this->end !== null) {
            $months = $this->months();

            foreach ($this->entries as $entry) {
                if (! in_array($entry['period'], $months, true)) {
                    $problems[] = __('Rad :line: budgetmånaden :period ligger utanför räkenskapsåret.', ['line' => $entry['line'], 'period' => str_replace('-', '', $entry['period'])]);
                }
            }
        }

        return $this->limit($problems);
    }

    /**
     * What is left out or changed, for the report's "Bra att veta".
     *
     * @return list<string>
     */
    public function notes(): array
    {
        $notes = [];

        if ($this->otherYears > 0) {
            $notes[] = __(':count budgetposter gäller andra räkenskapsår än filens och läses inte in. Läs in budgeten för ett annat år med det årets fil.', ['count' => $this->otherYears]);
        }

        if ($this->skippedAccounts !== []) {
            $accounts = array_keys($this->skippedAccounts);
            sort($accounts);

            $notes[] = __('Budget på konto :accounts läses inte in. Budgeten här gäller resultatkonton (3000–8998).', ['accounts' => implode(', ', array_slice($accounts, 0, self::LISTED))]);
        }

        if ($this->otherDimensions !== []) {
            $notes[] = __('Budget per dimension :dimensions läses inte in. Här finns budget per kostnadsställe (1) och projekt (6); kontots hela belopp läses in ändå.', ['dimensions' => implode(', ', array_keys($this->otherDimensions))]);
        }

        if ($this->multipleObjects > 0) {
            $notes[] = __(':count budgetposter anger mer än ett objekt, vilket formatet inte har. De läses inte in; kontots hela belopp läses in ändå.', ['count' => $this->multipleObjects]);
        }

        if ($this->type === '2' && $this->skippedObjectRecords > 0) {
            $notes[] = __('Filen är av SIE-typ 2, som inte har budget per objekt. :count budgetposter med objekt läses därför inte in.', ['count' => $this->skippedObjectRecords]);
        }

        if ($this->quantities > 0) {
            $notes[] = __('Kvantiteter i budgeten läses inte in, bara belopp.');
        }

        $crossed = $this->crossed();

        if ($crossed > 0) {
            $notes[] = __('På :count konton och månader har budgeten både kostnadsställen och projekt. SIE anger dem var för sig, så budgeten läses in per kostnadsställe och per projekt, med en rad utan objekt som gör att kontots summa stämmer.', ['count' => $crossed]);
        }

        $built = $this->build();

        if ($built['rest'] !== []) {
            $first = $built['rest'][0];

            $notes[] = __(':count konton och månader får en rad utan kostnadsställe och projekt för det som inte ligger på något objekt, till exempel konto :account :month med :amount.', [
                'count' => count($built['rest']),
                'account' => $first['account'],
                'month' => $first['period'],
                'amount' => Money::ofOre($first['amount'])->format(),
            ]);
        }

        if ($built['guessed'] > 0) {
            $notes[] = __('På :count konton och månader saknar filen kontots hela belopp ({}) men har både kostnadsställen och projekt. Den största av de två summorna räknas som kontots belopp.', ['count' => $built['guessed']]);
        }

        return $notes;
    }

    /**
     * The budget as lines here, in a fixed order: account, month, the line
     * without objects, cost centres, projects. Zero amounts are left out.
     *
     * @return list<array{account: int, period: string, cost_center: ?string, project: ?string, amount: int}>
     */
    public function lines(): array
    {
        return $this->build()['lines'];
    }

    /**
     * The lines, and what the report should say about them: the lines
     * without objects on an account that also has objects, and the accounts
     * and months whose whole amount was not in the file.
     *
     * @return array{lines: list<array{account: int, period: string, cost_center: ?string, project: ?string, amount: int}>, rest: list<array{account: int, period: string, amount: int}>, guessed: int}
     */
    private function build(): array
    {
        $lines = [];
        $restLines = [];
        $guessed = 0;

        foreach ($this->grouped() as $group) {
            $costCenters = $group['objects'][Dimension::CostCenter->sieNumber()] ?? [];
            $projects = $group['objects'][Dimension::Project->sieNumber()] ?? [];
            $total = $group['total'];

            // A file that gives only objects: one dimension's objects split the account's whole amount (SIE 4C §8.12).
            // With both dimensions and no whole amount, the larger of the two sums is taken, and the report says so.
            if ($total === null) {
                $costCenterSum = array_sum($costCenters);
                $projectSum = array_sum($projects);
                $total = abs($costCenterSum) >= abs($projectSum) ? $costCenterSum : $projectSum;

                if ($costCenters !== [] && $projects !== []) {
                    $guessed++;
                }
            }

            $rest = $total - array_sum($costCenters) - array_sum($projects);

            if ($rest !== 0 && ($costCenters !== [] || $projects !== [])) {
                $restLines[] = ['account' => $group['account'], 'period' => $group['period'], 'amount' => $rest];
            }

            $lines[] = ['account' => $group['account'], 'period' => $group['period'], 'cost_center' => null, 'project' => null, 'amount' => $rest];

            foreach ($costCenters as $code => $amount) {
                $lines[] = ['account' => $group['account'], 'period' => $group['period'], 'cost_center' => (string) $code, 'project' => null, 'amount' => $amount];
            }

            foreach ($projects as $code => $amount) {
                $lines[] = ['account' => $group['account'], 'period' => $group['period'], 'cost_center' => null, 'project' => (string) $code, 'amount' => $amount];
            }
        }

        return [
            'lines' => array_values(array_filter($lines, fn (array $line): bool => $line['amount'] !== 0)),
            'rest' => $restLines,
            'guessed' => $guessed,
        ];
    }

    /**
     * The #PBUDGET records for budget lines, the inverse of lines(): per
     * account and month the whole amount ({}), then each cost centre and
     * each project with its sum. The whole amount is written whenever an
     * object is, also when it is zero, so a reader never has to guess it.
     *
     * @param  iterable<array{account: int, period: string, cost_center: ?string, project: ?string, amount: int}>  $lines
     * @return list<array{account: int, period: string, dimension: ?int, code: ?string, amount: int}>
     */
    public static function recordsFor(iterable $lines): array
    {
        $groups = [];

        foreach ($lines as $line) {
            $key = sprintf('%04d|%s', $line['account'], $line['period']);
            $groups[$key] ??= ['account' => $line['account'], 'period' => $line['period'], 'total' => 0, 'objects' => []];
            $groups[$key]['total'] += $line['amount'];

            foreach ([Dimension::CostCenter->sieNumber() => $line['cost_center'], Dimension::Project->sieNumber() => $line['project']] as $dimension => $code) {
                if ($code !== null) {
                    $groups[$key]['objects'][$dimension][$code] = ($groups[$key]['objects'][$dimension][$code] ?? 0) + $line['amount'];
                }
            }
        }

        ksort($groups);
        $records = [];

        foreach ($groups as $group) {
            $objects = [];

            foreach ([Dimension::CostCenter->sieNumber(), Dimension::Project->sieNumber()] as $dimension) {
                $codes = $group['objects'][$dimension] ?? [];
                ksort($codes, SORT_STRING);

                foreach ($codes as $code => $amount) {
                    if ($amount !== 0) {
                        $objects[] = ['account' => $group['account'], 'period' => $group['period'], 'dimension' => $dimension, 'code' => (string) $code, 'amount' => $amount];
                    }
                }
            }

            if ($group['total'] !== 0 || $objects !== []) {
                $records[] = ['account' => $group['account'], 'period' => $group['period'], 'dimension' => null, 'code' => null, 'amount' => $group['total']];
            }

            array_push($records, ...$objects);
        }

        return $records;
    }

    /**
     * Months of the file's fiscal year, as 'Y-m'.
     *
     * @return list<string>
     */
    private function months(): array
    {
        if ($this->start === null || $this->end === null) {
            return [];
        }

        $months = [];
        $month = $this->start->startOfMonth();

        while ($month->lessThanOrEqualTo($this->end)) {
            $months[] = $month->format('Y-m');
            $month = $month->addMonthNoOverflow();
        }

        return $months;
    }

    private function year(SieRecord $record): void
    {
        if ($record->field(0) !== '0') {
            return;
        }

        $this->start = SieDate::parse((string) $record->field(1));
        $this->end = SieDate::parse((string) $record->field(2));
    }

    private function budget(SieRecord $record): void
    {
        if ($record->field(0) !== '0') {
            $this->otherYears++;

            return;
        }

        $this->records++;

        // The object specification is required by the format, but a file without it is read the same way.
        $objects = $record->objects(3);
        $offset = $objects === null ? 3 : 4;
        $period = (string) $record->field(1);
        $account = (string) $record->field(2);
        $amount = SieAmount::toOre((string) $record->field($offset));

        if (preg_match('/^(\d{4})(0[1-9]|1[0-2])$/', $period, $match) !== 1) {
            $this->problems[] = __('Rad :line: budgetmånaden ":period" går inte att läsa. Den ska skrivas ÅÅÅÅMM.', ['line' => $record->line, 'period' => $period]);

            return;
        }

        if (preg_match('/^\d{4}$/', $account) !== 1) {
            $this->problems[] = __('Rad :line: budgetens konto ":account" har inte fyra siffror.', ['line' => $record->line, 'account' => $account]);

            return;
        }

        if ($amount === null || abs($amount) > BudgetMonths::MAX_CELL_ORE) {
            $this->problems[] = __('Rad :line: budgetbeloppet för konto :account går inte att läsa.', ['line' => $record->line, 'account' => $account]);

            return;
        }

        if (($record->field($offset + 1) ?? '') !== '') {
            $this->quantities++;
        }

        if (! BudgetMonths::isBudgetable((int) $account)) {
            $this->skippedAccounts[(int) $account] = true;

            return;
        }

        $raw = $record->fields[3] ?? [];
        [$dimension, $code] = $this->object(is_array($raw) ? $raw : []);

        if ($dimension === false) {
            return;
        }

        // The registers here hold codes of at most 20 characters (ADR-009); a longer one cannot be matched or created.
        if ($code !== null && mb_strlen($code) > 20) {
            $this->problems[] = __('Rad :line: objektkoden ":code" är längre än 20 tecken.', ['line' => $record->line, 'code' => $code]);

            return;
        }

        if ($dimension !== null && $this->type === '2') {
            $this->skippedObjectRecords++;

            return;
        }

        $month = "{$match[1]}-{$match[2]}";
        $key = "{$account}|{$month}|{$dimension}|{$code}";

        if (isset($this->entries[$key])) {
            $this->problems[] = __('Rad :line: budgeten för konto :account och månaden :period finns redan på rad :first.', [
                'line' => $record->line,
                'account' => $account,
                'period' => $period,
                'first' => $this->entries[$key]['line'],
            ]);

            return;
        }

        $this->entries[$key] = ['account' => (int) $account, 'period' => $month, 'dimension' => $dimension, 'code' => $code, 'amount' => $amount, 'line' => $record->line];
    }

    /**
     * The record's object: [null, null] for the whole account, [1|6, code]
     * for a cost centre or project, [false, null] when it is left out.
     *
     * The raw list is read, not SieRecord::objects(), which keys the pairs by
     * dimension and so would merge {1 "10" 1 "20"} into one object.
     *
     * @param  list<string>  $raw  the object list as dimension, object, dimension, object …
     * @return array{0: int|false|null, 1: ?string}
     */
    private function object(array $raw): array
    {
        $pairs = array_values(array_filter(array_chunk($raw, 2), fn (array $pair): bool => count($pair) === 2));

        if ($pairs === []) {
            return [null, null];
        }

        // More than one object (the format has one per balance record): only the account's whole amount is kept.
        if (count($pairs) > 1) {
            $this->multipleObjects++;

            return [false, null];
        }

        [$number, $code] = $pairs[0];

        // Another dimension: only the account's whole amount is kept.
        if (Dimension::fromSieNumber((int) $number) === null) {
            $this->otherDimensions[(string) $number] = true;

            return [false, null];
        }

        if ($code === '') {
            return [null, null];
        }

        return [(int) $number, (string) $code];
    }

    /**
     * @return array<string, array{account: int, period: string, total: ?int, objects: array<int, array<string, int>>}>
     */
    private function grouped(): array
    {
        $groups = [];

        foreach ($this->entries as $entry) {
            $key = sprintf('%04d|%s', $entry['account'], $entry['period']);
            $groups[$key] ??= ['account' => $entry['account'], 'period' => $entry['period'], 'total' => null, 'objects' => []];

            if ($entry['dimension'] === null) {
                $groups[$key]['total'] = $entry['amount'];

                continue;
            }

            $groups[$key]['objects'][$entry['dimension']][(string) $entry['code']] = $entry['amount'];
        }

        ksort($groups);

        foreach ($groups as &$group) {
            foreach ($group['objects'] as &$codes) {
                ksort($codes, SORT_STRING);
            }
        }

        return $groups;
    }

    /** Accounts and months with both cost centres and projects. */
    private function crossed(): int
    {
        return count(array_filter(
            $this->grouped(),
            fn (array $group): bool => isset($group['objects'][Dimension::CostCenter->sieNumber()], $group['objects'][Dimension::Project->sieNumber()]),
        ));
    }

    /**
     * @param  list<string>  $messages
     * @return list<string>
     */
    private function limit(array $messages): array
    {
        $more = count($messages) - self::LISTED;

        if ($more <= 0) {
            return $messages;
        }

        return [...array_slice($messages, 0, self::LISTED), __('… och :count problem till av samma slag.', ['count' => $more])];
    }

    /**
     * The cost centre codes the lines use.
     *
     * @return list<string>
     */
    public function costCenterCodes(): array
    {
        return array_values(array_unique(array_filter(array_column($this->lines(), 'cost_center'), fn (?string $code): bool => $code !== null)));
    }

    /**
     * The project codes the lines use.
     *
     * @return list<string>
     */
    public function projectCodes(): array
    {
        return array_values(array_unique(array_filter(array_column($this->lines(), 'project'), fn (?string $code): bool => $code !== null)));
    }
}
