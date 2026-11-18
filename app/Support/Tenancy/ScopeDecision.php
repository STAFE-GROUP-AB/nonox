<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * What a route or Livewire component does for a member limited to cost centres
 * or projects (ADR-081 gap 10, DimensionScopeRegistry).
 */
enum ScopeDecision: string
{
    /** The page applies DimensionScope to everything it shows and saves. */
    case Scoped = 'scoped';

    /** The page holds nothing of the company's books: the profile, signing out, the manual. */
    case Neutral = 'neutral';

    /** The start page sends a limited member to the first page they may use. */
    case Landing = 'landing';

    /** A limited member gets 404, as for another company's record. */
    case Refused = 'refused';
}
