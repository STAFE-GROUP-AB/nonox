<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Units\UnitCatalogue;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row whose unit is a unit of the company's register (ADR-071 §12.9),
 * with the unit text kept beside it as printed. A row written with only a
 * text (an older code path, an import) is linked to the unit the text
 * names when it is created, and a new unit is added for a spelling the
 * register does not know, so every new row has one.
 *
 * @property ?int $unit_id
 *
 * @mixin Model
 */
trait HasRegisterUnit
{
    public static function bootHasRegisterUnit(): void
    {
        static::creating(function (Model $row): void {
            $text = $row->getAttribute('unit');

            if ($row->getAttribute('unit_id') !== null || $text === null || $row->getAttribute('company_id') === null) {
                return;
            }

            $row->setAttribute('unit_id', UnitCatalogue::resolveId((int) $row->getAttribute('company_id'), (string) $text));
        });
    }

    /** @return BelongsTo<Unit, $this> */
    public function registerUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}
