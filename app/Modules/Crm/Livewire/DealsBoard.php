<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\MoveDeal;
use App\Modules\Crm\Actions\SaveDeal;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\DealNextStep;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Domain\PipelineFigures;
use App\Modules\Crm\Livewire\Concerns\ActsInCompany;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Support\CompanyMembers;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The pipeline board (ADR-043 stage 2): a column per stage with its count,
 * value and weighted value, and a card per open deal with the account, the
 * value, the close date and the next step (red when it is overdue or
 * missing). A card is dragged to another stage, or moved with Flytta, which
 * is what the phone uses: it shows one stage at a time with a switcher.
 * Filters for pipeline and owner live in the query string. "Ny affär"
 * opens the form with the next step, which a new deal must have. A fixed
 * number of queries, whatever the number of deals.
 */
class DealsBoard extends Component
{
    use ActsInCompany;
    use ShowsToasts;

    /** The most cards on one board; a pipeline larger than this needs a filter. */
    public const int LIMIT = 500;

    #[Url(as: 'saljtratt', except: '')]
    public string $pipelineId = '';

    /** '' for everyone's, 'mina' for the user's own, or a member's id. */
    #[Url(as: 'ansvarig', except: '')]
    public string $owner = '';

    /** 'utan-steg' shows only the deals without a next step. */
    #[Url(as: 'visa', except: '')]
    public string $show = '';

    /** An account id from the account card's "Ny affär": opens the form for it. */
    #[Url(as: 'ny', except: '')]
    public string $newFor = '';

    public bool $creating = false;

    public string $accountSearch = '';

