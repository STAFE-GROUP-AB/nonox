<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Where a change of an article's base price came from, kept in
 * article_price_history (ADR-082 §1).
 */
enum PriceChangeSource: string
{
    case Form = 'FORM';
    case Api = 'API';
    case Import = 'IMPORT';
    case PriceUpdate = 'PRICE_UPDATE';
}
