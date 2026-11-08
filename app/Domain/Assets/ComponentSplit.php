<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use App\Support\Money\Money;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * How an asset's cost and accumulated depreciation are divided among its
 * components when it is split (K3 17.4, 17.11; at transition 35.18, S1-R3,
 * S1-R7.2 a; ADR-059 A.2.4). No database, no side effects.
 *
 * The cost is the person's allocation (17.11 "anskaffningsvärde fördelas"):
 * every component's cost is entered and the costs must add up to the asset's
 * cost to the öre. The accumulated depreciation follows the cost: land gets
 * none (it is never depreciated, S1-R3.2), and the rest is divided among the
 * depreciable components in proportion to their cost by the largest
 * remainder method (whole öre, the leftover öre go to the largest fractions,
 * the earlier component first on a tie), so the parts add up exactly. When
 * the person knows each component's own history (a register kept per
 * component, as in F-S1-a) the accumulated depreciation of every depreciable
 * component may be entered instead; it must then add up exactly too.
 *
 * TODO(legal-verify): K3 35.18 says the carrying amount is split into
 * significant components but gives no allocation key; dividing the
 * accumulated depreciation in proportion to cost (land excluded) is NONOX's
 * default, to be confirmed by the accountant.
 */
final class ComponentSplit
{
    /**
     * @param  list<array{cost_ore: int, land: bool, accumulated_ore: ?int}>  $parts
     * @return list<array{cost_ore: int, accumulated_ore: int}>
     *
     * @throws ComponentSplitRefused
     */
    public static function allocate(int $costOre, int $accumulatedOre, array $parts): array
    {
        if (count($parts) < 2) {
            throw new ComponentSplitRefused(__('Dela upp tillgången i minst två komponenter.'));
        }

        if (count(array_filter($parts, fn (array $part): bool => $part['land'])) > 1) {
            throw new ComponentSplitRefused(__('En tillgång kan bara ha en markkomponent.'));
        }

        foreach ($parts as $part) {
            if ($part['cost_ore'] <= 0) {
                throw new ComponentSplitRefused(__('Varje komponent ska ha ett anskaffningsvärde större än noll.'));
            }

            if ($part['land'] && ($part['accumulated_ore'] ?? 0) !== 0) {
                throw new ComponentSplitRefused(__('Mark skrivs inte av och kan inte ha gjorda avskrivningar.'));
            }
        }

        $total = array_sum(array_column($parts, 'cost_ore'));

        if ($total !== $costOre) {
            throw new ComponentSplitRefused(__('Komponenternas anskaffningsvärden ska tillsammans vara :expected. Nu är de :actual.', [
                'expected' => Money::ofOre($costOre)->format(),
                'actual' => Money::ofOre($total)->format(),
            ]));
        }

        $depreciable = array_keys(array_filter($parts, fn (array $part): bool => ! $part['land']));
        $entered = array_filter($depreciable, fn (int $index): bool => $parts[$index]['accumulated_ore'] !== null);

        if ($depreciable === [] && $accumulatedOre > 0) {
            throw new ComponentSplitRefused(__('Tillgången har avskrivningar men ingen komponent som skrivs av.'));
        }

        $accumulated = array_fill(0, count($parts), 0);

        if ($entered !== [] && count($entered) !== count($depreciable)) {
            throw new ComponentSplitRefused(__('Fyll i gjorda avskrivningar för alla komponenter som skrivs av, eller för ingen.'));
        }

        if ($entered !== []) {
            foreach ($depreciable as $index) {
                $accumulated[$index] = (int) $parts[$index]['accumulated_ore'];
            }

            if (array_sum($accumulated) !== $accumulatedOre) {
                throw new ComponentSplitRefused(__('Komponenternas gjorda avskrivningar ska tillsammans vara :expected. Nu är de :actual.', [
                    'expected' => Money::ofOre($accumulatedOre)->format(),
                    'actual' => Money::ofOre(array_sum($accumulated))->format(),
                ]));
            }
        } else {
            $weights = array_combine($depreciable, array_map(fn (int $index): int => $parts[$index]['cost_ore'], $depreciable));

            foreach (self::largestRemainder($accumulatedOre, $weights) as $index => $share) {
                $accumulated[$index] = $share;
            }
        }

        $result = [];

        foreach ($parts as $index => $part) {
            if ($accumulated[$index] < 0 || $accumulated[$index] > $part['cost_ore']) {
                throw new ComponentSplitRefused(__('En komponents gjorda avskrivningar kan inte vara negativa eller större än dess anskaffningsvärde.'));
            }

            $result[] = ['cost_ore' => $part['cost_ore'], 'accumulated_ore' => $accumulated[$index]];
        }

        return $result;
    }

    /**
     * Divides an amount in whole öre in proportion to the weights: each share
     * rounded down, then one öre each to the largest remainders until the
     * shares add up (the earlier key first on a tie).
     *
     * @param  array<int, int>  $weights  positive, by key
     * @return array<int, int> by key
     */
    public static function largestRemainder(int $amountOre, array $weights): array
    {
        $sum = array_sum($weights);

        if ($weights === [] || $sum <= 0) {
            return [];
        }

        $shares = [];
        $remainders = [];

        foreach ($weights as $key => $weight) {
            $product = BigInteger::of($amountOre)->multipliedBy($weight);
            $shares[$key] = $product->dividedBy($sum, RoundingMode::Floor)->toInt();
            $remainders[$key] = $product->mod($sum)->toInt();
        }

        $left = $amountOre - array_sum($shares);
        $order = array_keys($weights);
        usort($order, fn (int $a, int $b): int => [$remainders[$b], $a] <=> [$remainders[$a], $b]);

        foreach (array_slice($order, 0, $left) as $key) {
            $shares[$key]++;
        }

        return $shares;
    }
}