    /** @var array<string, string> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Deal::class, $this->company()]), 403);

        if ($this->newFor !== '' && ctype_digit($this->newFor)) {
            $this->startCreate((int) $this->newFor);
        }
    }

    public function startCreate(?int $accountId = null): void
    {
        abort_unless($this->user()->can('manage', [Deal::class, $this->company()]), 403);

        $account = $accountId === null ? null : Account::query()->find($accountId);

        $this->form = [
            'account_id' => $account === null ? '' : (string) $account->id,
            'person_id' => '',
            'title' => '',
            'value' => '',
            'expected_close_date' => CarbonImmutable::now(LogActivity::ZONE)->addMonthNoOverflow()->endOfMonth()->toDateString(),
            'owner_id' => (string) ($account->owner_id ?? $this->user()->id),
            'stage_id' => '',
            'next_type' => ActivityType::Call->value,
            'next_subject' => '',
            'next_due_at' => CarbonImmutable::now(LogActivity::ZONE)->addWeekday()->setTime(9, 0)->format('Y-m-d\TH:i'),
        ];
        $this->accountSearch = $account->name ?? '';
        $this->creating = true;
        $this->resetErrorBag();
    }

    public function pickAccount(int $accountId): void
    {
        $account = Account::query()->findOrFail($accountId);

        $this->form['account_id'] = (string) $account->id;
        $this->form['person_id'] = '';
        $this->accountSearch = $account->name;
    }

    public function create(SaveDeal $save): void
    {
        $deal = $save($this->user(), $this->company(), [
            'account_id' => $this->form['account_id'] === '' ? null : (int) $this->form['account_id'],
            'person_id' => $this->form['person_id'] === '' ? null : (int) $this->form['person_id'],
            'title' => $this->form['title'],
            'value' => $this->form['value'],
            'expected_close_date' => $this->form['expected_close_date'],
            'owner_id' => $this->form['owner_id'] === '' ? null : (int) $this->form['owner_id'],
            'stage_id' => $this->form['stage_id'] === '' ? null : (int) $this->form['stage_id'],
            'pipeline_id' => $this->form['stage_id'] === '' ? $this->currentPipeline()?->id : null,
            'next_step' => [
                'type' => $this->form['next_type'],
                'subject' => $this->form['next_subject'],
                'due_at' => $this->form['next_due_at'],
                'assigned_to' => $this->form['owner_id'] === '' ? null : (int) $this->form['owner_id'],
            ],
        ]);

        $this->closeForm();
        $this->toast(__('Affären :title är skapad, med nästa steg planerat.', ['title' => $deal->title]));
    }

    public function closeForm(): void
    {
        $this->reset('creating', 'form', 'accountSearch', 'newFor');
        $this->resetErrorBag();
    }

    /** Drag and drop, and Flytta: the deal to another stage. */
    public function move(int $dealId, int $stageId, MoveDeal $move): void
    {
        $deal = Deal::query()->findOrFail($dealId);

        try {
            $moved = $move($this->user(), $this->company(), $deal, $stageId);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast(__(':title är flyttad till :stage.', ['title' => $moved->title, 'stage' => $moved->stage?->name]));
    }

    public function render(PipelineFigures $figures): View
    {
        $company = $this->company();
        $pipelines = Pipeline::query()->active()->ordered()->get();
        $pipeline = $this->currentPipeline($pipelines->all());
        $stages = $pipeline === null ? collect() : $pipeline->stages()->get();
        $ownerId = $this->ownerId();
        $now = CarbonImmutable::now();

        $deals = $pipeline === null ? collect() : Deal::query()
            ->where('pipeline_id', $pipeline->id)
            ->where('status', DealStatus::Open->value)
            ->when($ownerId !== null, fn (Builder $query) => $query->where('owner_id', $ownerId))
            ->when($this->show === 'utan-steg', fn (Builder $query) => $query->whereIn('id', DealNextStep::missing()->select('crm_deals.id')))
            ->with(['account:id,name', 'owner:id,name'])
            ->withMin(['activities as next_due_at' => fn (Builder $query) => $query->whereNull('done_at')], 'due_at')
            ->withCount(['activities as open_steps' => fn (Builder $query) => $query->whereNull('done_at')])
            ->orderByRaw('expected_close_date ASC NULLS LAST')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        $accountId = ($this->form['account_id'] ?? '') === '' ? null : (int) $this->form['account_id'];

        return view('crm::livewire.deals', [
            'pipelines' => $pipelines,
            'pipeline' => $pipeline,
            'stages' => $stages,
            'byStage' => $deals->groupBy('stage_id'),
            'figures' => $pipeline === null ? [] : $figures->stages($pipeline->id, $ownerId),
            'members' => CompanyMembers::all($company),
            'canManage' => $this->user()->can('manage', [Deal::class, $company]),
            'canSettings' => $this->user()->can('settings', [Pipeline::class, $company]),
            'matches' => $this->creating && $accountId === null && mb_strlen(trim($this->accountSearch)) >= 2
                ? Account::query()->where('name', 'ilike', '%'.addcslashes(trim($this->accountSearch), '\\%_').'%')->orderBy('name')->limit(8)->get(['id', 'name', 'city'])
                : collect(),
            'people' => $this->creating && $accountId !== null
                ? Person::query()->whereHas('accounts', fn (Builder $query) => $query->whereKey($accountId))->orderBy('name')->get(['id', 'name'])
                : collect(),
            'types' => array_filter(ActivityType::cases(), fn (ActivityType $type): bool => $type->canBePlanned()),
            'now' => $now,
            'truncated' => $deals->count() >= self::LIMIT,
        ]);
    }

    private function ownerId(): ?int
    {
        return match (true) {
            $this->owner === 'mina' => $this->user()->id,
            ctype_digit($this->owner) => (int) $this->owner,
            default => null,
        };
    }

    /** @param  ?array<int, Pipeline>  $pipelines */
    private function currentPipeline(?array $pipelines = null): ?Pipeline
    {
        $pipelines ??= Pipeline::query()->active()->ordered()->get()->all();

        foreach ($pipelines as $pipeline) {
            if ((string) $pipeline->id === $this->pipelineId) {
                return $pipeline;
            }
        }

        return $pipelines[0] ?? null;
    }
}
