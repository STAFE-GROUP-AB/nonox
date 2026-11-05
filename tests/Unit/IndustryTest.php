<?php

declare(strict_types=1);

use App\Domain\Company\Industry;
use App\Support\Landing\Industries;

/*
 * The industries a company can choose (ADR-051) and the public industry
 * pages in docs/branscher: every page has exactly one industry, and every
 * industry but "Annan bransch" has a page.
 */

it('maps every industry page to exactly one industry', function () {
    foreach (Industries::slugs() as $slug) {
        $cases = array_filter(Industry::cases(), fn (Industry $industry): bool => $industry->landingSlug() === $slug);

        expect($cases)->toHaveCount(1, "docs/branscher/{$slug}.json has no industry, or more than one.");
    }
});

it('has a page for every industry but Annan bransch', function () {
    foreach (Industry::cases() as $industry) {
        $industry === Industry::Other
            ? expect($industry->landingSlug())->toBeNull()
            : expect(Industries::find((string) $industry->landingSlug()))->not->toBeNull("{$industry->value} points at a missing industry page.");
    }

    expect(Industry::guideKeys())->toContain('other')
        ->and(Industry::Other->label())->toBe('Annan bransch');
});
