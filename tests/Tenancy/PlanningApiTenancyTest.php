<?php

declare(strict_types=1);

use App\Modules\Connect\Models\ConnectWebhookDelivery;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Planning\Actions\PublishPeriod;
use App\Modules\Planning\Actions\SaveArea;
use App\Modules\Planning\Models\Publication;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\Support\Staffing\StaffingReport;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;
use Tests\Support\Planning;

/*
 * AGENTS.md §13.2 for Planering in the API and Bemanning och kostnad: a
 * credential or a page in company A never sees B's schedule, areas,
 * publications, sales or webhooks, and nothing in the planning API takes
 * another company's id.
 */

beforeEach(function () {
    Mail::fake();
    Connect::setUp();
    Connect::fakeDns(['hooks.example.com' => ['93.184.215.14']]);
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'Europe/Stockholm'));

    foreach (['a' => 'Anna', 'b' => 'Bertil'] as $side => $name) {
        [$owner, $company] = Connect::company();
        CurrentCompany::set($company);
        // The payroll rules and tax tables are shared data, set up once.
        $side === 'a'
            ? Planning::setUp($company, $owner)
            : collect([PayrollModule::KEY, PlanningModule::KEY])->each(fn (string $key) => app(ModuleManager::class)->enable($key, $company, $owner));
        $employee = Planning::employee($owner, $company, $name, $side === 'a' ? '900615123' : '850301234', ['salary_form' => 'HOURLY', 'salary' => '150']);
        app(SaveArea::class)($owner, $company, ['name' => strtoupper($side).':s avdelning', 'colour' => '#2e86ab']);
        Planning::shift($owner, $company, ['employee_id' => $employee->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00']);
        Bookkeeping::post($owner, $company, $side === 'a' ? 100000 : 777700, 1930, 3001, '2026-11-10');
        $this->{$side} = ['owner' => $owner, 'company' => $company];
    }

    CurrentCompany::set($this->a['company']);
    $this->key = Connect::apiKey($this->a['owner'], $this->a['company'], ['planning:view']);
});

function publishFor(array $side): void
{
    CurrentCompany::run($side['company'], fn () => app(PublishPeriod::class)($side['owner'], $side['company'], CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-15')));
}

it('lists only the credential\'s own schedule and areas', function (string $uri, string $foreign) {
    publishFor($this->a);
    publishFor($this->b);

    $response = $this->withToken($this->key)->getJson($uri)->assertOk();

    expect($response->getContent())->not->toContain($foreign)
        ->and($response->json('data'))->toHaveCount(1);
})->with([
    ['/api/v1/planning/shifts?from=2026-11-09&to=2026-11-15&include_drafts=true', 'Bertil'],
    ['/api/v1/planning/shifts?from=2026-11-09&to=2026-11-15', 'Bertil'],
    ['/api/v1/planning/areas', 'B:s avdelning'],
]);

it('lists only the credential\'s own publications', function () {
    publishFor($this->a);
    publishFor($this->b);
    $own = Publication::query()->sole();

    $this->withToken($this->key)->getJson('/api/v1/planning/publications')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
});

it('does not filter its way into another company\'s employees', function () {
    publishFor($this->b);
    $bertil = CurrentCompany::run($this->b['company'], fn () => Employee::query()->sole());

    $this->withToken($this->key)->getJson("/api/v1/planning/shifts?from=2026-11-09&to=2026-11-15&employee_id={$bertil->id}")
        ->assertOk()->assertJsonPath('meta.count', 0);
});

it('takes no id in its paths', function () {
    $paths = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/planning'))
        ->map(fn ($route): string => $route->uri());

    expect($paths)->not->toBeEmpty()
        ->and($paths->filter(fn (string $uri): bool => str_contains($uri, '{')))->toBeEmpty();
});

it('reports only the company\'s own hours and sales on Bemanning och kostnad', function () {
    $data = app(StaffingReport::class)->load($this->a['company'], CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-15'));

    expect($data['totals']['sales'])->toBe(100000)
        ->and($data['totals']['planned_minutes'])->toBe(480)
        ->and($data['areas']->pluck('name')->all())->toBe(['A:s avdelning']);
});

it('sends planning.published only to the publishing company\'s webhooks', function () {
    Http::fake(['https://hooks.example.com/*' => Http::response('', 204)]);
    Connect::webhook($this->b['owner'], $this->b['company'], ['planning.published'], 'https://hooks.example.com/b');

    publishFor($this->a);

    Http::assertNothingSent();
    expect(CompanyScope::bypass(fn () => ConnectWebhookDelivery::query()->count()))->toBe(0);
});
