<?php

declare(strict_types=1);

use App\Modules\Connect\Models\ConnectWebhookDelivery;
use App\Modules\ModuleManager;
use App\Modules\Planning\Actions\PublishPeriod;
use App\Modules\Planning\Actions\SaveArea;
use App\Modules\Planning\Actions\SaveShift;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\Support\PlanningWebhooks;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Connect;
use Tests\Support\Planning;

/*
 * Planering in NONOX Connect (PLANNING_MODULE.md §9, ADR-032): the
 * published schedule per period with planning:view, and the webhooks
 * planning.published and planning.attested.
 */

beforeEach(function () {
    Mail::fake();
    Connect::setUp();
    Connect::fakeDns(['hooks.example.com' => ['93.184.215.14']]);
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'Europe/Stockholm'));
    [$this->owner, $this->company] = Connect::company();
    Planning::setUp($this->company, $this->owner);
    $this->anna = Planning::employee($this->owner, $this->company, 'Anna', '900615123', ['salary_form' => 'HOURLY', 'salary' => '150']);
    $this->area = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Kök', 'colour' => '#2e86ab']);
    $this->key = Connect::apiKey($this->owner, $this->company, ['planning:view']);
});

function publishWeek(object $test): void
{
    CurrentCompany::run($test->company, fn () => app(PublishPeriod::class)($test->owner, $test->company, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-15')));
}

it('lists the published schedule as the employees were shown it', function () {
    $published = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $this->area->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00', 'break_minutes' => 30]);
    $open = Planning::shift($this->owner, $this->company, ['date' => '2026-11-11', 'start' => '10:00', 'end' => '14:00']);
    publishWeek($this);

    // Changed after publishing: the API still gives the published version. A new draft is left out.
    CurrentCompany::run($this->company, fn () => app(SaveShift::class)($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $this->area->id, 'date' => '2026-11-10', 'start' => '09:00', 'end' => '17:00', 'break_minutes' => 30], Shift::query()->findOrFail($published->id)));
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-12', 'start' => '08:00', 'end' => '12:00']);

    $response = $this->withToken($this->key)->getJson('/api/v1/planning/shifts?from=2026-11-09&to=2026-11-15')->assertOk();

    expect($response->json('meta'))->toBe(['from' => '2026-11-09', 'to' => '2026-11-15', 'include_drafts' => false, 'count' => 2])
        ->and($response->json('data.0'))->toMatchArray([
            'id' => $published->id,
            'day' => '2026-11-10',
            'employee_id' => $this->anna->id,
            'employee_name' => 'Anna Kock',
            'area_id' => $this->area->id,
            'starts_at' => '2026-11-10T08:00:00+01:00',
            'ends_at' => '2026-11-10T16:00:00+01:00',
            'break_minutes' => 30,
            'worked_minutes' => 450,
            'status' => 'published',
            'has_unpublished_changes' => true,
            'is_open' => false,
        ])
        ->and($response->json('data.1'))->toMatchArray(['id' => $open->id, 'is_open' => true, 'employee_id' => null, 'employee_name' => null])
        ->and($response->getContent())->not->toContain('cost');

    $drafts = $this->withToken($this->key)->getJson('/api/v1/planning/shifts?from=2026-11-09&to=2026-11-15&include_drafts=true')->assertOk();
    expect($drafts->json('meta.count'))->toBe(3)
        ->and($drafts->json('data.0.starts_at'))->toBe('2026-11-10T09:00:00+01:00')
        ->and($drafts->json('data.0.status'))->toBe('draft')
        ->and($drafts->json('data.2.status'))->toBe('draft');

    $this->withToken($this->key)->getJson("/api/v1/planning/shifts?from=2026-11-09&to=2026-11-15&area_id={$this->area->id}")->assertOk()->assertJsonPath('meta.count', 1);
    $this->withToken($this->key)->getJson("/api/v1/planning/shifts?from=2026-11-09&to=2026-11-15&employee_id={$this->anna->id}")->assertOk()->assertJsonPath('meta.count', 1);
});

it('lists areas and publications', function () {
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00']);
    publishWeek($this);

    $this->withToken($this->key)->getJson('/api/v1/planning/areas')->assertOk()
        ->assertJsonPath('data.0.name', 'Kök')->assertJsonPath('data.0.is_archived', false)->assertJsonPath('meta.has_more', false);

    $this->withToken($this->key)->getJson('/api/v1/planning/publications')->assertOk()
        ->assertJsonPath('data.0.from', '2026-11-09')->assertJsonPath('data.0.to', '2026-11-15')->assertJsonPath('data.0.shift_count', 1);
});

it('asks for a period of at most 93 days', function () {
    $this->withToken($this->key)->getJson('/api/v1/planning/shifts')->assertStatus(422);
    $this->withToken($this->key)->getJson('/api/v1/planning/shifts?from=2026-11-15&to=2026-11-09')->assertStatus(422);
    $this->withToken($this->key)->getJson('/api/v1/planning/shifts?from=2026-01-01&to=2026-04-30')->assertStatus(422);
    $this->withToken($this->key)->getJson('/api/v1/planning/shifts?from=2026-01-01&to=2026-04-03')->assertOk();
});

it('needs planning:view, and planning:view is gone while the module is off', function () {
    $other = Connect::apiKey($this->owner, $this->company, ['vouchers:view']);
    $this->withToken($other)->getJson('/api/v1/planning/shifts?from=2026-11-09&to=2026-11-15')->assertForbidden()->assertJsonPath('code', 'insufficient_scope');

    app(ModuleManager::class)->disable(PlanningModule::KEY, $this->company, $this->owner, confirmed: true);
    $this->withToken($this->key)->getJson('/api/v1/planning/areas')->assertForbidden();
});

it('sends planning.published after the publication commits', function () {
    Http::fake(['https://hooks.example.com/*' => Http::response('', 204)]);
    Connect::webhook($this->owner, $this->company, ['planning.published']);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00']);

    publishWeek($this);

    Http::assertSentCount(1);
    $body = json_decode((string) CompanyScope::bypass(fn () => ConnectWebhookDelivery::query()->where('company_id', $this->company->id)->sole()->body), true);

    expect($body['type'])->toBe('planning.published')
        ->and($body['company_id'])->toBe($this->company->id)
        ->and($body['data'])->toMatchArray(['from' => '2026-11-09', 'to' => '2026-11-15', 'shift_count' => 1])
        ->and($body['data'])->toHaveKeys(['publication_id', 'published_at']);
});

it('sends planning.attested from the helper, only once the transaction commits', function () {
    Http::fake(['https://hooks.example.com/*' => Http::response('', 204)]);
    Connect::webhook($this->owner, $this->company, ['planning.attested']);

    try {
        DB::transaction(function (): void {
            PlanningWebhooks::attested($this->company, CarbonImmutable::parse('2026-11-08'), CarbonImmutable::parse('2026-11-02'), [11, 12], [$this->anna->id], 480);

            throw new RuntimeException('Avbruten');
        });
    } catch (RuntimeException) {
    }

    Http::assertNothingSent();

    DB::transaction(fn () => PlanningWebhooks::attested($this->company, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-08'), [11, 12], [$this->anna->id, $this->anna->id], 480));

    Http::assertSentCount(1);
    $body = json_decode((string) CompanyScope::bypass(fn () => ConnectWebhookDelivery::query()->where('company_id', $this->company->id)->sole()->body), true);

    expect($body['type'])->toBe('planning.attested')
        ->and($body['data'])->toBe(['from' => '2026-11-02', 'to' => '2026-11-08', 'attestation_ids' => [11, 12], 'employee_ids' => [$this->anna->id], 'attested_minutes' => 480]);
});
