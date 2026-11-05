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

    /**
     * Plain values for the cache, which unserializes no objects
     * (cache.serializable_classes is false).
     *
     * @return array{name: string, country: string, psuTypes: list<string>, maxConsentDays: int, bic: ?string, beta: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'country' => $this->country,
            'psuTypes' => $this->psuTypes,
            'maxConsentDays' => $this->maxConsentDays,
            'bic' => $this->bic,
            'beta' => $this->beta,
        ];
    }

    /** @param  array{name: string, country: string, psuTypes: list<string>, maxConsentDays: int, bic: ?string, beta: bool}  $values */
    public static function fromArray(array $values): self
    {
        return new self($values['name'], $values['country'], $values['psuTypes'], $values['maxConsentDays'], $values['bic'], $values['beta']);
    }

    public function supports(string $psuType): bool
    {
        return in_array($psuType, $this->psuTypes, true);
    }
}
