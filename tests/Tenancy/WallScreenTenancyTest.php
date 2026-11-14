<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Actions\Workshop\WorkshopDevices;
use App\Modules\Manufacturing\Domain\DeviceKind;
use App\Modules\Manufacturing\Domain\DowntimeReason;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Livewire\Workshop\WallScreenAdminPage;
use App\Modules\Manufacturing\Models\Downtime;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\ProductionPlanner;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Manufacturing;
use Tests\Support\PlanningWorkshop;

/*
 * AGENTS.md §13.2 for the workshop wall screen (ADR-071, M7): a screen
 * paired to company A shows only A's plan, the preview shows only the
 * signed-in user's company, and A's setup page never lists or acts on B's
 * screens; B's ids answer "not found".
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 09:30', Clock::ZONE));

    foreach (['a', 'b'] as $side) {
        ['owner' => $owner, 'company' => $company] = Manufacturing::company();
        $this->actingAs($owner);
        $shop = PlanningWorkshop::build($company, $owner);

        if ($side === 'b') {
            CurrentCompany::run($company, fn () => Downtime::create([
                'workstation_id' => $shop['lackbox']->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3), 'reason' => DowntimeReason::Breakdown, 'source' => Downtime::MANUAL,
            ]));
            // B's products have names of their own.
            foreach ($shop['orders'] as $order) {
                CurrentCompany::run($company, fn () => $order->article->forceFill(['name' => "B-{$order->article->name}"])->save());
            }
        }

        app(ProductionPlanner::class)->run($company, PlanTrigger::Manual, CarbonImmutable::parse('2026-11-16 07:00', Clock::ZONE));
        $devices = app(WorkshopDevices::class);
        [$screen, $code] = $devices->register($owner, $company, "Skärm {$side}", DeviceKind::WallScreen);
        CurrentCompany::forget();
        [, $token] = $devices->pair($code, DeviceKind::WallScreen);
        $this->{$side} = compact('owner', 'company', 'screen', 'token');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('shows a screen only its own company\'s plan', function () {
    CurrentCompany::forget();
    $a = (string) $this->withToken($this->a['token'])->getJson(route('manufacturing.wallScreen.board'))->assertOk()->assertJsonPath('company', $this->a['company']->name)->getContent();
    CurrentCompany::forget();
    $b = (string) $this->withToken($this->b['token'])->getJson(route('manufacturing.wallScreen.board'))->assertOk()->assertJsonPath('company', $this->b['company']->name)->getContent();

    expect($a)->not->toContain('B-Byr')->not->toContain('Lackbox står')
        ->and(json_decode($a, true)['stopped'])->toBe([])
        ->and($b)->toContain('B-Byr')
        ->and(json_decode($b, true)['stopped'])->not->toBe([]);
});

it('previews only the signed-in user\'s company', function () {
    $json = (string) $this->getJson(route('manufacturing.wallScreen.preview.board'))->assertOk()->assertJsonPath('company', $this->a['company']->name)->getContent();

    expect($json)->not->toContain('B-Byr');
});

it('never lists or acts on another company\'s screens', function () {
    Livewire::test(WallScreenAdminPage::class)->assertSee('Skärm a')->assertDontSee('Skärm b');

    foreach (['renewCode', 'revokeScreen', 'removeScreen'] as $method) {
        Livewire::test(WallScreenAdminPage::class)->call($method, $this->b['screen']->id)->assertNotFound();
    }

    $stored = CompanyScope::bypass(fn () => WorkshopDevice::query()->find($this->b['screen']->id));
    expect($stored)->not->toBeNull()->and($stored->revoked_at)->toBeNull()->and($stored->token_hash)->not->toBeNull();
});
