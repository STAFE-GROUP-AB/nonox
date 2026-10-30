<?php

declare(strict_types=1);

use App\Support\Branding\Brand;
use Tests\Support\Bookkeeping;

it('keeps the NONOX colours until the company chooses its own', function () {
    [$owner, $company] = Bookkeeping::company();
    $this->actingAs($owner);

    expect(Brand::interfacePalette($company))->toBeNull();
    $this->get(route('dashboard'))->assertOk()->assertDontSee('--accent-500:', false);

    $company->forceFill(['brand_color' => '#1E6B52'])->save();
    $this->actingAs($owner->fresh());

    $this->get(route('dashboard'))->assertOk()->assertSee('--accent-500: 30 107 82;', false);
});

it('keeps button and link text readable whatever colour the company chooses', function (string $color) {
    [, $company] = Bookkeeping::company();
    $company->forceFill(['brand_color' => $color])->save();
    $palette = Brand::interfacePalette($company);
    $hex = fn (string $rgb): string => sprintf('#%02X%02X%02X', ...array_map('intval', explode(' ', $rgb)));

    expect(Brand::contrast($hex($palette[600]), '#FFFFFF'))->toBeGreaterThanOrEqual(4.5)
        ->and(Brand::contrast($hex($palette[700]), '#FFFFFF'))->toBeGreaterThanOrEqual(4.5)
        ->and($palette[500])->toBe(implode(' ', [hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2))]));
})->with(['dark green' => '#1E6B52', 'light yellow' => '#FFE14D', 'pale pink' => '#FADADD', 'black' => '#000000']);
