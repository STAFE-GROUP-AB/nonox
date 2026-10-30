<?php

declare(strict_types=1);

use App\Actions\Company\UpdateCompanyBranding;
use App\Livewire\Company\BrandSettings;
use App\Support\Branding\Brand;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    Storage::fake(Brand::DISK);
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->brand = app(UpdateCompanyBranding::class);
});

it('saves the logo privately under the company and the colours in upper case', function () {
    ($this->brand)($this->owner, $this->company, ['brand_color' => '#1f4e79', 'brand_accent_color' => ''], UploadedFile::fake()->image('../../evil name.png', 400, 120));

    $company = $this->company->fresh();

    expect($company->brand_color)->toBe('#1F4E79')
        ->and($company->brand_accent_color)->toBeNull()
        ->and($company->logo_path)->toStartWith("company-logos/{$company->id}/logo-")->toEndWith('.png')
        ->and(Storage::disk(Brand::DISK)->exists($company->logo_path))->toBeTrue()
        ->and(Brand::for($company)->accent)->toBe('#1F4E79')
        ->and(Brand::for($company)->logoDataUri())->toStartWith('data:image/png;base64,');
});

it('replaces and removes the logo, deleting the old file', function () {
    ($this->brand)($this->owner, $this->company, [], UploadedFile::fake()->image('a.png', 200, 80));
    $first = $this->company->fresh()->logo_path;

    ($this->brand)($this->owner, $this->company->fresh(), [], UploadedFile::fake()->image('b.jpg', 200, 80));
    $second = $this->company->fresh()->logo_path;

    expect(Storage::disk(Brand::DISK)->exists($first))->toBeFalse()
        ->and(Storage::disk(Brand::DISK)->exists($second))->toBeTrue();

    ($this->brand)($this->owner, $this->company->fresh(), [], removeLogo: true);

    expect($this->company->fresh()->logo_path)->toBeNull()
        ->and(Storage::disk(Brand::DISK)->exists($second))->toBeFalse();
});

it('refuses SVG, wrong colours and members who may not change the company', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(fn () => ($this->brand)($this->owner, $this->company, [], UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')))->toThrow(ValidationException::class, 'PNG-, JPEG- eller WebP')
        ->and(fn () => ($this->brand)($this->owner, $this->company, ['brand_color' => 'blue']))->toThrow(ValidationException::class, 'hexkod')
        ->and(fn () => ($this->brand)($bookkeeper, $this->company, ['brand_color' => '#000000']))->toThrow(AuthorizationException::class);
});

it('shows the logo to the company\'s members only', function () {
    ($this->brand)($this->owner, $this->company, [], UploadedFile::fake()->image('a.png', 200, 80));
    [$stranger] = Bookkeeping::company();

    $this->actingAs($this->owner)->get(route('teams.logo', $this->company->id))->assertOk();
    $this->actingAs($stranger)->get(route('teams.logo', $this->company->id))->assertNotFound();
});

it('picks readable text on the main colour', function () {
    expect((new Brand('#15171C', '#15171C', null))->onColor())->toBe('#FFFFFF')
        ->and((new Brand('#FFE066', '#FFE066', null))->onColor())->toBe('#000000')
        ->and((new Brand('#000000', '#000000', null))->tint(0.5))->toBe('#808080');
});

it('has a Varumärke tab with a live preview', function () {
    $this->actingAs($this->owner)->get(route('teams.show', ['company' => $this->company->id, 'flik' => 'varumarke']))
        ->assertOk()
        ->assertSee('Huvudfärg')
        ->assertSee('Så här ser en faktura ut');

    Livewire::test(BrandSettings::class, ['company' => $this->company])
        ->set('color', '#1F4E79')
        ->assertSee('background: #1F4E79', false)
        ->call('save')
        ->assertDispatched('toast');

    expect($this->company->fresh()->brand_color)->toBe('#1F4E79');
});
