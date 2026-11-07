<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;

/**
 * The size figures of one fiscal year (S1-R9.2): the average number of
 * employees in full-time equivalents, exact (null when not known), the
 * balance sheet total and the net turnover in öre. `source` is `ledger` for
 * a year kept in NONOX, `entered` for a year a person entered. A fiscal year
 * begins on the first day of a month and ends on the last day of a month.
 */
final readonly class K3SizeFigures
{
    public ?BigNumber $averageEmployees;

    /** @param BigNumber|string|int|null $averageEmployees full-time equivalents, "50.4" or exact (K3CompanySize::fteFromHours) */
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        BigNumber|string|int|null $averageEmployees,
        public int $balanceSheetTotalOre,
        public int $netTurnoverOre,
        public string $source = 'entered',
    ) {
        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('A fiscal year ends after it begins.');
        }

        $this->averageEmployees = $averageEmployees === null ? null : BigNumber::of($averageEmployees);
    }

    /**
     * The average number of employees as the whole number the thresholds are
     * compared with. BFN's vägledning Gränsvärden, example 3: "Totalt 9,4
     * personer. Avrundas till 9 anställda".
     */
    public function averageEmployeesRounded(): ?int
    {
        if ($this->averageEmployees === null) {
            return null;
        }

        $rounding = K3Rules::rule('average_employees_rounding')['value'];

        if ($rounding !== 'half_up_whole') {
            throw new LogicException("Unknown rounding of employees: {$rounding}.");
        }

        // TODO(legal-verify): BFN's examples 3–4 round the medelantal (9,4 → 9, 11,1 → 11). Applying that rounding
        // before the ÅRL 1 kap. 3 § and K2 1.1C threshold comparisons is NONOX's inference, and so is half-up for x,5.
        return $this->averageEmployees->toBigRational()->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /** The exact average with two decimals and a decimal comma, for a reason text. */
    public function averageEmployeesText(): ?string
    {
        return $this->averageEmployees === null
            ? null
            : str_replace('.', ',', (string) $this->averageEmployees->toBigRational()->toScale(2, RoundingMode::HalfUp));
    }

    /** The number of calendar months the year covers. */
    public function months(): int
    {
        return ($this->end->year * 12 + $this->end->month) - ($this->start->year * 12 + $this->start->month) + 1;
    }

    /**
     * Whether the net turnover adjusted to twelve months (BFNAR 2006:11 point
     * 5 c) is more than the threshold, compared exactly: turnover × 12 >
     * threshold × months, in whole öre.
     */
    public function exceedsNetTurnover(int $thresholdOre): bool
    {
        $twelve = K3Rules::int('net_turnover_prorated_to_months');

        return BigInteger::of($this->netTurnoverOre)->multipliedBy($twelve)
            ->isGreaterThan(BigInteger::of($thresholdOre)->multipliedBy($this->months()));
    }

    /**
     * The net turnover adjusted to twelve months, rounded half up to the öre.
     * For display only: the test compares exactly (exceedsNetTurnover).
     */
    public function annualisedNetTurnoverOre(): int
    {
        return BigRational::of($this->netTurnoverOre)
            ->multipliedBy(K3Rules::int('net_turnover_prorated_to_months'))
            ->dividedBy($this->months())
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();
    }

    /** The key of the year in results: its end date. */
    public function key(): string
    {
        return $this->end->toDateString();
    }

    /** "2025" for a year within one calendar year, else "2024/2025", as FiscalYear::label(). */
    public function label(): string
    {
        return $this->start->year === $this->end->year
            ? (string) $this->start->year
            : "{$this->start->year}/{$this->end->year}";
    }

    /** "2025-01-01–2025-06-30", when labels would be ambiguous. */
    public function period(): string
    {
        return $this->start->toDateString().'–'.$this->end->toDateString();
    }
}
