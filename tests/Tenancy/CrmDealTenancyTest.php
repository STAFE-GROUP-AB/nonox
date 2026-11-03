<?php

declare(strict_types=1);

use App\Modules\Crm\Actions\CreateWinBackTasks;
use App\Modules\Crm\Actions\LoseDeal;
use App\Modules\Crm\Actions\SaveGoal;
use App\Modules\Crm\Domain\Forecast;
use App\Modules\Crm\Domain\GoalProgress;
use App\Modules\Crm\Domain\PipelineFigures;
use App\Modules\Crm\Livewire\DealCard;
use App\Modules\Crm\Livewire\DealsBoard;
use App\Modules\Crm\Livewire\ForecastPage;
use App\Modules\Crm\Livewire\PipelineSettings;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Goal;
use App\Modules\Crm\Models\LossReason;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Connect;
use Tests\Support\Crm;

/*
 * AGENTS.md §13.2 for CRM stage 2 (ADR-043): company A never sees B's
 * deals, pipelines, stages, loss reasons or goals, through Eloquent, the
 * figures, the pages, the API or the win-back command; B's ids answer 404.
 */

beforeEach(function () {
    Connect::setUp();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Connect::company();
        $this->actingAs($owner);
        Crm::enable($company, $owner);
        $account = Crm::prospect($company, $owner, "{$name} Prospekt AB");
        $deal = Crm::deal($company, $owner, $account, "{$name}affären", 1_000_000, ['expected_close_date' => '2026-07-15']);
        $lost = Crm::deal($company, $owner, $account, "{$name}förlust");
        app(LoseDeal::class)($owner, $company, $lost, ['loss_reason_id' => LossReason::query()->value('id'), 'win_back_at' => '2026-06-20']);
        $goal = app(SaveGoal::class)($owner, $company, ['user_id' => $owner->id, 'month' => '2026-06', 'metric' => 'MEETINGS', 'target' => '5']);
        $pipeline = Crm::pipeline();
        $pipeline->forceFill(['name' => "{$name}tratten"])->save();
        $stage = Crm::stage(1);
        $reason = LossReason::query()->firstOrFail();
        $this->{$side} = compact('owner', 'company', 'account', 'deal', 'lost', 'goal', 'pipeline', 'stage', 'reason');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('never shows B\'s deals, pipelines, stages, reasons or goals through Eloquent or the figures', function () {
    expect(Deal::query()->pluck('title')->sort()->values()->all())->toBe(['Alfaaffären', 'Alfaförlust'])
        ->and(Pipeline::query()->pluck('name')->all())->toBe(['Alfatratten'])
        ->and(PipelineStage::query()->count())->toBe(4)
        ->and(LossReason::query()->count())->toBe(6)
        ->and(Goal::query()->pluck('id')->all())->toBe([$this->a['goal']?->id])
        ->and(app(PipelineFigures::class)->open())->toBe(['count' => 1, 'value' => 1_000_000, 'weighted' => 100_000])
        ->and(app(PipelineFigures::class)->stages($this->b['pipeline']->id))->toBe([])
        ->and(collect(app(Forecast::class)->for(CarbonImmutable::now())['months'])->sum('value'))->toBe(1_000_000)
        ->and(app(GoalProgress::class)->for(CarbonImmutable::parse('2026-06-01')))->toHaveCount(1);
});

it('answers 404 for B\'s deal on the pages and refuses B\'s ids in A\'s forms', function () {
    $this->get(route('crm.deals'))->assertOk()->assertSee('Alfaaffären')->assertDontSee('Betaaffären');
    $this->get(route('crm.deals.show', $this->a['deal']))->assertOk();
    $this->get(route('crm.deals.show', $this->b['deal']))->assertNotFound();
    $this->get(route('crm.forecast'))->assertOk();

    Livewire::test(DealsBoard::class)->call('move', $this->b['deal']->id, $this->a['stage']->id)->assertNotFound();
    Livewire::test(DealsBoard::class)->call('move', $this->a['deal']->id, $this->b['stage']->id)->assertDispatched('toast');
    Livewire::test(DealsBoard::class)->call('pickAccount', $this->b['account']->id)->assertNotFound();
    Livewire::test(DealCard::class, ['dealId' => $this->a['deal']->id])->call('moveTo', $this->b['stage']->id);
    Livewire::test(PipelineSettings::class)->call('startPipeline', $this->b['pipeline']->id)->assertNotFound();
    Livewire::test(ForecastPage::class)->call('removeGoal', $this->b['goal']->id)->assertNotFound();
    Livewire::withQueryParams(['saljtratt' => (string) $this->b['pipeline']->id])->test(DealsBoard::class)->assertSee('Alfaaffären')->assertDontSee('Betaaffären');

    expect($this->a['deal']->fresh()?->stage_id)->toBe(Crm::stage(0)->id);

    CurrentCompany::set($this->b['company']);
    expect($this->b['deal']->fresh()?->stage_id)->toBe(Crm::stage(0)->id);
});

it('shows only A\'s deals, pipelines and reasons over the API, and 404 for B\'s ids', function () {
    $key = Connect::apiKey($this->a['owner'], $this->a['company'], ['crm:view', 'crm:manage']);

    $this->withToken($key)->getJson('/api/v1/crm/deals')->assertOk()->assertJsonCount(2, 'data');
    $this->withToken($key)->getJson('/api/v1/crm/pipelines')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Alfatratten');
    $this->withToken($key)->getJson('/api/v1/crm/stages')->assertOk()->assertJsonCount(4, 'data');
    $this->withToken($key)->getJson('/api/v1/crm/loss-reasons')->assertOk()->assertJsonCount(6, 'data');
    $this->withToken($key)->getJson("/api/v1/crm/deals/{$this->b['deal']->id}")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/crm/pipelines/{$this->b['pipeline']->id}")->assertNotFound();
    $this->withToken($key)->patchJson("/api/v1/crm/deals/{$this->b['deal']->id}", ['title' => 'X'])->assertNotFound();
    $this->withToken($key)->patchJson("/api/v1/crm/deals/{$this->a['deal']->id}", ['stage_id' => $this->b['stage']->id])->assertUnprocessable();
    $this->withToken($key)->withHeader('Idempotency-Key', 'tvars-affar')
        ->postJson('/api/v1/crm/deals', ['account_id' => $this->b['account']->id, 'title' => 'X', 'next_step' => ['type' => 'TASK']])
        ->assertUnprocessable();
    $this->withToken($key)->patchJson("/api/v1/crm/deals/{$this->a['deal']->id}", ['status' => 'LOST', 'loss_reason_id' => $this->b['reason']->id])->assertUnprocessable();
});

it('makes win-back tasks for one company without touching the other', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-21 06:00:00'));

    expect(app(CreateWinBackTasks::class)($this->a['company']))->toBe(1);

    CurrentCompany::set($this->b['company']);
    expect(Activity::query()->where('subject', 'like', 'Vinn tillbaka%')->count())->toBe(0)
        ->and($this->b['lost']->fresh()?->win_back_task_at)->toBeNull();
});
