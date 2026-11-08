<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

/**
 * Who holds shares: a natural person, a legal entity, or the company itself
 * (own shares, and the surplus of a reverse split, ABL 4 kap. 50 §).
 */
enum HolderKind: string
{
    case Person = 'PERSON';
    case Entity = 'ENTITY';
    case CompanyItself = 'COMPANY_ITSELF';

    public function label(): string
    {
        return match ($this) {
            self::Person => __('Fysisk person'),
            self::Entity => __('Juridisk person'),
            self::CompanyItself => __('Bolaget självt'),
        };
    }
}
