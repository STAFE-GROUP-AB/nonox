<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Crm\Actions\ArchivePipeline;
use App\Modules\Crm\Actions\LoseDeal;
use App\Modules\Crm\Actions\RemoveStage;
use App\Modules\Crm\Actions\SaveDeal;
use App\Modules\Crm\Actions\SaveGoal;
use App\Modules\Crm\Actions\SaveLossReason;
use App\Modules\Crm\Actions\SavePipeline;
use App\Modules\Crm\Livewire\PipelineSettings;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\DealStageChange;
use App\Modules\Crm\Models\Goal;
use App\Modules\Crm\Models\LossReason;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * CRM → Inställningar in stage 2 (ADR-043): pipelines with up to seven
 * stages, a stage with deals removed only by moving them, archived
 * pipelines, the loss reasons and the sellers' goals. crm:settings only.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->account = Crm::prospect($this->company, $this->owner);
});

/** @return list<array{id?: int, name: string, probability: int}> */
function stagesOf(Pipeline $pipeline): array
{
    return $pipeline->stages()->get()->map(fn (PipelineStage $stage): array => ['id' => $stage->id, 'name' => $stage->name, 'probability' => $stage->probability])->all();
}

it('renames, reorders and adds stages, and allows at most seven', function () {
    $pipeline = Crm::pipeline();
    $stages = stagesOf($pipeline);
    $save = app(SavePipeline::class);

    $saved = $save($this->owner, $this->company, ['name' => 'Nyförsäljning', 'stages' => [
        [...$stages[1], 'name' => 'Behovsanalys', 'probability' => 30],
        $stages[0],
        ...array_slice($stages, 2),
        ['name' => 'Avtal', 'probability' => 90],
    ]], $pipeline);

    expect($saved->name)->toBe('Nyförsäljning')
        ->and($saved->stages()->pluck('probability', 'name')->all())->toBe(['Behovsanalys' => 30, 'Ny kontakt' => 10, 'Offert' => 50, 'Förhandling' => 75, 'Avtal' => 90]);

    $eight = array_map(fn (int $n): array => ['name' => "Steg {$n}", 'probability' => 10], range(1, 8));
    expect(fn () => $save($this->owner, $this->company, ['name' => 'För lång', 'stages' => $eight]))->toThrow(ValidationException::class, 'högst 7 steg')
        ->and(fn () => $save($this->owner, $this->company, ['name' => 'Tom', 'stages' => []]))->toThrow(ValidationException::class, 'minst ett steg')
        ->and(fn () => $save($this->owner, $this->company, ['name' => 'Fel', 'stages' => [['name' => 'A', 'probability' => 101]]]))->toThrow(ValidationException::class, 'mellan 0 och 100');

    // Stages left out of the list still count until they are removed.
    $current = stagesOf($saved->fresh());
    expect(fn () => $save($this->owner, $this->company, ['name' => 'Nyförsäljning', 'stages' => [$current[0], ['name' => 'X', 'probability' => 1], ['name' => 'Y', 'probability' => 1], ['name' => 'Z', 'probability' => 1]]], $saved->fresh()))->toThrow(ValidationException::class, 'högst 7 steg');
});

it('adds a second pipeline, makes it the default, and keeps exactly one default', function () {
    $service = app(SavePipeline::class)($this->owner, $this->company, ['name' => 'Service', 'is_default' => true, 'stages' => [['name' => 'Förfrågan', 'probability' => 40]]]);

    expect($service->is_default)->toBeTrue()
        ->and(Pipeline::query()->where('is_default', true)->pluck('name')->all())->toBe(['Service'])
        ->and(Crm::deal($this->company, $this->owner, $this->account)->pipeline_id)->toBe($service->id);
});

