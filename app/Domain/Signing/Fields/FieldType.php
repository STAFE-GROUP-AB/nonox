<?php

declare(strict_types=1);

namespace App\Domain\Signing\Fields;

/**
 * The kinds of field a signer fills in (ADR-046 amendment, gap 2). A date
 * and a name are filled in by NONOX (the signing date in Stockholm time, the
 * name the signer types), a signature and initials by the signer's pad or
 * typed alternative; the rest the signer enters.
 */
enum FieldType: string
{
    case Signature = 'SIGNATURE';
    case Initials = 'INITIALS';
    case Date = 'DATE';
    case Name = 'NAME';
    case Text = 'TEXT';
    case Number = 'NUMBER';
    case Checkbox = 'CHECKBOX';
    case Dropdown = 'DROPDOWN';
    case Radio = 'RADIO';

    public function label(): string
    {
        return match ($this) {
            self::Signature => __('Underskrift'),
            self::Initials => __('Initialer'),
            self::Date => __('Datum'),
            self::Name => __('Namn'),
            self::Text => __('Text'),
            self::Number => __('Tal'),
            self::Checkbox => __('Kryssruta'),
            self::Dropdown => __('Lista'),
            self::Radio => __('Alternativ'),
        };
    }

    /** Filled in by NONOX, not typed by the signer. */
    public function isAutomatic(): bool
    {
        return in_array($this, [self::Date, self::Name], true);
    }

    /** Filled in from the signer's signature or initials pad. */
    public function isMark(): bool
    {
        return in_array($this, [self::Signature, self::Initials], true);
    }

    public function hasOptions(): bool
    {
        return in_array($this, [self::Dropdown, self::Radio], true);
    }
}
