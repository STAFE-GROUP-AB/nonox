<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * S1-R5, K3 20.29: in the legal entity all finance leases may be accounted for
 * as operating leases. "Alla": one company-wide choice, never per contract.
 * S1 supports that choice only.
 */
enum K3Leasing: string implements K3PolicyValue
{
    case OperationalInLegalEntity = 'operational_in_legal_entity';
    case Classify = 'classify';

    public function refusal(): ?string
    {
        return match ($this) {
            self::OperationalInLegalEntity => null,
            self::Classify => (string) __('NONOX stöder bara att alla finansiella leasingavtal redovisas som operationella leasingavtal i juridisk person (K3 20.29). Finansiella leasingavtal i balansräkningen stöds inte.'),
        };
    }
}
