<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/**
 * The built-in categories of the document archive. A company may also use
 * its own: any other text is a category of the company's own list, which is
 * the categories its documents already use.
 */
enum DocumentCategory: string
{
    case Agreement = 'AGREEMENT';
    case Insurance = 'INSURANCE';
    case Permit = 'PERMIT';
    case Policy = 'POLICY';
    case Certificate = 'CERTIFICATE';
    case Correspondence = 'CORRESPONDENCE';
    case Accounting = 'ACCOUNTING';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Agreement => __('Avtal'),
            self::Insurance => __('Försäkring'),
            self::Permit => __('Tillstånd'),
            self::Policy => __('Policy och rutin'),
            self::Certificate => __('Intyg och certifikat'),
            self::Correspondence => __('Brev och korrespondens'),
            self::Accounting => __('Bokföring och skatt'),
            self::Other => __('Övrigt'),
        };
    }

    /** The label of a stored category: a built-in key, or the company's own text. */
    public static function labelOf(string $category): string
    {
        return self::tryFrom($category)?->label() ?? $category;
    }
}