it('removes a stage with deals only by moving them to another stage of the same pipeline', function () {
    $deal = Crm::deal($this->company, $this->owner, $this->account, extra: ['stage_id' => Crm::stage(1)->id]);
    $lost = Crm::deal($this->company, $this->owner, $this->account, 'Förlorad', extra: ['stage_id' => Crm::stage(1)->id]);
    app(LoseDeal::class)($this->owner, $this->company, $lost, ['loss_reason_id' => LossReason::query()->value('id')]);
    $behov = Crm::stage(1);
    $remove = app(RemoveStage::class);
    $other = PipelineStage::create(['pipeline_id' => Pipeline::create(['name' => 'Annan'])->id, 'name' => 'Där', 'probability' => 5]);

    expect(fn () => $remove($this->owner, $this->company, $behov))->toThrow(ValidationException::class, '2 affärer')
        ->and(fn () => $remove($this->owner, $this->company, $behov, $other))->toThrow(ValidationException::class, 'samma säljtratt');

    $remove($this->owner, $this->company, $behov, Crm::stage(2));

    expect(PipelineStage::query()->whereKey($behov->id)->exists())->toBeFalse()
        ->and(Deal::query()->whereKey([$deal->id, $lost->id])->pluck('stage_id')->unique()->all())->toBe([Crm::stage(1)->id])
        ->and(DealStageChange::query()->where('deal_id', $deal->id)->where('to_stage_id', Crm::stage(1)->id)->exists())->toBeTrue()
        ->and(DealStageChange::query()->where('deal_id', $lost->id)->count())->toBe(1);

    // A stage without deals goes at once; the last one stays.
    $remove($this->owner, $this->company, Crm::stage(0));
    $pipeline = Crm::pipeline();
    PipelineStage::query()->where('pipeline_id', $pipeline->id)->whereKeyNot(Crm::stage(0)->id)->get()->each(fn (PipelineStage $stage) => $remove($this->owner, $this->company, $stage, Crm::stage(0)));
    expect(fn () => $remove($this->owner, $this->company, Crm::stage(0)))->toThrow(ValidationException::class, 'minst ett steg');
});

it('archives a pipeline only without open deals and not the last one, and moves the default', function () {
    $sales = Crm::pipeline();
    $deal = Crm::deal($this->company, $this->owner, $this->account);
    $archive = app(ArchivePipeline::class);

    expect(fn () => $archive($this->owner, $this->company, $sales))->toThrow(ValidationException::class, 'öppna affärer');

    app(LoseDeal::class)($this->owner, $this->company, $deal, ['loss_reason_id' => LossReason::query()->value('id')]);
    expect(fn () => $archive($this->owner, $this->company, $sales->fresh()))->toThrow(ValidationException::class, 'minst en säljtratt');

    $service = app(SavePipeline::class)($this->owner, $this->company, ['name' => 'Service', 'stages' => [['name' => 'Förfrågan', 'probability' => 40]]]);
    $archive($this->owner, $this->company, $sales->fresh());

    expect($sales->fresh()?->isArchived())->toBeTrue()
        ->and($service->fresh()?->is_default)->toBeTrue()
        ->and(fn () => app(SaveDeal::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'title' => 'X', 'pipeline_id' => $sales->id, 'next_step' => ['type' => 'TASK']]))->toThrow(ValidationException::class, 'arkiverad');

    $archive($this->owner, $this->company, $sales->fresh(), false);
    expect($sales->fresh()?->isArchived())->toBeFalse();
});

it('adds, renames and archives loss reasons, with unique names', function () {
    $save = app(SaveLossReason::class);

    $reason = $save($this->owner, $this->company, ['name' => 'Leveranstiden']);
    expect(fn () => $save($this->owner, $this->company, ['name' => 'Priset']))->toThrow(ValidationException::class, 'finns redan');

    $archived = $save($this->owner, $this->company, ['name' => 'Leveranstid', 'archived' => true], $reason);
    expect($archived->name)->toBe('Leveranstid')
        ->and($archived->archived_at)->not->toBeNull()
        ->and(LossReason::query()->active()->count())->toBe(6);
});

