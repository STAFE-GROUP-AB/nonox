<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\Support\Bookkeeping;

function manifest(string $file): array
{
    return json_decode((string) file_get_contents(public_path($file)), true, flags: JSON_THROW_ON_ERROR);
}

it('links the app manifest and the touch icon from the app', function () {
    [$owner] = Bookkeeping::company();

    $this->actingAs($owner)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('rel="manifest" href="'.asset('app.webmanifest').'"', false)
        ->assertSee(asset('brand/apple-touch-icon.png'), false);
});

it('links the app manifest from the public pages', function () {
    $this->get(route('start'))->assertOk()->assertSee('rel="manifest" href="'.asset('app.webmanifest').'"', false);
});

it('describes installable apps whose start pages and icons exist', function (string $file, string $route) {
    $manifest = manifest($file);

    expect($manifest['lang'])->toBe('sv')
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['start_url'])->toBe(route($route, absolute: false));

    $sizes = collect($manifest['icons'])->pluck('sizes')->all();
    expect($sizes)->toContain('192x192', '512x512')
        ->and(collect($manifest['icons'])->pluck('purpose')->all())->toContain('maskable');

    foreach ($manifest['icons'] as $icon) {
        expect(public_path(ltrim($icon['src'], '/')))->toBeFile();
    }

    foreach ($manifest['shortcuts'] ?? [] as $shortcut) {
        expect(collect(Route::getRoutes()->getRoutes())->contains(fn ($r) => '/'.ltrim($r->uri(), '/') === $shortcut['url']))->toBeTrue();
    }
})->with([
    'the app' => ['app.webmanifest', 'dashboard'],
    'Min sida' => ['anstalld.webmanifest', 'payroll.portal.home'],
]);

it('keeps the service worker to an offline page and caches no data', function () {
    $worker = (string) file_get_contents(public_path('sw.js'));

    expect(public_path('offline.html'))->toBeFile()
        ->and($worker)->toContain("event.request.mode !== 'navigate'")
        ->and($worker)->not->toContain('cache.put')
        ->and((string) file_get_contents(public_path('offline.html')))->toContain('Ingen anslutning');
});
