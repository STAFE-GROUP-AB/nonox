<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire\Purchasing;

use App\Domain\Units\ArticleUnits;
use App\Domain\Units\QuantityOutOfRange;
use App\Domain\Units\QuantityRoundsToZero;
use App\Domain\Units\UnitConverter;
use App\Domain\Units\UnitDefaults;
use App\Models\Article;
use App\Models\Supplier;
use App\Models\Unit;
use App\Modules\Inventory\Actions\Purchasing\SavePurchaseOrder;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Livewire\Concerns\PurchasingPage;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Modules\Inventory\Purchasing\PurchaseQuantities;
use App\Support\Money\Money;
use App\Support\Toast;
use Brick\Math\BigRational;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Lager → Inköpsorder → Ny or Ändra: the order's supplier, dates, delivery
 * address and rows. A row is typed in any unit the article converts to
 * (ADR-071 §12.2); the page shows what that is in the stock unit, and the
 * price is per the row's unit (the purchase price converted when left
 * empty).
 */
class PurchaseOrderEditor extends Component
{
    use PurchasingPage;

    public const string NAME = 'inventory.purchase-order-editor';

    #[Locked]
    public ?int $orderId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(?int $orderId = null): void
    {
        abort_unless($this->canPurchase('manage'), 403);

        if ($orderId !== null) {
            $order = PurchaseOrder::query()->with('rows')->findOrFail($orderId);
            abort_unless($order->isDraft(), 404);
            $this->orderId = $order->id;
            $this->form = [
                'supplier_id' => (string) $order->supplier_id,
                'order_date' => $order->order_date->toDateString(),
                'requested_delivery_on' => (string) $order->requested_delivery_on?->toDateString(),
                'our_reference' => (string) $order->our_reference,
                'delivery_address' => (string) $order->delivery_address,
                'notes' => (string) $order->notes,
                'rows' => $order->rows->map(fn (PurchaseOrderRow $row): array => [
                    'id' => $row->id,
                    'article_id' => (string) $row->article_id,
                    'quantity' => str_replace('.', ',', Quantity::decimal(Quantity::parse($row->entered_quantity))),
                    'unit_id' => (string) $row->unit_id,
                    'price' => Money::ofOre($row->price_ore)->format(withCurrency: false),
                    'expected_on' => (string) $row->expected_on?->toDateString(),
                    'supplier_article_number' => (string) $row->supplier_article_number,
                    'received' => $row->received_milli > 0,
                ])->values()->all(),
            ];

            return;
        }

        $supplier = request()->query('leverantor');

        $this->form = [
            'supplier_id' => is_string($supplier) && ctype_digit($supplier) ? $supplier : '',
            'order_date' => CarbonImmutable::today()->toDateString(),
            'requested_delivery_on' => '',
            'our_reference' => $this->user()->name,
            'delivery_address' => (string) SavePurchaseOrder::defaultDeliveryAddress($this->company()),
            'notes' => '',
            'rows' => [self::blankRow()],
        ];
    }

