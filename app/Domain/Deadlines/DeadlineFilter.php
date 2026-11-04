<?php

declare(strict_types=1);

namespace App\Domain\Deadlines;

/** The filter chips on Mina datum (?visa=…, ADR-051): a GET link each, so they work without JavaScript. */
enum DeadlineFilter: string
{
    case Vat = 'moms';
    case Tax = 'skatt';
    case Employer = 'arbetsgivare';
    case Bolagsverket = 'bolagsverket';
    case Closing = 'bokslut';

    public function label(): string
    {
        return match ($this) {
            self::Vat => __('Moms'),
            self::Tax => __('Skatt'),
            self::Employer => __('Arbetsgivare'),
            self::Bolagsverket => __('Bolagsverket'),
            self::Closing => __('Bokslut'),
        };
    }

    public function matches(Deadline $deadline): bool
    {
        return DeadlineCategory::of($deadline)->filter() === $this;
    }
}
