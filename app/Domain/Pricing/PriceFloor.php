<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\Company;

/**
 * A company's lowest contribution margin (lägsta täckningsgrad) and what a
 * manual price under it does (ADR-082 §9, decision 12): off by default, so a
 * small company is never stopped; on, it warns, and the company may also
 * require that someone with prices:approve-below-floor approves the
 * document first.
 */
final readonly class PriceFloor
{
    public function __construct(
        public ?int $minimumBp,
        public PriceFloorPolicy $policy,
    ) {}

    public static function of(Company $company): self
    {
        return new self($company->min_contribution_bp, $company->price_floor_policy);
    }

    public function isOn(): bool
    {
        return $this->minimumBp !== null;
    }

    public function requiresApproval(): bool
    {
        return $this->isOn() && $this->policy === PriceFloorPolicy::RequireApproval;
    }

    /** "15 %" or "12,5 %". */
    public function label(): string
    {
        return self::percent((int) $this->minimumBp);
    }

    public static function percent(int $bp): string
    {
        $sign = $bp < 0 ? '-' : '';
        $bp = abs($bp);
        $whole = intdiv($bp, 100);
        $rest = $bp % 100;

        return $sign.($rest === 0 ? (string) $whole : $whole.','.rtrim(str_pad((string) $rest, 2, '0', STR_PAD_LEFT), '0')).' %';
    }
}
