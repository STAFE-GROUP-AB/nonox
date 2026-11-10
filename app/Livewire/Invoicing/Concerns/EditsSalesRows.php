<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing\Concerns;

use App\Domain\Units\UnitRegister;
use App\Domain\Vat\SalesVatRules;
use App\Models\Account;
use App\Models\Article;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The rows of an invoice, quote or order being written. Adding rows,
 * filling them from articles and the running totals happen in the browser
 * (resources/js/sales-rows.js); the rows reach the server when the document
 * is saved, and the server calculates everything again. A row's unit is
 * chosen from the company's unit register (ADR-071 §12.9); an archived
 * unit is offered only on the rows that already had it.
 *
 * @mixin Component
 */
trait EditsSalesRows
{
    /** @var array<int, array<string, string>> */
    public array $rows = [];

    /** @var list<int> the units the document's rows had when it was opened: still offered, even when archived */
    #[Locked]
    public array $keptUnitIds = [];

    /**
     * The active articles for the rows, with their revenue account, so the
     * browser can warn when a row's VAT rate does not fit it (#62).
     *
     * @return Collection<int, Article>
     */
    protected function rowArticles(): Collection
    {
        return Article::query()->where('is_active', true)->with('account:id,number')->orderBy('number')->get(['number', 'name', 'unit', 'stock_unit_id', 'sales_unit_id', 'price_ore', 'vat_rate', 'account_id']);
    }

    /**
     * The units the rows offer, the one a new row starts with, and whether
     * the person may open the unit register.
     *
     * @return array{units: Collection<int, Unit>, defaultUnitId: int, canManageUnits: bool}
     */
    protected function rowUnitData(): array
    {
        $register = UnitRegister::load();
        $user = Auth::user();
        $company = $user instanceof User ? $user->currentCompany() : null;

        return [
            'units' => $register->choices($this->keptUnitIds),
            'defaultUnitId' => $register->default()->id,
            'canManageUnits' => $user instanceof User && $company !== null && $user->can('create', [Article::class, $company]),
        ];
    }

    /**
     * The revenue accounts' VAT rules for the browser (SalesVatRules).
     *
     * @return array<int, array{rates: list<int>, vat: ?list<int>}>
     */
    protected function salesVatRules(): array
    {
        return SalesVatRules::forAccounts(Account::query()->whereBetween('number', [3000, 3999])->get(['id', 'number', 'vat_box']));
    }

    /**
     * The filled rows as input for the save action.
     *
     * @return list<array<string, mixed>>
     */
    protected function rowInput(): array
    {
        $filled = $this->filledRows();
        UnitRegister::load()->assertSelectable(
            collect($filled)->mapWithKeys(fn (array $row, int $index): array => ["rows.{$index}.unit_id" => $row['unit_id'] ?? null])->all(),
            $this->keptUnitIds,
        );

        return array_map(fn (array $row): array => [
            ...$row,
            'vat_rate' => (int) ($row['vat_rate'] ?? 2500),
            'account' => ($row['account'] ?? '') === '' ? null : (int) $row['account'],
        ], $filled);
    }

    /**
     * @param  iterable<object>  $rows  invoice or quote/order rows with their account
     * @return array<int, array<string, string>>
     */
    protected function rowsFrom(iterable $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $input = method_exists($row, 'asInput') ? $row->asInput() : [];
            $result[] = array_map(fn (mixed $value): string => is_bool($value) ? ($value ? '1' : '') : (string) $value, ['article' => '', ...$input]);

            if (($input['unit_id'] ?? '') !== '') {
                $this->keptUnitIds[] = (int) $input['unit_id'];
            }
        }

        $this->keptUnitIds = array_values(array_unique($this->keptUnitIds));

        return $result;
    }

    /**
     * @return list<array<string, string>>
     */
    private function filledRows(): array
    {
        return array_values(array_filter($this->rows, fn (array $row): bool => trim((string) ($row['description'] ?? '')) !== '' || trim((string) ($row['price'] ?? '')) !== ''));
    }

    /** @return array<string, string> */
    private function blankRow(): array
    {
        return ['article' => '', 'description' => '', 'quantity' => '1', 'unit_id' => (string) UnitRegister::load()->default()->id, 'price' => '', 'discount' => '', 'vat_rate' => '2500', 'account' => ''];
    }
}
