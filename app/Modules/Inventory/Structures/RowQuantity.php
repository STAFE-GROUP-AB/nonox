<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Structures;

use App\Domain\Units\ArticleUnits;
use App\Domain\Units\QuantityOutOfRange;
use App\Domain\Units\QuantityRoundsToZero;
use App\Domain\Units\UnitConverter;
use App\Models\Article;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\StructureUnits;
use Brick\Math\BigRational;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A component quantity as typed on a structure row (ADR-071 §12.2, §12.4):
 * in any unit the article converts to, stored as milli-units of its stock
 * unit, rounded half up once. A result of zero is refused; a round trip
 * that changes the typed quantity by more than 0,5 % is saved with a
 * warning. What was typed is kept when the unit differs from the stock
 * unit, so the row is shown again as entered. Used by the structure writer
 * and by the bill of materials copies on quote and order rows.
 */
final readonly class RowQuantity
{
    public function __construct(
        public int $stockMilli,
        public ?string $enteredQuantity,
        public ?string $enteredUnit,
        public ?string $warning,
    ) {}

    /**
     * @param  array<string, BigRational>  $conversions  the article's conversions (ArticleUnits::fractions)
     *
     * @throws ValidationException on $field when the quantity cannot be read, is not above zero, has no conversion or rounds to zero
     */
    public static function of(Article $article, array $conversions, string $quantity, string $unit, string $field): self
    {
        try {
            $entered = Quantity::parse($quantity);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field.'quantity' => __('Mängden går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
        }

        if ($entered <= 0) {
            throw ValidationException::withMessages([$field.'quantity' => __('Mängden måste vara mer än noll.')]);
        }

        $stockCode = ArticleUnits::stockCode($article);
        $unit = StructureUnits::normalise($unit !== '' ? $unit : $stockCode);
        $perStock = UnitConverter::enteredPerStock($stockCode, $unit, $conversions);

        if ($perStock === null) {
            throw ValidationException::withMessages([$field.'unit' => __(':name har enheten :unit. Lägg till en omräkning från :entered på artikeln, eller skriv mängden i :unit.', ['name' => $article->name, 'unit' => $stockCode, 'entered' => $unit])]);
        }

        try {
            $conversion = UnitConverter::toStock(Quantity::decimal($entered), $perStock);
        } catch (QuantityOutOfRange) {
            throw ValidationException::withMessages([$field.'quantity' => __('Mängden är för stor för lagerenheten.')]);
        } catch (QuantityRoundsToZero) {
            throw ValidationException::withMessages([$field.'quantity' => __('Mängden blir noll i lagerenheten. Skriv en större mängd.')]);
        }

        $sameUnit = $perStock->isEqualTo(1);
        $warning = $conversion->warns
            ? (string) __(':name: :entered :unit blir :stock :stockUnit i lagerenheten, en ändring på :percent %.', ['name' => $article->name, 'entered' => Quantity::format($entered), 'unit' => $unit, 'stock' => Quantity::format($conversion->stockMilli), 'stockUnit' => $stockCode, 'percent' => $conversion->changePercent])
            : null;

        return new self(
            $conversion->stockMilli,
            $sameUnit ? null : Quantity::decimal($entered),
            $sameUnit ? null : $unit,
            $warning,
        );
    }
}
