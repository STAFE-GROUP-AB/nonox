<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use BackedEnum;

/**
 * A value of one K3 accounting policy (ADR-059, addendum 2026-11-07, A.2.3).
 * refusal() is null for a value NONOX supports in S1, otherwise the Swedish
 * reason the K3 annual report cannot be made with it.
 */
interface K3PolicyValue extends BackedEnum
{
    public function refusal(): ?string;
}
