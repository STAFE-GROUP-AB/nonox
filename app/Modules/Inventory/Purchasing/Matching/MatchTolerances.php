<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Matching;

use App\Models\Company;
use App\Modules\Inventory\InventorySettings;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The company's tolerances for the three-way comparison (ADR-081 E11, owner
 * decisions 10 and 11), from Lager's settings.
 *
 * - Quantity: the invoice may charge for at most the received quantity plus
 *   the quantity percentage of it (0 % by default: never more than arrived).
 * - Price: the invoiced amount may exceed what the order's price gives for
 *   the invoiced quantity by the price percentage of that amount or by the
 *   absolute amount, whichever is larger (2 % or 50 kr by default), so
 *   rounding on a small order and a small percentage on a large one both pass.
 * - Invoice rows without an order row (freight, rounding) pass together when
 *   their sum is at most the absolute amount.
 *
 * Only an invoice that charges more than ordered or received is outside; one
 * that charges less is shown but passes.
 */
final readonly class MatchTolerances
{
    public function __construct(
        public int $quantityBp,
        public int $priceBp,
        public int $absoluteOre,
        public bool $blocksAttest,
    ) {}

    public static function for(Company $company): self
    {
        $settings = InventorySettings::for($company);

        return new self(
            max(0, (int) $settings['match_quantity_tolerance_bp']),
            max(0, (int) $settings['match_price_tolerance_bp']),
            max(0, (int) $settings['match_absolute_tolerance_ore']),
            (bool) $settings['match_outside_blocks_attest'],
        );
    }

    /** How many thousandths over the received quantity still pass. */
    public function quantityAllowance(int $receivedMilli): int
    {
        return BigDecimal::of(max(0, $receivedMilli))->multipliedBy($this->quantityBp)->dividedBy(10000, 0, RoundingMode::Down)->toInt();
    }

    /** How many öre over the expected amount still pass. */
    public function priceAllowance(int $expectedOre): int
    {
        $share = BigDecimal::of(abs($expectedOre))->multipliedBy($this->priceBp)->dividedBy(10000, 0, RoundingMode::Down)->toInt();

        return max($share, $this->absoluteOre);
    }

    /** "antal 0 %, pris 2 % eller 50,00 kr" for the page and the manual. */
    public function describe(): string
    {
        return __('antal :quantity %, pris :price % eller :amount', [
            'quantity' => self::percent($this->quantityBp),
            'price' => self::percent($this->priceBp),
            'amount' => Money::ofOre($this->absoluteOre)->format(),
        ]);
    }

    /** Basis points as Swedish percent text without trailing zeros: 250 → "2,5". */
    public static function percent(int $bp): string
    {
        $text = BigDecimal::of($bp)->dividedBy(100, 2)->__toString();

        return str_replace('.', ',', str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text);
    }

    /** "2,5" or "2.5" or "2" percent as basis points; null when it cannot be read or is outside 0–100 %. */
    public static function parsePercent(string $input): ?int
    {
        $text = str_replace([' ', ','], ['', '.'], trim($input));

        if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $text) !== 1) {
            return null;
        }

        $bp = BigDecimal::of($text)->multipliedBy(100)->toScale(0)->toInt();

        return $bp <= 10000 ? $bp : null;
    }
}
