<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

/**
 * What one owner's K10 needs (ADR-062), in whole kronor and share counts:
 * the proposals from the books after the person's corrections, and what only
 * the owner knows. The shares are those at the start of the inkomstår (IL 57
 * kap. 11 § fjärde stycket).
 */
final readonly class K10Input
{
    /**
     * @param  int  $sharesOwned  the owner's shares at the start of the year (K10 4531)
     * @param  int  $sharesTotal  all shares in the company at the start of the year (4532)
     * @param  ?int  $otherHoldingsBp  the owner's holdings in other fåmansföretag with qualified shares, summed, in basis points (IL 57 kap. 11 a §); null when none
     * @param  int  $spouseShares  the shares of an owner who was the owner's spouse at the start of the year (16 § andra stycket); 0 when none
     * @param  bool  $acquiredDuringPreviousYear  the shares were acquired during the year before (16 a §): the pay goes to p. 4.5–4.8
     * @param  int  $companyPayKronor  kontant ersättning to the employees of the company in the year before, while the shares were owned (p. 4.1 or 4.5)
     * @param  int  $subsidiaryPayKronor  the same from subsidiaries, by the parent's share and holding time (p. 4.2 or 4.6)
     * @param  int  $capBasisKronor  the highest kontant ersättning the owner or a närstående had from the company in the year before (16 § tredje stycket)
     * @param  int  $costBasisKronor  the omkostnadsbelopp of the owner's shares at the start of the year (11 § andra stycket, 12 §)
     * @param  int  $savedSpaceKronor  sparat utdelningsutrymme from the year before (13 §)
     * @param  int  $dividendKronor  dividend on the shares that could be disposed of in the year (IL 42 kap. 12 §); 0 when none
     */
    public function __construct(
        public int $sharesOwned,
        public int $sharesTotal,
        public ?int $otherHoldingsBp,
        public int $spouseShares,
        public bool $acquiredDuringPreviousYear,
        public int $companyPayKronor,
        public int $subsidiaryPayKronor,
        public int $capBasisKronor,
        public int $costBasisKronor,
        public int $savedSpaceKronor,
        public int $dividendKronor,
    ) {}
}
