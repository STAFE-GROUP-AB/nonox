<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\SalesDocumentRow;
use App\Models\User;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\StructureKind;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\BomRow;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\RowStructure;
use App\Modules\Manufacturing\Models\RowStructureLine;
use App\Modules\Manufacturing\Support\StructureLines;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A quote or order row's own bill of materials (ADR-071 §4). Choosing a
 * bill of materials on a row copies the version valid today into the row;
 * the copy is then changed on the row (quantities, units, scrap, swapped,
 * added or removed components and operations, minutes) and the template is
 * untouched. "Spara som ny stycklista" saves the copy as a new bill of
 * materials or a new version.
 *
 * Changing a copy needs manufacturing:view and the right to change the
 * document, which must be open. The document row is locked, so a copy is
 * never written while the document is being saved.
 */
class SaveRowStructure
{
    /** A copy has at most this many lines, as a bill of materials (StructureWriter::MAX_ROWS). */
    public const int MAX_LINES = StructureLines::MAX_LINES;

    /** @var list<string> conversions of the last update that changed the typed quantity by more than 0,5 % */
    public array $warnings = [];

    public function __construct(
        private readonly ModuleManager $modules,
        private readonly SaveBillOfMaterials $saveBillOfMaterials,
    ) {}

    /** Copies the bill of materials of $article valid today into the row, replacing a copy it had. */
    public function choose(User $user, SalesDocument $document, string $lineKey, Article $article): RowStructure
    {
        return $this->run($user, $document, $lineKey, function (Company $company, SalesDocumentRow $row) use ($user, $document, $lineKey, $article): RowStructure {
            $article = Article::query()->findOrFail($article->id);
            $version = BillOfMaterials::query()
                ->where('kind', StructureKind::Bom)
                ->where('output_article_id', $article->id)
                ->where('is_active', true)
                ->whereDate('valid_from', '<=', CarbonImmutable::today()->toDateString())
                ->orderByDesc('valid_from')->orderByDesc('version')
                ->with('rows')
                ->first() ?? throw ValidationException::withMessages(['structure' => __(':name har ingen stycklista som gäller i dag.', ['name' => $article->name])]);

            RowStructure::query()->where('sales_document_id', $document->id)->where('line_key', $lineKey)->delete();

            $structure = RowStructure::create([
                'sales_document_id' => $document->id,
                'line_key' => $lineKey,
                'output_article_id' => $article->id,
                'bill_of_materials_id' => $version->id,
                'name' => $version->name,
                'yield_milli' => $version->yield_milli,
                'changed' => false,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            foreach ($version->rows as $position => $source) {
                /** @var BomRow $source */
                RowStructureLine::create([
                    'row_structure_id' => $structure->id,
                    'position' => $position,
                    'component_article_id' => $source->component_article_id,
                    'quantity_milli' => $source->quantity_milli,
                    'loss_bp' => $source->loss_bp,
                    'entered_quantity' => $source->entered_quantity,
                    'entered_unit' => $source->entered_unit,
                    'operation_id' => $source->operation_id,
                    'minutes' => $source->minutes,
                    'note' => $source->note,
                ]);
            }

            Audit::record('manufacturing.row_structure_chosen', $document, [
                'line_key' => $lineKey,
                'row_position' => $row->position,
                'bill_of_materials_id' => $version->id,
                'version' => $version->version,
                'lines' => $version->rows->count(),
            ], $company, $user);

            return $structure->load('lines');
        });
    }

    /**
     * Replaces the copy's lines and batch. Lines as the form sends them: a
     * component has article_id, quantity as written, unit (the stock unit
     * when empty), loss in percent and note; an operation has operation_id
     * and minutes for the batch.
     *
     * @param  array<string, mixed>  $input  name, yield (the batch, as written), lines
     */
    public function update(User $user, SalesDocument $document, string $lineKey, array $input): RowStructure
    {
        $this->warnings = [];

        return $this->run($user, $document, $lineKey, function (Company $company, SalesDocumentRow $row) use ($user, $document, $lineKey, $input): RowStructure {
            $structure = RowStructure::query()->where('sales_document_id', $document->id)->where('line_key', $lineKey)->with('lines')->first()
                ?? throw ValidationException::withMessages(['structure' => __('Raden har ingen stycklista.')]);
            $output = Article::query()->findOrFail($structure->output_article_id);

            try {
                $yield = Quantity::parse((string) ($input['yield'] ?? ''));
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['yield' => __('Mängden går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
            }

            if ($yield <= 0) {
                throw ValidationException::withMessages(['yield' => __('Ange hur många stycklistan gäller för, mer än noll.')]);
            }

            $prepare = app(StructureLines::class);
            $prepared = $prepare->prepare($company, $output, (array) ($input['lines'] ?? []), array_values(array_filter($structure->lines->map(fn (RowStructureLine $line): ?int => $line->operation_id)->all())));
            $this->warnings = $prepare->warnings;

            $structure->lines()->delete();

            foreach ($prepared as $line) {
                RowStructureLine::create(['row_structure_id' => $structure->id, ...$line]);
            }

            $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 255);
            $structure->forceFill([
                'name' => $name === '' ? $structure->name : $name,
                'yield_milli' => $yield,
                'changed' => true,
                'updated_by' => $user->id,
            ])->save();

            Audit::record('manufacturing.row_structure_changed', $document, [
                'line_key' => $lineKey,
                'row_position' => $row->position,
                'components' => count(array_filter($prepared, fn (array $line): bool => isset($line['component_article_id']))),
                'operations' => count(array_filter($prepared, fn (array $line): bool => isset($line['operation_id']))),
            ], $company, $user);

            return $structure->load('lines');
        });
    }

    /** Takes the copy off the row. */
    public function remove(User $user, SalesDocument $document, string $lineKey): void
    {
        $this->run($user, $document, $lineKey, function (Company $company, SalesDocumentRow $row) use ($user, $document, $lineKey): bool {
            $deleted = RowStructure::query()->where('sales_document_id', $document->id)->where('line_key', $lineKey)->delete();

            if ($deleted > 0) {
                Audit::record('manufacturing.row_structure_removed', $document, ['line_key' => $lineKey, 'row_position' => $row->position], $company, $user);
            }

            return true;
        });
    }

    /**
     * "Spara som ny stycklista": the copy as a new version of the bill of
     * materials of $output (a new bill of materials when it has none). Needs
     * manufacturing:manage, as any bill of materials.
     *
     * @param  array<string, mixed>  $input  name, valid_from, confirm_backdate
     */
    public function saveAsBillOfMaterials(User $user, SalesDocument $document, string $lineKey, Article $output, array $input): BillOfMaterials
    {
        $company = Company::query()->findOrFail($document->company_id);
        abort_unless($user->belongsToTeam($company), 404);
        $this->modules->ensureEnabled(ManufacturingModule::KEY, $company);
        Gate::forUser($user)->authorize('view', $document);

        return CurrentCompany::run($company, function () use ($user, $company, $document, $lineKey, $output, $input): BillOfMaterials {
            $structure = RowStructure::query()->where('sales_document_id', $document->id)->where('line_key', $lineKey)->with('lines')->firstOrFail();

            $structureVersion = ($this->saveBillOfMaterials)($user, $company, $output, [
                'name' => ($input['name'] ?? '') === '' ? $structure->name : $input['name'],
                'valid_from' => $input['valid_from'] ?? null,
                'confirm_backdate' => $input['confirm_backdate'] ?? false,
                'yield' => Quantity::decimal($structure->yield_milli),
                'rows' => $structure->lines->map(fn (RowStructureLine $line): array => $line->isOperation()
                    ? ['operation_id' => (string) $line->operation_id, 'minutes' => (string) $line->minutes, 'note' => (string) $line->note]
                    : [
                        'article_id' => (string) $line->component_article_id,
                        'quantity' => $line->entered_quantity ?? Quantity::decimal($line->quantity_milli),
                        'unit' => (string) $line->entered_unit,
                        'loss' => $line->loss_bp === 0 ? '' : (string) BigDecimal::of($line->loss_bp)->dividedBy(100, 2, RoundingMode::HalfUp),
                        'note' => (string) $line->note,
                    ])->values()->all(),
            ]);

            Audit::record('manufacturing.row_structure_saved_as_bom', $document, ['line_key' => $lineKey, 'bill_of_materials_id' => $structureVersion->id, 'version' => $structureVersion->version], $company, $user);

            return $structureVersion;
        });
    }

    /**
     * @template T
     *
     * @param  Closure(Company, SalesDocumentRow): T  $callback
     * @return T
     */
    private function run(User $user, SalesDocument $document, string $lineKey, Closure $callback): mixed
    {
        $company = Company::query()->findOrFail($document->company_id);
        // Another company's document does not exist for this user (AGENTS.md §13.2).
        abort_unless($user->belongsToTeam($company), 404);
        $this->modules->ensureEnabled(ManufacturingModule::KEY, $company);
        abort_unless($user->hasTeamPermission($company, 'manufacturing:view'), 403);

        return CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $document, $lineKey, $callback) {
            $locked = SalesDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('update', $locked);

            $row = SalesDocumentRow::query()->where('sales_document_id', $locked->id)->where('line_key', $lineKey)->first()
                ?? throw ValidationException::withMessages(['structure' => __('Raden finns inte längre. Spara dokumentet och försök igen.')]);

            return $callback($company, $row);
        }));
    }
}
