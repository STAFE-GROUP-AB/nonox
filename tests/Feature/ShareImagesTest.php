<?php

declare(strict_types=1);

use App\Support\Landing\ShareCards;

it('has a rendered share image for every public page that has a card', function () {
    foreach (array_keys(ShareCards::all()) as $key) {
        $file = public_path(ShareCards::DIRECTORY."/{$key}.png");

        expect($file)->toBeFile("Run `php artisan og:images` to draw {$key}.png.");

        [$width, $height] = getimagesize($file) ?: [0, 0];
        expect([$width, $height])->toBe([1200, 630]);
    }
});

it('links the page its own share image, and the start image otherwise', function () {
    $this->get('/')->assertOk()
        ->assertSee('property="og:image" content="'.asset('og/start.png'), false)
        ->assertSee('name="twitter:card" content="summary_large_image"', false);

    $this->get('/bokforing-for-restauranger')->assertOk()
        ->assertSee(asset('og/bokforing-for-restauranger.png'), false);

    $this->get('/egen-drift')->assertOk()
        ->assertSee(asset('og/start.png'), false);
});
