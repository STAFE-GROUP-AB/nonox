<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Units\UnitCatalogue;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A row whose unit is a unit of the company's register (ADR-071 §12.9),
 * with the unit text kept beside it as printed. A row written with only a
 * text is linked to the unit the text names when it is created. A text the
 * register does not know is a bug in the code that wrote the row and
 * throws; only an import may add the spelling, inside
 * UnitCatalogue::addingUnits().
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

            $companyId = (int) $row->getAttribute('company_id');
            $found = UnitCatalogue::find($companyId, (string) $text);

            if ($found === null && ! UnitCatalogue::addsUnits()) {
                throw new LogicException("The unit text \"{$text}\" is not in the company's unit register; resolve it before writing the row.");
            }

            $row->setAttribute('unit_id', $found['id'] ?? UnitCatalogue::resolveId($companyId, (string) $text));
        });
    }

    /** @return BelongsTo<Unit, $this> */
    public function registerUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}
