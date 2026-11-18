<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Budget\ManageBudgetVersion;
use App\Actions\Budget\SaveBudgetLines;
use App\Domain\Budget\BudgetActuals;
use App\Domain\Budget\BudgetCsv;
use App\Domain\Budget\BudgetMonths;
use App\Domain\Budget\BudgetScope;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\BudgetVersion;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The budget grid of one version: result accounts (optionally per cost
 * centre and project) × the months of the fiscal year, keyboard-first like
 * the voucher editor. Amounts are typed in kronor, revenue and costs both as
 * positive amounts (BudgetMonths::shown). Nothing is stored until "Spara".
 * Five queries per render, whatever the size of the grid.
 */
class BudgetEditor extends Component
{
    use ShowsToasts;
    use WithFileUploads;

    /** The longest block that may be pasted, in characters. */
    public const int MAX_PASTE_LENGTH = 500_000;

    #[Locked]
    public int $versionId;

    /** @var list<string> */
    #[Locked]
    public array $months = [];

    /** @var list<array{key: string, account_id: int, cost_center_id: ?int, project_id: ?int, revenue?: bool}> */
    #[Locked]
    public array $rows = [];

    /** @var array<string, array<int, mixed>> kronor as typed, per row key and month index; mixed, since the browser sends it */
    public array $cells = [];

    public string $newAccount = '';

    public string $newCostCenter = '';

    public string $newProject = '';

    public string $percent = '';

    #[Validate(['string', 'max:500000'])]
    public string $paste = '';

    #[Validate(['nullable', 'file', 'mimes:csv,txt', 'max:2048'])]
    public ?TemporaryUploadedFile $file = null;

    /** @var list<string> */
    public array $importErrors = [];

    public function mount(BudgetVersion $version): void
    {
        abort_unless($this->user()->can('view', $version), 403);

        $version->load('fiscalYear');
        $this->versionId = $version->id;
        $this->months = BudgetMonths::of($version->fiscalYear);
        $this->load();
    }

    public function addRow(): void
    {
        $this->authorizeChange();
        $this->resetErrorBag();

        $account = Account::query()->where('number', (int) $this->newAccount)->first();

        if (! $account instanceof Account || ! BudgetMonths::isBudgetable($account->number)) {
            $this->addError('newAccount', __('Välj ett resultatkonto (3000–8998).'));

            return;
        }

        $costCenter = $this->newCostCenter === '' ? null : CostCenter::query()->where('code', $this->newCostCenter)->value('id');
        $project = $this->newProject === '' ? null : Project::query()->where('code', $this->newProject)->value('id');
        $key = self::key($account->id, $costCenter === null ? null : (int) $costCenter, $project === null ? null : (int) $project);

        if (isset($this->cells[$key])) {
            $this->addError('newAccount', __('Raden finns redan i budgeten.'));

            return;
        }

        $this->rows[] = ['key' => $key, 'account_id' => $account->id, 'cost_center_id' => $costCenter === null ? null : (int) $costCenter, 'project_id' => $project === null ? null : (int) $project];
        $this->cells[$key] = array_fill(0, count($this->months), '');
        $this->sortRows();
        $this->reset('newAccount', 'newCostCenter', 'newProject');

        $this->toast(__('Raden för konto :account är tillagd. Spara när du har fyllt i den.', ['account' => $account->number]));
    }

    public function removeRow(string $key): void
    {
        $this->authorizeChange();

        $this->rows = array_values(array_filter($this->rows, fn (array $row): bool => $row['key'] !== $key));
        unset($this->cells[$key]);

        $this->toast(__('Raden är borttagen. Spara för att ta bort den ur budgeten.'));
    }

    /** "Fördela jämnt": the year's total typed in the sum column, spread over the months in whole kronor. */
    public function spread(string $key, string $total): void
    {
        $this->authorizeChange();

        if (! isset($this->cells[$key])) {
            return;
        }

        $ore = trim($total) === '' ? 0 : BudgetMonths::parseCell($total);

        if ($ore === null) {
            $this->addError("cells.{$key}", __('Skriv årets belopp i kronor, till exempel 120 000.'));

            return;
        }

        $this->resetErrorBag("cells.{$key}");
        $this->cells[$key] = array_map(self::kronor(...), BudgetMonths::spreadEvenly($ore, count($this->months)));
    }

