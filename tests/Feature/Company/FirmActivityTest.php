<?php

declare(strict_types=1);

use App\Actions\Company\SaveClientProfile;
use App\Domain\Company\FirmActivity;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
});

it('shows the client what the firm has done, day by day', function () {
    $consultant = Bookkeeping::member($this->company, 'accountant');
    $consultant->forceFill(['name' => 'Anna Konsult'])->save();
    app(SaveClientProfile::class)->assign($consultant, $this->company, $consultant->id);
    $this->actingAs($consultant);
    Bookkeeping::post($consultant, $this->company, 10_000, 1930, 3001, '2026-03-05');
    Bookkeeping::post($consultant, $this->company, 20_000, 1930, 3001, '2026-03-06');
    $this->travelTo(CarbonImmutable::parse('2026-04-09 15:00:00'));
    Bookkeeping::post($consultant, $this->company, 30_000, 1930, 3001, '2026-03-07');
    Bookkeeping::post($this->owner, $this->company, 40_000, 1930, 3001, '2026-03-08');
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));

    $firm = FirmActivity::for($this->company);

    expect($firm['responsible'])->toBe('Anna Konsult')
        ->and($firm['days'][0])->toBe(['date' => '2026-04-10', 'entries' => [['name' => 'Anna Konsult', 'label' => 'Bokförde verifikationer', 'count' => 2]]])
        ->and($firm['days'][1]['date'])->toBe('2026-04-09')
        ->and($firm['days'][1]['entries'][0]['count'])->toBe(1);

    $this->actingAs($this->owner->fresh());
    $this->get(route('dashboard'))->assertOk()
        ->assertSee('Vad byrån har gjort')
        ->assertSee('Anna Konsult')
        ->assertSee('I dag')
        ->assertSee('I går')
        ->assertSee('Bokförde verifikationer');

    // The consultant does not see the panel about their own work.
    $this->actingAs($consultant->fresh());
    $this->get(route('dashboard'))->assertOk()->assertDontSee('Vad byrån har gjort');
});

it('shows nothing when no consultant works in the company', function () {
    expect(FirmActivity::for($this->company))->toBeNull();

    $this->actingAs($this->owner);
    $this->get(route('dashboard'))->assertOk()->assertDontSee('Vad byrån har gjort');
});
