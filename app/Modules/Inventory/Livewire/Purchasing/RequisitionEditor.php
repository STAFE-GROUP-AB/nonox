<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire\Purchasing;

use App\Domain\Accounting\AccountType;
use App\Domain\Units\ArticleUnits;
use App\Domain\Units\UnitDefaults;
use App\Domain\Units\UnitRegister;
use App\Models\Account;
use App\Models\Article;
use App\Models\Supplier;
use App\Models\Unit;
use App\Modules\Inventory\Actions\Purchasing\SavePurchaseRequisition;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\PurchaseRequisition;
use App\Modules\Inventory\Models\PurchaseRequisitionRow;
use App\Modules\Inventory\Purchasing\PurchaseQuantities;
use App\Support\Money\Money;
use App\Support\Toast;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Lager → Inköpsanmodan → Ny or Ändra: what is needed, when and why, and the
 * rows (ADR-081 E9). A row is an article, or free text; the account is needed
 * for what is not a stock article, and a cost centre, project and supplier are
 * optional. Saving a requisition that was rejected takes it back to a draft.
 */
class RequisitionEditor extends Component
{
    use InventoryPage;

    public const string NAME = 'inventory.requisition-editor';

    #[Locked]
    public ?int $requisitionId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(?int $requisitionId = null): void
    {
        $gate = Gate::forUser($this->user());

        if ($requisitionId === null) {
            abort_unless($gate->allows('create', [PurchaseRequisition::class, $this->company()]), 403);
            $this->form = ['needed_by' => '', 'reason' => '', 'rows' => [self::blankRow()]];

            return;
        }

        $requisition = PurchaseRequisition::query()->with(['rows.account:id,number', 'rows.costCenter:id,code', 'rows.project:id,code'])->findOrFail($requisitionId);
        abort_unless($gate->allows('handle', [$requisition, $this->company()]), 403);
        abort_unless($requisition->status->isEditable(), 404);

        $this->requisitionId = $requisition->id;
        $this->form = [
            'needed_by' => (string) $requisition->needed_by?->toDateString(),
            'reason' => (string) $requisition->reason,
            'rows' => $requisition->rows->map(fn (PurchaseRequisitionRow $row): array => [
                'article_id' => (string) $row->article_id,
                'description' => $row->article_id === null ? $row->description : '',
                'quantity' => str_replace('.', ',', Quantity::decimal($row->quantity_milli)),
                'unit_id' => (string) $row->unit_id,
                'price' => $row->estimated_price_ore === null ? '' : Money::ofOre($row->estimated_price_ore)->format(withCurrency: false),
                'account' => (string) $row->account?->number,
                'cost_center' => (string) $row->costCenter?->code,
                'project' => (string) $row->project?->code,
                'supplier_id' => (string) $row->supplier_id,
            ])->values()->all(),
        ];
    }

    public function updated(string $property, mixed $value): void
    {
        if (preg_match('/^form\.rows\.(\d+)\.article_id$/', $property, $match) !== 1) {
            return;
        }

        $article = Article::query()->find((int) $value);
        $register = UnitRegister::load();
        $this->form['rows'][(int) $match[1]]['unit_id'] = (string) ($article === null ? $register->default()->id : ($article->purchase_unit_id ?? $article->stock_unit_id ?? $register->default()->id));
        $this->form['rows'][(int) $match[1]]['price'] = '';
    }

    public function addRow(): void
    {
        $this->form['rows'][] = self::blankRow();
    }

    public function removeRow(int $index): void
    {
        unset($this->form['rows'][$index]);
        $this->form['rows'] = array_values($this->form['rows']);
    }

