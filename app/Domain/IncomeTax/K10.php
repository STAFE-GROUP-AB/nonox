<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * One owner's K10 for one inkomstår under the gränsbelopp rules from
 * inkomstår 2026 (IL 57 kap. 10–20 a §§ as amended by SFS 2025:1361;
 * ADR-062). Pure arithmetic on the form's points, in whole kronor, each
 * point from the points before it as on the form:
 *
 * - A (1.1–1.6) gränsbelopp: grundbelopp, lönebaserat utrymme, ränta on the
 *   omkostnadsbelopp above 100 000 kr, sparat utdelningsutrymme;
 * - B (2.1–2.10) the dividend split between tjänst and kapital;
 * - D (4.1–4.13) the lönebaserat utrymme.
 *
 * Not handled, and refused by the page: shares transferred during the year
 * (1.5, 2.5, section C), shares that are "särskilt kvalificerade" (4535) and
 * shares of several classes.
 *
 * TODO(legal-verify): rounding. Amounts that build the gränsbelopp are
 * rounded down to whole kronor; the taxable two thirds (2.8) is rounded to
 * the nearest krona. Neither the law nor the form says how.
 */
final class K10
{
    /** @var array<string, int> kronor by form point */
    private array $points = [];

    private function __construct(public readonly K10Parameters $parameters, public readonly K10Input $input)
    {
        $this->compute();
    }

    public static function calculate(K10Parameters $parameters, K10Input $input): self
    {
        if ($input->sharesTotal < 1 || $input->sharesOwned < 1 || $input->sharesOwned + $input->spouseShares > $input->sharesTotal) {
            throw new \InvalidArgumentException('The owner\'s shares must be between 1 and the company\'s shares.');
        }

        return new self($parameters, $input);
    }

    /** @return array<string, int> kronor by form point, only the points that apply */
    public function points(): array
    {
        return $this->points;
    }

    public function point(string $point): ?int
    {
        return $this->points[$point] ?? null;
    }

    public function limitKronor(): int
    {
        return $this->points['1.6'];
    }

    public function hasDividend(): bool
    {
        return $this->input->dividendKronor > 0;
    }

    /** What is carried to next year: 2.6 after a dividend, the whole gränsbelopp without one (IL 57 kap. 13 §). */
    public function savedForNextYearKronor(): int
    {
        return $this->hasDividend() ? $this->points['2.6'] : $this->points['1.6'];
    }

    /** The lönebaserat utrymme before the 50× cap (4.12, or 4.13 for spouses). */
    public function salarySpaceBeforeCapKronor(): int
    {
        return max(0, $this->points['4.13'] ?? $this->points['4.12'] ?? 0);
    }

    /** The cap of IL 57 kap. 16 § tredje stycket: 50 × the highest kontant ersättning to the owner or a närstående. */
    public function salarySpaceCapKronor(): int
    {
        return $this->input->capBasisKronor * $this->parameters->int('salary_space_cap_multiple');
    }

    public function isSalarySpaceCapped(): bool
    {
        return $this->salarySpaceBeforeCapKronor() > $this->salarySpaceCapKronor();
    }

    public function isSpouseCalculation(): bool
    {
        return $this->input->spouseShares > 0;
    }

    /** The grundbelopp was shared with the owner's other fåmansföretag (IL 57 kap. 11 a §). */
    public function isBasicAmountShared(): bool
    {
        $in = $this->input;

        if ($in->otherHoldingsBp === null || $in->otherHoldingsBp <= 0) {
            return false;
        }

        return BigInteger::of($in->sharesOwned)->multipliedBy(10_000)->plus(BigInteger::of($in->otherHoldingsBp)->multipliedBy($in->sharesTotal))
            ->isGreaterThan(BigInteger::of($in->sharesTotal)->multipliedBy(10_000));
    }

    /** The tjänst part was capped at 90 inkomstbasbelopp (IL 57 kap. 20 a §). */
    public function isEmploymentIncomeCapped(): bool
    {
        return $this->hasDividend() && $this->input->dividendKronor - $this->points['1.6'] > $this->points['2.3'];
    }

    /**
     * The fields of the K10 block in BLANKETTER.SRU. Zero amounts are left
     * out, as Skatteverket's file example does; a negative 4.11 is left out
     * with 4.12 and 4.13, because the form adds no lönebaserat utrymme then
     * (TODO(legal-verify): whether Numeriskt_B accepts a negative 4586).
     *
     * @return array<int, int|string>
     */
    public function fields(string $companyIdentity): array
    {
        $fields = [
            K10Codes::COMPANY_ORG_NUMBER => $companyIdentity,
            K10Codes::SHARES_OWNED => $this->input->sharesOwned,
            K10Codes::SHARES_TOTAL => $this->input->sharesTotal,
        ];
        $negativeBase = ($this->points['4.11'] ?? 0) < 0;

        foreach (K10Codes::FIELDS as $point => $code) {
            $kronor = $this->points[$point] ?? null;

            if ($kronor === null || $kronor === 0) {
                continue;
            }

            if ($negativeBase && in_array($point, ['4.11', '4.12', '4.13'], true)) {
                continue;
            }

            $fields[$code] = $kronor;
        }

        return $fields;
    }