    /** "Som i fjol" (+ x %): last year's outcome of the row, month by month, rounded to whole kronor. */
    public function lastYear(?string $key = null): void
    {
        $this->authorizeChange();
        $this->resetErrorBag('percent');

        $percent = BudgetMonths::percent($this->percent);

        if ($percent === null || $percent < -10000) {
            $this->addError('percent', __('Skriv förändringen i procent, till exempel 5 eller -2,5.'));

            return;
        }

        $version = $this->version();
        $previous = BudgetActuals::previousYear($version->fiscalYear);

        if ($previous === null) {
            $this->toast(__('Det finns inget räkenskapsår före det här att hämta utfall från.'), 'error');

            return;
        }

        if ($this->rows === []) {
            $this->toast(__('Lägg till rader först.'), 'error');

            return;
        }

        $actuals = BudgetActuals::perMonth($previous, perDimension: true);
        $numbers = Account::query()->whereIn('id', array_column($this->rows, 'account_id'))->pluck('number', 'id');
        $changed = 0;

        foreach ($this->rows as $row) {
            if ($key !== null && $row['key'] !== $key) {
                continue;
            }

            $values = BudgetActuals::forRow($actuals, $row['account_id'], $row['cost_center_id'], $row['project_id'], $this->months);
            $this->cells[$row['key']] = array_map(
                fn (int $ore): string => self::kronor(BudgetMonths::shown((int) $numbers[$row['account_id']], BudgetMonths::adjusted($ore, $percent))),
                array_values($values),
            );
            $changed++;
        }

        $this->toast(trans_choice('{1} En rad har fått fjolårets utfall. Spara för att behålla den.|[2,*] :count rader har fått fjolårets utfall. Spara för att behålla dem.', $changed, ['count' => $changed]));
    }

    /** A block pasted from a spreadsheet into a cell fills the grid from that cell, right and down. */
    public function pasteBlock(int $rowIndex, int $monthIndex, string $text): void
    {
        $this->authorizeChange();

        if (mb_strlen($text) > self::MAX_PASTE_LENGTH) {
            $this->toast(__('Det inklistrade är för stort. Läs in det som en CSV-fil i stället.'), 'error');

            return;
        }

        $lines = array_slice(preg_split('/\r\n|\r|\n/', rtrim($text, "\r\n")) ?: [], 0, BudgetMonths::MAX_IMPORT_ROWS);

        foreach ($lines as $offset => $line) {
            $row = $this->rows[$rowIndex + $offset] ?? null;

            if ($row === null) {
                break;
            }

            foreach (explode("\t", $line) as $column => $value) {
                if (! isset($this->months[$monthIndex + $column])) {
                    break;
                }

                $this->cells[$row['key']][$monthIndex + $column] = trim($value);
            }
        }
    }

    /** Reads a CSV file or a block with account numbers into the grid (not saved until "Spara"). */
    public function import(): void
    {
        $this->authorizeChange();
        $this->validateOnly('file');

        $text = $this->file !== null ? (string) file_get_contents($this->file->getRealPath()) : $this->paste;

        if (mb_strlen($text) > self::MAX_PASTE_LENGTH) {
            $this->importErrors = [__('Det inklistrade är för stort. Läs in det som en CSV-fil i stället.')];

            return;
        }

        if (trim($text) === '') {
            $this->importErrors = [__('Välj en fil eller klistra in rader först.')];

            return;
        }

        $read = BudgetCsv::read($text, $this->months);
        $this->importErrors = $read['errors'];

        if ($read['errors'] === [] && $read['rows'] === []) {
            $this->importErrors = [__('Filen har inga rader med konton.')];
        }

        if ($this->importErrors !== []) {
            return;
        }

        $numbers = Account::query()->whereIn('id', array_column($read['rows'], 'account_id'))->pluck('number', 'id');

        foreach ($read['rows'] as $imported) {
            $key = self::key($imported['account_id'], $imported['cost_center_id'], $imported['project_id']);

            if (! isset($this->cells[$key])) {
                $this->rows[] = ['key' => $key, 'account_id' => $imported['account_id'], 'cost_center_id' => $imported['cost_center_id'], 'project_id' => $imported['project_id']];
            }

            $this->cells[$key] = array_map(
                fn (string $month): string => isset($imported['amounts'][$month]) ? self::kronor(BudgetMonths::shown((int) $numbers[$imported['account_id']], $imported['amounts'][$month])) : ($this->cells[$key][array_search($month, $this->months, true)] ?? ''),
                $this->months,
            );
        }

        $this->sortRows();
        $this->reset('file', 'paste');
        $count = count($read['rows']);

        $this->toast(trans_choice('{1} En rad är inläst. Spara för att behålla den.|[2,*] :count rader är inlästa. Spara för att behålla dem.', $count, ['count' => $count]));
    }

    public function save(SaveBudgetLines $save): void
    {
        $this->authorizeChange();
        $this->resetErrorBag();

        $numbers = Account::query()->whereIn('id', array_column($this->rows, 'account_id'))->pluck('number', 'id');
        $rows = [];
        $invalid = false;

        foreach ($this->rows as $row) {
            $amounts = [];

            foreach ($this->months as $index => $month) {
                $raw = $this->cells[$row['key']][$index] ?? '';
                $typed = is_string($raw) ? trim($raw) : null;

                if ($typed === '') {
                    continue;
                }

                $ore = $typed === null ? null : BudgetMonths::parseCell($typed);

                if ($ore === null) {
                    $this->addError("cells.{$row['key']}.{$index}", __('Inget giltigt belopp'));
                    $invalid = true;

                    continue;
                }

                $amounts[$month] = BudgetMonths::shown((int) ($numbers[$row['account_id']] ?? 0), $ore);
            }

            $rows[] = ['account_id' => $row['account_id'], 'cost_center_id' => $row['cost_center_id'], 'project_id' => $row['project_id'], 'amounts' => $amounts];
        }

        if ($invalid) {
            $this->toast(__('Några rutor innehåller inget giltigt belopp (högst 100 miljarder kr per ruta). Rätta de markerade rutorna och spara igen.'), 'error');

            return;
        }

        $save($this->user(), $this->version(), $rows);

        $this->dispatch('budget-saved');
        $this->toast(__('Budgeten är sparad.'));
    }