it('sets, replaces and removes a seller\'s monthly goals, in kronor for order value', function () {
    $seller = Bookkeeping::member($this->company, 'sales');
    $save = app(SaveGoal::class);

    $save($this->owner, $this->company, ['user_id' => $seller->id, 'month' => '2026-06', 'metric' => 'MEETINGS', 'target' => '8']);
    $save($this->owner, $this->company, ['user_id' => $seller->id, 'month' => '2026-06', 'metric' => 'MEETINGS', 'target' => '10']);
    $value = $save($this->owner, $this->company, ['user_id' => $seller->id, 'month' => '2026-06-15', 'metric' => 'ORDER_VALUE', 'target' => '250 000']);

    expect(Goal::query()->count())->toBe(2)
        ->and(Goal::query()->where('metric', 'MEETINGS')->value('target'))->toBe(10)
        ->and($value?->target)->toBe(25_000_000)
        ->and($value?->month->toDateString())->toBe('2026-06-01');

    expect(fn () => $save($this->owner, $this->company, ['user_id' => $seller->id, 'month' => '2026-06', 'metric' => 'QUOTES', 'target' => '-1']))->toThrow(ValidationException::class, 'heltal')
        ->and(fn () => $save($this->owner, $this->company, ['user_id' => $seller->id, 'month' => 'juni', 'metric' => 'QUOTES', 'target' => '3']))->toThrow(ValidationException::class)
        ->and(fn () => $save($seller, $this->company, ['user_id' => $seller->id, 'month' => '2026-06', 'metric' => 'QUOTES', 'target' => '3']))->toThrow(AuthorizationException::class);

    expect($save($this->owner, $this->company, ['user_id' => $seller->id, 'month' => '2026-06', 'metric' => 'MEETINGS', 'target' => '']))->toBeNull()
        ->and(Goal::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'crm.goal_removed')->exists())->toBeTrue();
});

it('lets only crm:settings change pipelines, stages and loss reasons, and shows the editor only to them', function () {
    $seller = Bookkeeping::member($this->company, 'sales');

    expect(fn () => app(SavePipeline::class)($seller, $this->company, ['name' => 'X', 'stages' => [['name' => 'A', 'probability' => 1]]]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SavePipeline::class)($seller, $this->company, ['name' => 'X', 'stages' => stagesOf(Crm::pipeline())], Crm::pipeline()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveStage::class)($seller, $this->company, Crm::stage(3)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ArchivePipeline::class)($seller, $this->company, Crm::pipeline()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveLossReason::class)($seller, $this->company, ['name' => 'X']))->toThrow(AuthorizationException::class);

    $this->actingAs($seller);
    Livewire::test(PipelineSettings::class)->assertForbidden();
});

it('edits a pipeline on the settings page: adds a stage up to seven and removes one with deals by moving them', function () {
    Crm::deal($this->company, $this->owner, $this->account, extra: ['stage_id' => Crm::stage(1)->id]);
    $pipeline = Crm::pipeline();

    $page = Livewire::test(PipelineSettings::class)
        ->call('startPipeline', $pipeline->id)
        ->call('addStage')->call('addStage')->call('addStage')
        ->call('addStage')
        ->assertSee('Säljtratten har 7 steg')
        ->set('pipelineForm.stages.4.name', 'Avtal')
        ->set('pipelineForm.stages.5.name', 'Leverans')
        ->set('pipelineForm.stages.6.name', 'Uppföljning')
        ->call('savePipeline')
        ->assertHasNoErrors();

    expect($pipeline->stages()->count())->toBe(7);

    $page->call('startPipeline', $pipeline->id)
        ->call('removeStage', 1)
        ->assertSee('Flytta affärerna till')
        ->set('moveTo', (string) Crm::stage(2)->id)
        ->call('removeStage', 1);

    expect($pipeline->stages()->count())->toBe(6)
        ->and(Deal::query()->sole()->stage_id)->toBe(Crm::stage(1)->id);
});