    public function save(SavePurchaseRequisition $save): void
    {
        $requisition = $this->requisitionId === null ? null : PurchaseRequisition::query()->findOrFail($this->requisitionId);
        $saved = null;

        $ok = $this->attempt(function () use ($save, $requisition, &$saved): void {
            $saved = $save($this->user(), $this->company(), [
                'needed_by' => ($this->form['needed_by'] ?? '') ?: null,
                'reason' => $this->form['reason'] ?? '',
                'rows' => array_map(fn (array $row): array => [
                    'article_id' => ($row['article_id'] ?? '') ?: null,
                    'description' => $row['description'] ?? '',
                    'quantity' => $row['quantity'] ?? '',
                    'unit_id' => ($row['unit_id'] ?? '') ?: null,
                    'price' => $row['price'] ?? '',
                    'account' => $row['account'] ?? '',
                    'cost_center' => $row['cost_center'] ?? '',
                    'project' => $row['project'] ?? '',
                    'supplier_id' => ($row['supplier_id'] ?? '') ?: null,
                ], array_values((array) $this->form['rows'])),
            ], $requisition);
        }, '');

        if (! $ok || ! $saved instanceof PurchaseRequisition) {
            return;
        }

        Toast::flash($requisition === null ? __('Inköpsanmodan :number är sparad som utkast.', ['number' => $saved->number]) : __('Inköpsanmodan är sparad som utkast. Skicka den till attest när den är klar.'));
        $this->redirectRoute('inventory.requisitions.show', $saved->id);
    }

    public function render(): View
    {
        $chosenIds = array_values(array_filter(array_map(fn (array $row): int => (int) ($row['article_id'] ?? 0), (array) $this->form['rows'])));
        $articles = Article::query()->with(['stockUnit:id,code'])
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $chosenIds))
            ->orderBy('number')
            ->get(['id', 'number', 'name', 'unit', 'is_stock_item', 'stock_unit_id', 'purchase_unit_id']);
        $units = Unit::query()->orderBy('code')->get(['id', 'code', 'name', 'dimension', 'archived_at']);
        $chosen = $articles->whereIn('id', $chosenIds)->keyBy('id');
        $conversions = PurchaseQuantities::conversions($chosen->keys()->all());
        $available = $units->filter(fn (Unit $unit): bool => ! $unit->isArchived())->values();
        $byCode = [];

        foreach ($units as $unit) {
            $byCode[UnitDefaults::codeFor($unit->code) ?? UnitDefaults::normalise($unit->code)] = $unit;
        }

        $choices = [];

        foreach ((array) $this->form['rows'] as $index => $row) {
            $article = $chosen->get((int) ($row['article_id'] ?? 0));

            if ($article === null || ! $article->is_stock_item) {
                $choices[$index] = $available;

                continue;
            }

            $choices[$index] = collect(ArticleUnits::choices(ArticleUnits::stockCode($article), $conversions[$article->id] ?? []))
                ->map(fn (string $code): ?Unit => $byCode[$code] ?? null)
                ->filter(fn (?Unit $unit): bool => $unit !== null && (! $unit->isArchived() || (string) $unit->id === (string) ($row['unit_id'] ?? '')))
                ->values();
        }

        return view('inventory::livewire.purchasing.requisition-editor', [
            'articles' => $articles,
            'unitChoices' => $choices,
            'suppliers' => Supplier::query()->where(fn ($query) => $query->whereNull('processing_restricted_at')->orWhereIn('id', array_values(array_filter(array_map(fn (array $row): int => (int) ($row['supplier_id'] ?? 0), (array) $this->form['rows'])))))->orderBy('name')->get(['id', 'name']),
            'accounts' => Account::query()->where('is_active', true)->whereIn('type', [AccountType::Asset->value, AccountType::Expense->value])->orderBy('number')->get(['id', 'number', 'name']),
            'existing' => $this->requisitionId === null ? null : PurchaseRequisition::query()->find($this->requisitionId, ['id', 'number', 'status']),
        ]);
    }

    /** @return array<string, mixed> */
    private static function blankRow(): array
    {
        return ['article_id' => '', 'description' => '', 'quantity' => '', 'unit_id' => (string) UnitRegister::load()->default()->id, 'price' => '', 'account' => '', 'cost_center' => '', 'project' => '', 'supplier_id' => ''];
    }
}
