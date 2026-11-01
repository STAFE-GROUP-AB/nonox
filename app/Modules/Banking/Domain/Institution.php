<?php

declare(strict_types=1);

namespace App\Modules\Banking\Domain;

/**
 * A bank a company can connect, as the provider lists it. The longest
 * consent the bank accepts is in days (PSD2: 180 where the bank allows it,
 * otherwise often 90).
 */
final readonly class Institution
{
    /**
     * @param  list<string>  $psuTypes  'business' and/or 'personal'
     */
    public function __construct(
        public string $name,
        public string $country,
        public array $psuTypes,
        public int $maxConsentDays,
        public ?string $bic = null,
        public bool $beta = false,
    ) {}

    public function supports(string $psuType): bool
    {
        return in_array($psuType, $this->psuTypes, true);
    }
}