    private function compute(): void
    {
        $in = $this->input;
        $p = $this->parameters;

        // 1.1 Grundbelopp: four inkomstbasbelopp shared equally on the company's shares (11 § första stycket 1),
        // and at most one grundbelopp over all the owner's fåmansföretag, by holding (11 a §).
        $basic = intdiv($p->basicAmountOre(), 100);
        $this->points['1.1'] = $this->isBasicAmountShared()
            ? self::share($basic, BigInteger::of($in->sharesOwned)->multipliedBy(10_000), BigInteger::of($in->sharesOwned)->multipliedBy(10_000)->plus(BigInteger::of((int) $in->otherHoldingsBp)->multipliedBy($in->sharesTotal)))
            : self::share($basic, BigInteger::of($in->sharesOwned), BigInteger::of($in->sharesTotal));

        // D. Lönebaserat utrymme (16–17 §§), jointly for spouses (16 § andra stycket).
        $shares = $in->sharesOwned + $in->spouseShares;
        $base = $in->companyPayKronor + $in->subsidiaryPayKronor;
        $share = self::share($base, BigInteger::of($shares), BigInteger::of($in->sharesTotal));

        if ($in->acquiredDuringPreviousYear) {
            $this->points += ['4.5' => $in->companyPayKronor, '4.6' => $in->subsidiaryPayKronor, '4.7' => $base, '4.8' => $share];
        } else {
            $this->points += ['4.1' => $in->companyPayKronor, '4.2' => $in->subsidiaryPayKronor, '4.3' => $base, '4.4' => $share];
        }

        $this->points['4.9'] = $share;
        $this->points['4.10'] = intdiv($p->salaryDeductionOre(), 100);
        $this->points['4.11'] = $share - $this->points['4.10'];
        $this->points['4.12'] = $this->points['4.11'] > 0 ? self::floor($this->points['4.11'] * $p->int('salary_space_share_bp'), 10_000) : 0;

        if ($this->isSpouseCalculation()) {
            $this->points['4.13'] = self::share($this->points['4.12'], BigInteger::of($in->sharesOwned), BigInteger::of($shares));
        }

        // 1.2 The owner's lönebaserat utrymme, at most 50 × the cash pay of the owner or a närstående (16 § tredje stycket).
        $this->points['1.2'] = min($this->salarySpaceBeforeCapKronor(), $this->salarySpaceCapKronor());

        // 1.3 Ränta on the omkostnadsbelopp above 100 000 kr at statslåneräntan + 9 procentenheter (11 § andra stycket).
        $above = max(0, $in->costBasisKronor - intdiv($p->int('cost_basis_threshold_ore'), 100));
        $this->points['1.3'] = self::floor($above * $p->costBasisInterestBp(), 10_000);

        // 1.4 Sparat utdelningsutrymme, carried without uplift from inkomstår 2026 (13 §).
        $this->points['1.4'] = $in->savedSpaceKronor;
        $this->points['1.6'] = $this->points['1.1'] + $this->points['1.2'] + $this->points['1.3'] + $this->points['1.4'];

        if (! $this->hasDividend()) {
            return;
        }

        // B. Utdelning: above the gränsbelopp in tjänst (20 §), at most 90 inkomstbasbelopp (20 a §); within it two thirds in kapital.
        $dividend = $in->dividendKronor;
        $limit = $this->points['1.6'];
        $capital = $p->fraction('dividend_capital_share');
        $this->points['2.1'] = $dividend;
        $this->points['2.2'] = $limit;
        $this->points['2.3'] = min(max(0, $dividend - $limit), intdiv($p->employmentIncomeCapOre(), 100));
        $this->points['2.4'] = max(0, $limit - $dividend);
        $this->points['2.6'] = $this->points['2.4'];
        $this->points['2.7'] = min($dividend, $limit);
        $this->points['2.8'] = BigInteger::of($this->points['2.7'])->multipliedBy($capital['numerator'])->dividedBy($capital['denominator'], RoundingMode::HalfUp)->toInt();
        $this->points['2.9'] = $dividend - $this->points['2.3'] - $this->points['2.7'];
        $this->points['2.10'] = $this->points['2.8'] + $this->points['2.9'];
    }

    private static function floor(int $numerator, int $denominator): int
    {
        return BigInteger::of($numerator)->dividedBy($denominator, RoundingMode::Floor)->toInt();
    }

    /** An amount × part / whole, rounded down to whole kronor, without overflow on large share counts. */
    private static function share(int $kronor, BigInteger $part, BigInteger $whole): int
    {
        return BigInteger::of($kronor)->multipliedBy($part)->dividedBy($whole, RoundingMode::Floor)->toInt();
    }
}
