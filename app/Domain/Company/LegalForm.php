<?php

declare(strict_types=1);

namespace App\Domain\Company;

enum LegalForm: string
{
    case Ab = 'AB';
    case Ef = 'EF';
    case Hb = 'HB';
    case Kb = 'KB';
    case EkonomiskForening = 'EK_FORENING';
    case IdeellForening = 'IDEELL_FORENING';
    case Stiftelse = 'STIFTELSE';

    public function label(): string
    {
        return match ($this) {
            self::Ab => __('Aktiebolag'),
            self::Ef => __('Enskild firma'),
            self::Hb => __('Handelsbolag'),
            self::Kb => __('Kommanditbolag'),
            self::EkonomiskForening => __('Ekonomisk förening'),
            self::IdeellForening => __('Ideell förening'),
            self::Stiftelse => __('Stiftelse'),
        };
    }
}
