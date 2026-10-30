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
        ->and(Brand::contrast($hex($palette[500]), '#FFFFFF'))->toBeGreaterThanOrEqual(3.0);
})->with(['dark green' => '#1E6B52', 'light yellow' => '#FFE14D', 'pale pink' => '#E8A0A8', 'black' => '#000000']);

it('prefers the accent colour and passes over a nearly white main colour', function () {
    [, $company] = Bookkeeping::company();

    $company->forceFill(['brand_color' => '#F6F4F0', 'brand_accent_color' => '#FD5532'])->save();
    expect(Brand::interfacePalette($company)[50])->not->toBeNull()
        ->and(Brand::interfacePalette($company)[600])->not->toBe('246 244 240');

    $company->forceFill(['brand_color' => '#1E6B52', 'brand_accent_color' => null])->save();
    expect(Brand::interfacePalette($company)[500])->toBe('30 107 82');

    $company->forceFill(['brand_color' => '#F6F4F0', 'brand_accent_color' => '#FFFFFF'])->save();
    expect(Brand::interfacePalette($company))->toBeNull();
});
