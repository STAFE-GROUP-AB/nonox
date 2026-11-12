<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Production;

use App\Domain\Invoicing\SalesDocumentStatus;
use App\Domain\Units\ArticleUnits;
use App\Models\Article;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Models\SalesDocument;
use App\Models\SalesDocumentRow;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\StructureKind;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\BomRow;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Structures\RowQuantity;
use App\Modules\Manufacturing\Actions\Concerns\GuardsManufacturing;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderComponent;
use App\Modules\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Manufacturing\Models\RowStructure;
use App\Modules\Manufacturing\Models\RowStructureLine;
use App\Modules\Manufacturing\Production\ProductionMath;
use App\Modules\Manufacturing\Support\StructureLines;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates and changes a production order (ADR-071 §8). A new order copies
 * its structure, components and operations, from the customer order row it
 * serves (the row's own copy, §4) or else from the article's bill of
 * materials valid today, through the same copy shape as M2; the template
 * is never touched. The quantity is entered in any unit the article
 * converts to and stored in its stock unit (§12.4).
 *
 * While the order is a draft its quantity and its copy can change; the
 * dates, the location and the notes can change while it is open. Every
 * change recomputes what the order needs of each component and the
 * planned minutes of each operation.
 */
class SaveProductionOrder
{
    use GuardsManufacturing;

    /** @var list<string> conversions that changed a typed quantity by more than 0,5 % */
    public array $warnings = [];

    /**
     * @param  array<string, mixed>  $input  article_id, or sales_document_id and line_key; quantity, unit_id, location_id, planned_start_on, due_on, notes
     */
    public function create(User $user, Company $company, array $input): ProductionOrder
    {
        $this->guard($user, $company, 'manage');
        $this->warnings = [];

        return CurrentCompany::run($company, fn (): ProductionOrder => DB::transaction(function () use ($user, $company, $input): ProductionOrder {
            [$article, $row, $structure] = $this->source($input);

            if (! $article->is_stock_item) {
                throw ValidationException::withMessages(['article_id' => __('Artikeln :number är inte lagerförd. Markera den som lagerförd för att kunna tillverka den mot lagret.', ['number' => $article->number])]);
            }

            $quantityText = trim((string) ($input['quantity'] ?? ''));

            if ($quantityText === '' && $row instanceof SalesDocumentRow) {
                $input['quantity'] = $row->quantity;
                $input['unit'] = $row->unit;
            }

            [$quantity, $entered, $unitId] = $this->quantity($article, $input);
            $copy = $structure instanceof RowStructure ? $this->fromRow($structure) : $this->fromBom($article);

            $order = ProductionOrder::create([
                'number' => NumberSeries::take(ProductionOrder::SERIES),
                'status' => ProductionOrderStatus::Draft,
                'article_id' => $article->id,
                'bill_of_materials_id' => $copy['bill_of_materials_id'],
                'name' => $copy['name'],
                'yield_milli' => $copy['yield_milli'],
                'quantity_milli' => $quantity,
                'entered_quantity' => $entered,
                'unit_id' => $unitId,
                'location_id' => $this->location($input['location_id'] ?? null) ?? $article->default_location_id ?? StockLocation::query()->where('is_active', true)->orderBy('sort_key')->value('id'),
                'sales_document_id' => $row?->sales_document_id,
                'sales_line_key' => $row?->line_key,
                ...$this->dates($input),
                'notes' => self::notes($input['notes'] ?? null),
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $this->writeCopy($order, $copy['lines']);

            Audit::record('manufacturing.production_order_created', $order, [
                'number' => $order->number,
                'article_id' => $article->id,
                'quantity_milli' => $quantity,
                'source' => $row === null ? 'article' : 'sales_order_row',
                'sales_document_id' => $row?->sales_document_id,
                'bill_of_materials_id' => $copy['bill_of_materials_id'],
                'components' => count(array_filter($copy['lines'], fn (array $line): bool => isset($line['component_article_id']))),
                'operations' => count(array_filter($copy['lines'], fn (array $line): bool => isset($line['operation_id']))),
            ], $company, $user);

            return $order->load(['components', 'operations']);
        }));
    }

    /**
     * Dates, location and notes while the order is open; the quantity only
     * while it is a draft. A field left out keeps its value.
     *
     * @param  array<string, mixed>  $input  quantity, unit_id, location_id, planned_start_on, due_on, notes
     */
    public function update(User $user, Company $company, ProductionOrder $order, array $input): ProductionOrder
    {
        $this->guard($user, $company, 'manage');
        $this->warnings = [];

        return CurrentCompany::run($company, fn (): ProductionOrder => DB::transaction(function () use ($user, $company, $order, $input): ProductionOrder {
            $order = ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->status->isOpen()) {
                throw ValidationException::withMessages(['order' => __('Ordern är färdig eller makulerad och kan inte ändras.')]);
            }

            $input += [
                'planned_start_on' => $order->planned_start_on?->toDateString(),
                'due_on' => $order->due_on?->toDateString(),
                'notes' => $order->notes,
            ];
            $attributes = [
                ...$this->dates($input),
                'notes' => self::notes($input['notes']),
                'updated_by' => $user->id,
            ];

            if (array_key_exists('location_id', $input)) {
                $attributes['location_id'] = $this->location($input['location_id']) ?? $order->location_id;
            }

            $quantityChanged = false;

            if (array_key_exists('quantity', $input)) {
                $article = Article::query()->with('stockUnit')->findOrFail($order->article_id);
                [$quantity, $entered, $unitId] = $this->quantity($article, $input);

                if ($quantity !== $order->quantity_milli || $entered !== $order->entered_quantity || $unitId !== $order->unit_id) {
                    if ($order->status !== ProductionOrderStatus::Draft) {
                        throw ValidationException::withMessages(['quantity' => __('Antalet ändras bara medan ordern är ett utkast. Ta tillbaka den till utkast först.')]);
                    }

                    $attributes += ['quantity_milli' => $quantity, 'entered_quantity' => $entered, 'unit_id' => $unitId];
                    $quantityChanged = $quantity !== $order->quantity_milli;
                }
            }

            $order->forceFill($attributes)->save();

            if ($quantityChanged) {
                $this->recompute($order);
            }

            Audit::record('manufacturing.production_order_updated', $order, [
                'number' => $order->number,
                'quantity_milli' => $order->quantity_milli,
                'quantity_changed' => $quantityChanged,
            ], $company, $user);

            return $order->load(['components', 'operations']);
        }));
    }

    /**
     * Replaces the copy's batch and lines while the order is a draft, with
     * the same rules as a quote or order row's copy (StructureLines).
     *
     * @param  array<string, mixed>  $input  name, yield (the batch, as written), lines
     */
    public function saveStructure(User $user, Company $company, ProductionOrder $order, array $input): ProductionOrder
    {
        $this->guard($user, $company, 'manage');
        $this->warnings = [];

        return CurrentCompany::run($company, fn (): ProductionOrder => DB::transaction(function () use ($user, $company, $order, $input): ProductionOrder {
            $order = ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->with(['components', 'operations'])->firstOrFail();

            if ($order->status !== ProductionOrderStatus::Draft) {
                throw ValidationException::withMessages(['structure' => __('Stycklistan på ordern ändras bara medan ordern är ett utkast.')]);
            }

            try {
                $yield = Quantity::parse((string) ($input['yield'] ?? ''));
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['yield' => __('Mängden går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
            }

            if ($yield <= 0) {
                throw ValidationException::withMessages(['yield' => __('Ange hur många stycklistan gäller för, mer än noll.')]);
            }

            $output = Article::query()->findOrFail($order->article_id);
            $prepare = app(StructureLines::class);
            $lines = $prepare->prepare($company, $output, (array) ($input['lines'] ?? []), array_values($order->operations->map(fn (ProductionOrderOperation $operation): int => $operation->operation_id)->all()));
            $this->warnings = $prepare->warnings;

            $order->components()->delete();
            $order->operations()->delete();
            $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 255);
            $order->forceFill(['name' => $name === '' ? $order->name : $name, 'yield_milli' => $yield, 'updated_by' => $user->id])->save();
            $this->writeCopy($order, $lines);

            Audit::record('manufacturing.production_order_structure_changed', $order, [
                'number' => $order->number,
                'components' => count(array_filter($lines, fn (array $line): bool => isset($line['component_article_id']))),
                'operations' => count(array_filter($lines, fn (array $line): bool => isset($line['operation_id']))),
            ], $company, $user);

            return $order->load(['components', 'operations']);
        }));
    }

    /**
     * The article to make, and when the order serves a customer order row,
     * the row and its own copy.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: Article, 1: ?SalesDocumentRow, 2: ?RowStructure}
     */
    private function source(array $input): array
    {
        if (($input['sales_document_id'] ?? '') !== '' && ($input['sales_document_id'] ?? null) !== null) {
            $document = SalesDocument::query()->where('kind', SalesDocument::ORDER)->find((string) $input['sales_document_id'])
                ?? throw ValidationException::withMessages(['sales_document_id' => __('Välj en kundorder som finns.')]);

            if ($document->status !== SalesDocumentStatus::Open) {
                throw ValidationException::withMessages(['sales_document_id' => __('Kundordern är inte öppen.')]);
            }

            $row = SalesDocumentRow::query()->where('sales_document_id', $document->id)->where('line_key', (string) ($input['line_key'] ?? ''))->first()
                ?? throw ValidationException::withMessages(['line_key' => __('Välj en rad på kundordern.')]);

            if ($row->article_id === null) {
                throw ValidationException::withMessages(['line_key' => __('Raden har ingen artikel att tillverka.')]);
            }

            $article = Article::query()->with('stockUnit')->findOrFail($row->article_id);
            $structure = RowStructure::query()->where('sales_document_id', $document->id)->where('line_key', $row->line_key)->with('lines')->first();

            return [$article, $row, $structure];
        }

        $article = Article::query()->with('stockUnit')->find((int) ($input['article_id'] ?? 0))
            ?? throw ValidationException::withMessages(['article_id' => __('Välj en artikel att tillverka.')]);

        return [$article, null, null];
    }

    /**
     * The quantity in thousandths of the stock unit, what was typed when the
     * unit differs, and the unit's id.
     *
     * @param  array<string, mixed>  $input  quantity, unit_id or unit (a code)
     * @return array{0: int, 1: ?string, 2: ?int}
     */
    private function quantity(Article $article, array $input): array
    {
        $unit = null;

        if (($input['unit_id'] ?? '') !== '' && ($input['unit_id'] ?? null) !== null) {
            $unit = Unit::query()->find((int) $input['unit_id']) ?? throw ValidationException::withMessages(['unit_id' => __('Enheten finns inte i företagets enhetslista.')]);
        }

        $code = $unit->code ?? (string) ($input['unit'] ?? '');
        $conversions = ArticleUnits::fractions(ArticleUnitConversion::query()->where('article_id', $article->id)->get());
        $quantity = RowQuantity::of($article, $conversions, (string) ($input['quantity'] ?? ''), $code, '');

        if ($quantity->warning !== null) {
            $this->warnings[] = $quantity->warning;
        }

        $unitId = $unit?->id;

        if ($unitId === null && $quantity->enteredUnit !== null) {
            $unitId = Unit::query()->where('code', $quantity->enteredUnit)->value('id');
        }

        return [$quantity->stockMilli, $quantity->enteredQuantity, $unitId === null ? $article->stock_unit_id : (int) $unitId];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{planned_start_on: ?string, due_on: ?string}
     */
    private function dates(array $input): array
    {
        $start = self::date($input['planned_start_on'] ?? null, 'planned_start_on');
        $due = self::date($input['due_on'] ?? null, 'due_on');

        if ($start !== null && $due !== null && $due < $start) {
            throw ValidationException::withMessages(['due_on' => __('Klart senast kan inte vara före planerad start.')]);
        }

        return ['planned_start_on' => $start, 'due_on' => $due];
    }

    private static function date(mixed $value, string $field): ?string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1 || ! checkdate((int) substr($text, 5, 2), (int) substr($text, 8, 2), (int) substr($text, 0, 4))) {
            throw ValidationException::withMessages([$field => __('Skriv datumet som ÅÅÅÅ-MM-DD.')]);
        }

        return $text;
    }

    private function location(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $location = StockLocation::query()->whereKey((int) $value)->where('is_active', true)->first()
            ?? throw ValidationException::withMessages(['location_id' => __('Välj ett aktivt lagerställe.')]);

        return $location->id;
    }

    private static function notes(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        if (mb_strlen($text) > 2000) {
            throw ValidationException::withMessages(['notes' => __('Anteckningen får vara högst :max tecken.', ['max' => 2000])]);
        }

        return $text === '' ? null : $text;
    }

    /**
     * The copy of a customer order row's own bill of materials.
     *
     * @return array{bill_of_materials_id: ?int, name: string, yield_milli: int, lines: list<array<string, mixed>>}
     */
    private function fromRow(RowStructure $structure): array
    {
        return [
            'bill_of_materials_id' => $structure->bill_of_materials_id,
            'name' => $structure->name,
            'yield_milli' => $structure->yield_milli,
            'lines' => array_values($structure->lines->map(fn (RowStructureLine $line): array => $line->only(['position', 'component_article_id', 'quantity_milli', 'loss_bp', 'entered_quantity', 'entered_unit', 'operation_id', 'minutes', 'note']))->all()),
        ];
    }

    /**
     * The copy of the article's bill of materials valid today.
     *
     * @return array{bill_of_materials_id: ?int, name: string, yield_milli: int, lines: list<array<string, mixed>>}
     */
    private function fromBom(Article $article): array
    {
        $version = BillOfMaterials::query()
            ->where('kind', StructureKind::Bom)
            ->where('output_article_id', $article->id)
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', CarbonImmutable::today()->toDateString())
            ->orderByDesc('valid_from')->orderByDesc('version')
            ->with('rows')
            ->first() ?? throw ValidationException::withMessages(['article_id' => __(':name har ingen stycklista som gäller i dag. Lägg upp en under Tillverkning → Stycklistor.', ['name' => $article->name])]);

        return [
            'bill_of_materials_id' => $version->id,
            'name' => $version->name,
            'yield_milli' => $version->yield_milli,
            'lines' => array_values($version->rows->values()->map(fn (BomRow $row, int $index): array => [
                'position' => $index,
                'component_article_id' => $row->component_article_id,
                'quantity_milli' => $row->quantity_milli,
                'loss_bp' => $row->loss_bp,
                'entered_quantity' => $row->entered_quantity,
                'entered_unit' => $row->entered_unit,
                'operation_id' => $row->operation_id,
                'minutes' => $row->minutes,
                'note' => $row->note,
            ])->all()),
        ];
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function writeCopy(ProductionOrder $order, array $lines): void
    {
        foreach ($lines as $line) {
            if (($line['operation_id'] ?? null) !== null) {
                ProductionOrderOperation::create([
                    'production_order_id' => $order->id,
                    'position' => (int) $line['position'],
                    'operation_id' => (int) $line['operation_id'],
                    'minutes' => (int) $line['minutes'],
                    'planned_minutes' => ProductionMath::plannedMinutes((int) $line['minutes'], $order->quantity_milli, $order->yield_milli),
                    'note' => $line['note'] ?? null,
                ]);

                continue;
            }

            ProductionOrderComponent::create([
                'production_order_id' => $order->id,
                'position' => (int) $line['position'],
                'component_article_id' => (int) $line['component_article_id'],
                'quantity_milli' => (int) $line['quantity_milli'],
                'loss_bp' => (int) ($line['loss_bp'] ?? 0),
                'entered_quantity' => $line['entered_quantity'] ?? null,
                'entered_unit' => $line['entered_unit'] ?? null,
                'note' => $line['note'] ?? null,
                'required_milli' => ProductionMath::required((int) $line['quantity_milli'], (int) ($line['loss_bp'] ?? 0), $order->quantity_milli, $order->yield_milli),
            ]);
        }
    }

    /** What each component needs and each operation's planned minutes, after the quantity changed. */
    private function recompute(ProductionOrder $order): void
    {
        foreach ($order->components()->get() as $component) {
            $component->forceFill(['required_milli' => ProductionMath::required($component->quantity_milli, $component->loss_bp, $order->quantity_milli, $order->yield_milli)])->save();
        }

        foreach ($order->operations()->get() as $operation) {
            $operation->forceFill(['planned_minutes' => ProductionMath::plannedMinutes($operation->minutes, $order->quantity_milli, $order->yield_milli)])->save();
        }
    }
}