    public function lock(ManageBudgetVersion $manage): void
    {
        $version = $manage->lock($this->user(), $this->version());

        $this->toast(__(':name är låst. Kopiera den om du vill ändra något.', ['name' => $version->name]));
    }

    public function activate(ManageBudgetVersion $manage): void
    {
        $version = $manage->activate($this->user(), $this->version());

        $this->toast(__(':name är nu den budget som rapporterna jämför med.', ['name' => $version->name]));
    }

    public function render(): View
    {
        $version = $this->version();

        return view('livewire.accounting.budget-editor', [
            'version' => $version,
            'accounts' => Account::query()->whereBetween('number', [BudgetMonths::FIRST_ACCOUNT, BudgetMonths::LAST_ACCOUNT])->orderBy('number')->get(['id', 'number', 'name', 'is_active'])->keyBy('id'),
            'costCenters' => CostCenter::query()->orderBy('code')->get(['id', 'code', 'name', 'is_active'])->keyBy('id'),
            'projects' => Project::query()->orderBy('code')->get(['id', 'code', 'name', 'is_active'])->keyBy('id'),
            'canChange' => $this->user()->can('update', $version),
            'canManage' => $this->user()->can('manage', $version),
        ]);
    }

    private function load(): void
    {
        $this->rows = [];
        $this->cells = [];
        $index = array_flip($this->months);

        $lines = BudgetScope::lines($this->versionId)
            ->join('accounts', 'accounts.id', '=', 'budget_lines.account_id')
            ->select('budget_lines.account_id', 'budget_lines.cost_center_id', 'budget_lines.project_id', 'budget_lines.period', 'budget_lines.amount_ore', 'accounts.number')
            ->toBase()
            ->get();

        foreach ($lines as $line) {
            $costCenter = $line->cost_center_id === null ? null : (int) $line->cost_center_id;
            $project = $line->project_id === null ? null : (int) $line->project_id;
            $key = self::key((int) $line->account_id, $costCenter, $project);

            if (! isset($this->cells[$key])) {
                $this->rows[] = ['key' => $key, 'account_id' => (int) $line->account_id, 'cost_center_id' => $costCenter, 'project_id' => $project];
                $this->cells[$key] = array_fill(0, count($this->months), '');
            }

            $month = substr((string) $line->period, 0, 7);

            if (isset($index[$month])) {
                $this->cells[$key][$index[$month]] = self::kronor(BudgetMonths::shown((int) $line->number, (int) $line->amount_ore));
            }
        }

        $this->sortRows();
    }

    /** Rows in account order, then cost centre and project; uses the numbers of the loaded accounts. */
    private function sortRows(): void
    {
        $numbers = Account::query()->whereIn('id', array_column($this->rows, 'account_id'))->pluck('number', 'id');

        usort($this->rows, fn (array $a, array $b): int => [(int) ($numbers[$a['account_id']] ?? 0), (int) $a['cost_center_id'], (int) $a['project_id']]
            <=> [(int) ($numbers[$b['account_id']] ?? 0), (int) $b['cost_center_id'], (int) $b['project_id']]);

        // The grid's result row adds revenue (BudgetMonths::isRevenue) and subtracts everything else.
        $this->rows = array_map(fn (array $row): array => [...$row, 'revenue' => BudgetMonths::isRevenue((int) ($numbers[$row['account_id']] ?? 0))], $this->rows);
    }

    private function authorizeChange(): void
    {
        abort_unless($this->user()->can('update', $this->version()), 403);
    }

    private function version(): BudgetVersion
    {
        return BudgetVersion::query()->with('fiscalYear')->findOrFail($this->versionId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private static function key(int $accountId, ?int $costCenterId, ?int $projectId): string
    {
        return "{$accountId}-".($costCenterId ?? 0).'-'.($projectId ?? 0);
    }

    /** Kronor as typed in the grid: no thousands separator, decimal comma, empty for zero. */
    private static function kronor(int $ore): string
    {
        if ($ore === 0) {
            return '';
        }

        $sign = $ore < 0 ? '-' : '';
        $absolute = abs($ore);

        return $absolute % 100 === 0
            ? $sign.intdiv($absolute, 100)
            : $sign.intdiv($absolute, 100).','.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
