<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Crm\Actions\ArchivePipeline;
use App\Modules\Crm\Actions\RemoveStage;
use App\Modules\Crm\Actions\SaveLossReason;
use App\Modules\Crm\Actions\SavePipeline;
use App\Modules\Crm\Livewire\Concerns\ActsInCompany;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\LossReason;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * CRM → Inställningar, stage 2 (ADR-043): the pipelines and their stages
 * (rename, reorder, probability, up to seven, archive a pipeline; a stage
 * with deals is removed only by moving them), and the loss reasons. For
 * those with crm:settings.
 */
class PipelineSettings extends Component
{
    use ActsInCompany;
    use ShowsToasts;

    /** The pipeline being edited: an id, 'ny' for a new one, '' for none. */
    #[Locked]
    public string $editing = '';

    /** @var array{name: string, is_default: bool, stages: array<int, array{id: ?int, name: string, probability: int|string, deals: int}>} */
    public array $pipelineForm = ['name' => '', 'is_default' => false, 'stages' => []];

    /** The stage asked to be removed, and where its deals go. */
    #[Locked]
    public ?int $removingStageId = null;

    public string $moveTo = '';

    public string $newReason = '';

    /** @var array<int, string> loss reason id => name being edited */
    public array $reasonNames = [];

    public function mount(): void
    {
        abort_unless($this->user()->can('settings', [Pipeline::class, $this->company()]), 403);
    }

    public function startPipeline(?int $pipelineId = null): void
    {
        $this->authorizeSettings();

        if ($pipelineId === null) {
            $this->editing = 'ny';
            $this->pipelineForm = ['name' => '', 'is_default' => false, 'stages' => [
                ['id' => null, 'name' => __('Ny kontakt'), 'probability' => 10, 'deals' => 0],
                ['id' => null, 'name' => __('Offert'), 'probability' => 50, 'deals' => 0],
            ]];
        } else {
            $pipeline = Pipeline::query()->findOrFail($pipelineId);
            $counts = Deal::query()->where('pipeline_id', $pipeline->id)->groupBy('stage_id')->toBase()->selectRaw('stage_id, COUNT(*) as total')->pluck('total', 'stage_id');
            $this->editing = (string) $pipeline->id;
            $this->pipelineForm = [
                'name' => $pipeline->name,
                'is_default' => $pipeline->is_default,
                'stages' => $pipeline->stages()->get()->map(fn (PipelineStage $stage): array => ['id' => $stage->id, 'name' => $stage->name, 'probability' => $stage->probability, 'deals' => (int) ($counts[$stage->id] ?? 0)])->all(),
            ];
        }

        $this->reset('removingStageId', 'moveTo');
        $this->resetErrorBag();
    }

    public function addStage(): void
    {
        if (count($this->pipelineForm['stages']) >= Pipeline::MAX_STAGES) {
            $this->toast(__('En säljtratt kan ha högst :max steg.', ['max' => Pipeline::MAX_STAGES]), 'error');

            return;
        }

        $this->pipelineForm['stages'][] = ['id' => null, 'name' => '', 'probability' => 50, 'deals' => 0];
    }

    public function moveStage(int $index, int $direction): void
    {
        $target = $index + $direction;
        $stages = $this->pipelineForm['stages'];

        if (! isset($stages[$index], $stages[$target])) {
            return;
        }

        [$stages[$index], $stages[$target]] = [$stages[$target], $stages[$index]];
        $this->pipelineForm['stages'] = array_values($stages);
    }

    /** A stage not saved yet just leaves the form; a saved one goes through RemoveStage. */
    public function removeStage(int $index, RemoveStage $remove): void
    {
        $stage = $this->pipelineForm['stages'][$index] ?? null;

        if ($stage === null) {
            return;
        }

        if ($stage['id'] === null) {
            unset($this->pipelineForm['stages'][$index]);
            $this->pipelineForm['stages'] = array_values($this->pipelineForm['stages']);

            return;
        }

        if ($stage['deals'] > 0 && $this->removingStageId !== $stage['id']) {
            $this->removingStageId = $stage['id'];
            $this->moveTo = '';

            return;
        }

        try {
            $remove($this->user(), $this->company(), PipelineStage::query()->findOrFail($stage['id']), $this->moveTo === '' ? null : PipelineStage::query()->findOrFail((int) $this->moveTo));
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast(__('Steget :name är borttaget.', ['name' => $stage['name']]));
        $this->startPipeline((int) $this->editing);
    }

    public function savePipeline(SavePipeline $save): void
    {
        $pipeline = $this->editing === 'ny' ? null : Pipeline::query()->findOrFail((int) $this->editing);

        $saved = $save($this->user(), $this->company(), [
            'name' => $this->pipelineForm['name'],
            'is_default' => (bool) $this->pipelineForm['is_default'],
            'stages' => array_map(fn (array $stage): array => ['id' => $stage['id'], 'name' => (string) $stage['name'], 'probability' => is_numeric($stage['probability']) ? (int) $stage['probability'] : $stage['probability']], $this->pipelineForm['stages']),
        ], $pipeline);

        $this->closeForm();
        $this->toast(__('Säljtratten :name är sparad.', ['name' => $saved->name]));
    }

    public function archive(int $pipelineId, bool $archive, ArchivePipeline $action): void
    {
        try {
            $pipeline = $action($this->user(), $this->company(), Pipeline::query()->findOrFail($pipelineId), $archive);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast($archive ? __('Säljtratten :name är arkiverad.', ['name' => $pipeline->name]) : __('Säljtratten :name används igen.', ['name' => $pipeline->name]));
    }

    public function closeForm(): void
    {
        $this->reset('editing', 'pipelineForm', 'removingStageId', 'moveTo');
        $this->resetErrorBag();
    }

    public function addReason(SaveLossReason $save): void
    {
        $save($this->user(), $this->company(), ['name' => $this->newReason]);

        $this->reset('newReason');
        $this->toast(__('Orsaken är tillagd.'));
    }

    public function renameReason(int $reasonId, SaveLossReason $save): void
    {
        $reason = LossReason::query()->findOrFail($reasonId);

        try {
            $save($this->user(), $this->company(), ['name' => $this->reasonNames[$reasonId] ?? $reason->name], $reason);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast(__('Orsaken är sparad.'));
    }

    public function toggleReason(int $reasonId, SaveLossReason $save): void
    {
        $reason = LossReason::query()->findOrFail($reasonId);
        $save($this->user(), $this->company(), ['name' => $reason->name, 'archived' => $reason->archived_at === null], $reason);

        $this->toast($reason->archived_at === null ? __('Orsaken används igen.') : __('Orsaken är arkiverad. Affärer som redan har den behåller den.'));
    }

    public function render(): View
    {
        $reasons = LossReason::query()->orderByRaw('archived_at IS NOT NULL')->orderBy('sort')->orderBy('id')->get();

        foreach ($reasons as $reason) {
            $this->reasonNames[$reason->id] ??= $reason->name;
        }

        return view('crm::livewire.pipeline-settings', [
            'pipelines' => Pipeline::query()->with('stages')->orderByRaw('archived_at IS NOT NULL')->ordered()->get(),
            'reasons' => $reasons,
            'max' => Pipeline::MAX_STAGES,
        ]);
    }

    private function authorizeSettings(): void
    {
        abort_unless($this->user()->can('settings', [Pipeline::class, $this->company()]), 403);
    }
}
