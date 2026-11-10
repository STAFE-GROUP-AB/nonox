<?php

declare(strict_types=1);

namespace App\Domain\Units;

use App\Models\Article;
use App\Models\Unit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The company's unit register as the only source of units on rows
 * (ADR-071 §12.9). Loaded once per save or screen, so a document with
 * many rows asks for the units once. A row names its unit by id or, from
 * the API, by a code or alias of the register; a text the register does
 * not know is refused. Without either, an article row takes the article's
 * sales unit and a free row the company's default unit. Must run inside
 * the company.
 */
final class UnitRegister
{
    /** @param  Collection<int, Unit>  $units */
    private function __construct(private readonly Collection $units) {}

    public static function load(): self
    {
        $companyId = CurrentCompany::id() ?? throw new \LogicException('The unit register needs a company.');
        $units = Unit::query()->orderBy('code')->get();

        if ($units->isEmpty()) {
            UnitCatalogue::seed($companyId);
            $units = Unit::query()->orderBy('code')->get();
        }

        return new self($units);
    }

    /** @return Collection<int, Unit> */
    public function all(): Collection
    {
        return $this->units;
    }

    public function find(mixed $id): ?Unit
    {
        $id = (int) $id;

        return $id > 0 ? $this->units->first(fn (Unit $unit): bool => $unit->id === $id) : null;
    }

    /** The unit a code or alias names, case-insensitive; null when the register has none. */
    public function match(string $text): ?Unit
    {
        $key = UnitDefaults::normalise($text);

        if ($key === '') {
            return null;
        }

        return $this->units->first(fn (Unit $unit): bool => mb_strtolower($unit->code) === $key)
            ?? $this->units->first(fn (Unit $unit): bool => in_array($key, $unit->aliases, true));
    }

    /** The unit a free row starts with: the one the company marked, otherwise styck, otherwise the first active unit. */
    public function default(): Unit
    {
        $active = $this->units->reject(fn (Unit $unit): bool => $unit->isArchived());

        return $active->first(fn (Unit $unit): bool => $unit->row_default)
            ?? $active->first(fn (Unit $unit): bool => $unit->code === 'st')
            ?? $active->first()
            ?? $this->units->firstOrFail();
    }

    /**
     * The units a select offers: the active ones, and the archived ones the
     * document already uses, so an old row still shows its unit.
     *
     * @param  iterable<mixed>  $keep  unit ids already on the document
     * @return Collection<int, Unit>
     */
    public function choices(iterable $keep = []): Collection
    {
        $kept = array_map(intval(...), [...$keep]);

        return $this->units->filter(fn (Unit $unit): bool => ! $unit->isArchived() || in_array($unit->id, $kept, true))->values();
    }

    /**
     * The unit of one row.
     *
     * @throws ValidationException
     */
    public function forRow(mixed $unitId, mixed $text, ?Article $article, string $key): Unit
    {
        if ($unitId !== null && $unitId !== '') {
            return $this->find($unitId) ?? throw ValidationException::withMessages([$key => __('Enheten finns inte i företagets enhetslista.')]);
        }

        $text = trim((string) $text);

        if ($text !== '') {
            return $this->match($text) ?? throw ValidationException::withMessages([$key => __('Enheten :unit finns inte i enhetslistan. Välj en enhet ur listan, eller lägg till den under Enheter.', ['unit' => mb_substr($text, 0, 20)])]);
        }

        if ($article !== null) {
            $unit = $this->find($article->sales_unit_id ?? $article->stock_unit_id) ?? $this->match((string) $article->unit);

            if ($unit !== null) {
                return $unit;
            }
        }

        return $this->default();
    }

    /**
     * The text printed on the row: the unit's code, or a spelling of the
     * same unit the row already carried or its article was written with
     * ("tim" stays "tim" for hours written that way, as before).
     */
    public function snapshot(Unit $unit, ?Article $article, mixed $text = null): string
    {
        foreach ([trim((string) $text), $article !== null ? trim($article->unit) : ''] as $spelling) {
            if ($spelling !== '' && $this->match($spelling)?->id === $unit->id) {
                return mb_substr($spelling, 0, 10);
            }
        }

        return $unit->code;
    }

    /**
     * Refuses an archived unit on a row unless the document already had it.
     *
     * @param  array<string, mixed>  $unitIds  error key => unit id
     * @param  iterable<mixed>  $keep  unit ids the document had when it was opened
     *
     * @throws ValidationException
     */
    public function assertSelectable(array $unitIds, iterable $keep = []): void
    {
        $kept = array_map(intval(...), [...$keep]);

        foreach ($unitIds as $key => $id) {
            $unit = $this->find($id);

            if ($unit !== null && $unit->isArchived() && ! in_array($unit->id, $kept, true)) {
                throw ValidationException::withMessages([$key => __('Enheten :unit är arkiverad och kan inte väljas.', ['unit' => $unit->code])]);
            }
        }
    }
}