    public function updated(string $property, mixed $value): void
    {
        if (preg_match('/^form\.rows\.(\d+)\.article_id$/', $property, $match) !== 1) {
            return;
        }

        $article = Article::query()->find((int) $value);
        $this->form['rows'][(int) $match[1]]['unit_id'] = $article === null ? '' : (string) ($article->purchase_unit_id ?? $article->stock_unit_id);
        $this->form['rows'][(int) $match[1]]['price'] = '';
        $this->form['rows'][(int) $match[1]]['supplier_article_number'] = '';
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

    public function save(SavePurchaseOrder $save): void
    {
        $order = $this->orderId === null ? null : PurchaseOrder::query()->findOrFail($this->orderId);
        $saved = null;

        $ok = $this->attempt(function () use ($save, $order, &$saved): void {
            $saved = $save($this->user(), $this->company(), [
                ...$this->form,
                'supplier_id' => ($this->form['supplier_id'] ?? '') === '' ? null : (int) $this->form['supplier_id'],
                'order_date' => ($this->form['order_date'] ?? '') ?: null,
                'requested_delivery_on' => ($this->form['requested_delivery_on'] ?? '') ?: null,
                'rows' => array_map(fn (array $row): array => [
                    'id' => ($row['id'] ?? null) ?: null,
                    'article_id' => $row['article_id'],
                    'quantity' => $row['quantity'],
                    'unit_id' => ($row['unit_id'] ?? '') ?: null,
                    'price' => $row['price'] ?? '',
                    'expected_on' => ($row['expected_on'] ?? '') ?: null,
                    'supplier_article_number' => $row['supplier_article_number'] ?? '',
                ], array_values((array) $this->form['rows'])),
            ], $order);
        }, '');

        if (! $ok || ! $saved instanceof PurchaseOrder) {
            return;
        }

        foreach ($save->warnings as $warning) {
            Toast::flash($warning, 'warning');
        }

        Toast::flash($order === null ? __('Inköpsorder :number är skapad som utkast.', ['number' => $saved->number]) : __('Inköpsordern är sparad.'));
        $this->redirectRoute('inventory.purchaseOrders.show', $saved->id);
    }

    public function render(): View
    {
        $articles = Article::query()->with(['stockUnit:id,code', 'purchaseUnit:id,code'])
            ->where('is_stock_item', true)
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', array_map(fn (array $row): int => (int) $row['article_id'], (array) $this->form['rows'])))
            ->orderBy('number')
            ->get(['id', 'number', 'name', 'unit', 'stock_unit_id', 'purchase_unit_id', 'purchase_price_ore', 'default_supplier_id']);
        $units = Unit::query()->orderBy('code')->get(['id', 'code', 'name', 'dimension', 'archived_at']);
        $chosen = $articles->whereIn('id', array_map(fn (array $row): int => (int) $row['article_id'], (array) $this->form['rows']));
        $conversions = PurchaseQuantities::conversions($chosen->modelKeys());

        return view('inventory::livewire.purchasing.editor', [
            'suppliers' => Supplier::query()->where(fn ($query) => $query->whereNull('processing_restricted_at')->orWhere('id', (int) ($this->form['supplier_id'] ?? 0)))->orderBy('name')->get(['id', 'name']),
            'articles' => $articles,
            'rowHelp' => $this->rowHelp($chosen->keyBy('id')->all(), $units->keyBy('id')->all(), $conversions),
            'existing' => $this->orderId === null ? null : PurchaseOrder::query()->find($this->orderId, ['id', 'number', 'version', 'sent_at']),
        ]);
    }

    /**
     * Per row: the units it may be typed in, what the typed quantity is in
     * the stock unit, and the price the row gets when none is typed.
     *
     * @param  array<int, Article>  $articles
     * @param  array<int, Unit>  $units
     * @param  array<int, array<string, BigRational>>  $conversions
     * @return array<int, array{units: list<Unit>, stock: string, price: string}>
     */
    private function rowHelp(array $articles, array $units, array $conversions): array
    {
        $byCode = [];

        foreach ($units as $unit) {
            $byCode[UnitDefaults::codeFor($unit->code) ?? UnitDefaults::normalise($unit->code)] = $unit;
        }

        $help = [];

        foreach ((array) $this->form['rows'] as $index => $row) {
            $article = $articles[(int) $row['article_id']] ?? null;

            if ($article === null) {
                $help[$index] = ['units' => [], 'stock' => '', 'price' => ''];

                continue;
            }

            $stockCode = ArticleUnits::stockCode($article);
            $own = $conversions[$article->id] ?? [];
            $choices = [];

            foreach (ArticleUnits::choices($stockCode, $own) as $code) {
                $unit = $byCode[$code] ?? null;

                if ($unit !== null && (! $unit->isArchived() || (string) $unit->id === (string) ($row['unit_id'] ?? ''))) {
                    $choices[] = $unit;
                }
            }

            $unit = $units[(int) ($row['unit_id'] ?? 0)] ?? null;
            $perStock = $unit === null ? null : PurchaseQuantities::perStock($article, $unit, $own);
            $stock = '';

            if ($perStock !== null && ! $perStock->isEqualTo(1)) {
                try {
                    $conversion = UnitConverter::toStock(Quantity::decimal(Quantity::parse((string) $row['quantity'])), $perStock);
                    $stock = __('≈ :quantity :unit', ['quantity' => Quantity::format($conversion->stockMilli), 'unit' => $stockCode]);
                } catch (InvalidArgumentException|QuantityRoundsToZero|QuantityOutOfRange) {
                    $stock = '';
                }
            }

            $help[$index] = [
                'units' => $choices,
                'stock' => $stock,
                'price' => $perStock === null ? '' : Money::ofOre(PurchaseQuantities::pricePerEntered($article->purchase_price_ore ?? 0, $perStock))->format(withCurrency: false),
            ];
        }

        return $help;
    }

    /** @return array<string, mixed> */
    private static function blankRow(): array
    {
        return ['id' => null, 'article_id' => '', 'quantity' => '', 'unit_id' => '', 'price' => '', 'expected_on' => '', 'supplier_article_number' => '', 'received' => false];
    }
}
