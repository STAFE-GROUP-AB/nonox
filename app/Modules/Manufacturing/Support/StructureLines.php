<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Support;

use App\Domain\Units\ArticleUnits;
use App\Models\Article;
use App\Models\Company;
use App\Modules\Inventory\Domain\StructureKind;
use App\Modules\Inventory\Domain\StructureMath;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Modules\Inventory\Structures\RowQuantity;
use App\Modules\Inventory\Structures\StructureBook;
use App\Modules\Manufacturing\Models\Operation;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * The lines of a copy of a bill of materials as a form sends them, checked
 * and converted the same way wherever a copy is changed: a quote or order
 * row's copy (SaveRowStructure) and a production order's copy
 * (SaveProductionOrder). A component has article_id, quantity as written,
 * unit (the stock unit when empty), loss in percent and note; an operation
 * has operation_id and whole minutes for the batch. Components are stored
 * in the stock unit (ADR-071 §12), loops and nesting past the limit are
 * refused, and an archived operation may stay only where it already was.
 * Runs inside the company.
 */
final class StructureLines
{
    /** A copy has at most this many lines, as a bill of materials (StructureWriter::MAX_ROWS). */
    public const int MAX_LINES = 200;

    /** @var list<string> conversions that changed the typed quantity by more than 0,5 % */
    public array $warnings = [];

    /**
     * @param  array<mixed>  $input  the lines as sent
     * @param  list<int>  $keptOperationIds  operations already on the copy, allowed even when archived
     * @return list<array{position: int, component_article_id?: int, quantity_milli?: int, loss_bp?: int, entered_quantity?: ?string, entered_unit?: ?string, operation_id?: int, minutes?: int, note: ?string}>
     */
    public function prepare(Company $company, Article $output, array $input, array $keptOperationIds = []): array
    {
        $this->warnings = [];
        $lines = array_values(array_filter($input, fn (mixed $line): bool => is_array($line) && (($line['article_id'] ?? '') !== '' || ($line['operation_id'] ?? '') !== '')));

        if (! array_filter($lines, fn (array $line): bool => ($line['article_id'] ?? '') !== '')) {
            throw ValidationException::withMessages(['lines' => __('Lägg till minst en komponent.')]);
        }

        if (count($lines) > self::MAX_LINES) {
            throw ValidationException::withMessages(['lines' => __('En stycklista kan ha högst :max rader.', ['max' => self::MAX_LINES])]);
        }

        $operations = Operation::query()->where(fn ($query) => $query->whereNull('archived_at')->orWhereIn('id', $keptOperationIds))->pluck('id')->flip()->all();
        $componentIds = array_map(fn (array $line): int => (int) $line['article_id'], array_filter($lines, fn (array $line): bool => ($line['article_id'] ?? '') !== ''));
        $articles = Article::query()->with('stockUnit')->whereKey($componentIds)->get()->keyBy('id');
        $conversions = ArticleUnitConversion::query()->whereIn('article_id', $articles->keys()->all())->get()->groupBy('article_id')->map(fn ($group): array => ArticleUnits::fractions($group));
        $book = StructureBook::for($company, StructureKind::Bom);
        $prepared = [];

        foreach ($lines as $index => $line) {
            $note = ($line['note'] ?? '') === '' ? null : mb_substr(trim((string) $line['note']), 0, 255);

            if (($line['article_id'] ?? '') === '') {
                $operationId = (int) $line['operation_id'];

                if (! isset($operations[$operationId])) {
                    throw ValidationException::withMessages(["lines.{$index}.operation_id" => __('Välj ett arbetssteg som finns och inte är arkiverat.')]);
                }

                $minutes = str_replace(' ', '', trim((string) ($line['minutes'] ?? '')));

                if (preg_match('/^\d{1,6}$/', $minutes) !== 1 || (int) $minutes <= 0) {
                    throw ValidationException::withMessages(["lines.{$index}.minutes" => __('Skriv tiden i hela minuter, mer än noll.')]);
                }

                $prepared[] = ['position' => $index, 'operation_id' => $operationId, 'minutes' => (int) $minutes, 'note' => $note];

                continue;
            }

            $article = $articles->get((int) $line['article_id']) ?? throw ValidationException::withMessages(["lines.{$index}.article_id" => __('Artikeln finns inte.')]);

            if ($article->id === $output->id || $book->reaches($article->id, $output->id)) {
                throw ValidationException::withMessages(["lines.{$index}.article_id" => __(':name innehåller redan :output, så stycklistan skulle gå i en ring.', ['name' => $article->name, 'output' => $output->name])]);
            }

            $quantity = RowQuantity::of($article, $conversions->get($article->id, []), (string) ($line['quantity'] ?? ''), (string) ($line['unit'] ?? ''), "lines.{$index}.");

            if ($quantity->warning !== null) {
                $this->warnings[] = $quantity->warning;
            }

            $prepared[] = [
                'position' => $index,
                'component_article_id' => $article->id,
                'quantity_milli' => $quantity->stockMilli,
                'loss_bp' => self::loss($line['loss'] ?? null, "lines.{$index}.loss"),
                'entered_quantity' => $quantity->enteredQuantity,
                'entered_unit' => $quantity->enteredUnit,
                'note' => $note,
            ];
        }

        $levels = 1 + max([0, ...array_map(fn (array $line): int => isset($line['component_article_id']) ? $book->depth((int) $line['component_article_id']) : 0, $prepared)]);

        if ($levels > StructureBook::MAX_DEPTH) {
            throw ValidationException::withMessages(['lines' => __('Stycklistan blir för djup: högst :max nivåer av stycklistor i varandra.', ['max' => StructureBook::MAX_DEPTH])]);
        }

        return $prepared;
    }

    /** A loss in percent as written ("12,5") as basis points, 0–9 999. */
    public static function loss(mixed $value, string $field): int
    {
        $text = str_replace([' ', ','], ['', '.'], trim((string) ($value ?? '')));

        if ($text === '') {
            return 0;
        }

        try {
            $bp = BigDecimal::of($text)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
        } catch (MathException) {
            throw ValidationException::withMessages([$field => __('Spillet går inte att läsa. Skriv procent, till exempel 10 eller 12,5.')]);
        }

        if ($bp < 0 || $bp >= StructureMath::BP) {
            throw ValidationException::withMessages([$field => __('Spillet måste vara från 0 till under 100 procent.')]);
        }

        return $bp;
    }
}
