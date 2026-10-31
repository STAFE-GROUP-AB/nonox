<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Build\BuildInfo;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

afterEach(fn () => BuildInfo::fake(null));

it('counts the commits on GitHub as the build number and writes it for the release', function () {
    $sha = str_repeat('a1', 20);
    $path = base_path(BuildInfo::FILE);
    $before = is_file($path) ? File::get($path) : null;

    Http::fake(['api.github.com/*' => Http::response([['sha' => $sha]], 200, [
        'Link' => '<https://api.github.com/repositories/1/commits?sha='.$sha.'&per_page=1&page=2>; rel="next", <https://api.github.com/repositories/1/commits?sha='.$sha.'&per_page=1&page=117>; rel="last"',
    ])]);

    try {
        $this->artisan('nonox:build-info', ['--sha' => $sha])->assertSuccessful();
        BuildInfo::fake(null);

        expect(BuildInfo::build())->toBe(117)
            ->and(BuildInfo::version())->toBe(config('app.version').'.117')
            ->and(BuildInfo::sha())->toBe('a1a1a1a');
    } finally {
        $before === null ? File::delete($path) : File::put($path, $before);
    }
});

it('shows the version and the open beta on the landing page, the sign-in page and inside the app', function () {
    BuildInfo::fake(['build' => 42, 'sha' => str_repeat('b2', 20), 'built_at' => '2026-11-01T10:00:00+01:00']);
    $version = config('app.version').'.42';

    $this->get('/')
        ->assertSee("Version {$version}")
        ->assertSee('Öppen beta')
        ->assertSee('Alla kan registrera sig och börja bokföra redan i dag.')
        ->assertSee('b2b2b2b');

    $this->get(route('login'))->assertSee("NONOX Version {$version} · Öppen beta · b2b2b2b");

    $this->actingAs(User::factory()->withCompany()->create())
        ->get(route('dashboard'))
        ->assertSee("NONOX Version {$version}");
});
