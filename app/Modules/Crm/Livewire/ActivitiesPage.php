<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Actions\CompleteActivity;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\DealNextStep;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Mina aktiviteter (ADR-043): the user's open activities, overdue first,
 * then today's and the coming ones. Done is one tap; the page then asks for
 * the account's next step, and skipping it is one more tap. Those who
 * manage the CRM can look at everyone's.
 *
 * Stage 2: the last open step of an open deal is not completed by the tap
 * alone. The next step is asked for first, without a skip, and both are
 * saved together (CompleteActivity); the deal's card is one tap away to
 * win or lose it instead.
 */
class ActivitiesPage extends Component
{
    use ShowsToasts;

    /** How far ahead "Kommande" reaches. */
    public const int UPCOMING_DAYS = 30;

    /** '' for the user's own, 'alla' for everyone's. */
    #[Url(as: 'vem', except: '')]
    public string $who = '';

    /** The account whose next step is asked for after a completion. */
    public ?int $nextAccountId = null;

    public ?int $nextPersonId = null;

    /** The deal's last open step, completed only together with the next one. */
    #[Locked]
    public ?int $pendingActivityId = null;

    #[Locked]
    public ?int $nextDealId = null;

    /** @var array<string, string> */
    public array $next = ['type' => 'TASK', 'subject' => '', 'due_at' => ''];

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Activity::class, $this->company()]), 403);
    }

    public function done(int $activityId, CompleteActivity $complete): void
    {
        $activity = $this->scoped()->findOrFail($activityId);

        if (DealNextStep::isLastStep($activity)) {
            abort_unless($this->user()->can('update', $activity), 403);
            $this->askNext($activity);
            $this->pendingActivityId = $activity->id;
            $this->nextDealId = $activity->deal_id;
            $this->toast(__('Det här är affärens enda planerade steg. Vad är nästa?'), 'info');

            return;
        }

        try {
            $complete($this->user(), $this->company(), $activity);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->askNext($activity);
        $this->nextDealId = $activity->deal_id;
        $this->toast(__('Klart. Vad är nästa steg?'));
    }

    public function saveNext(LogActivity $log, CompleteActivity $complete): void
    {
        if ($this->nextAccountId === null) {
            return;
        }

        if ($this->pendingActivityId !== null) {
            $complete($this->user(), $this->company(), $this->scoped()->findOrFail($this->pendingActivityId), ['next' => [...$this->next, 'person_id' => $this->nextPersonId]]);

            $this->reset('nextAccountId', 'nextPersonId', 'next', 'pendingActivityId', 'nextDealId');
            $this->toast(__('Klart, och nästa steg är planerat.'));

            return;
        }

        $log($this->user(), $this->company(), [
            ...$this->next,
            'account_id' => $this->nextAccountId,
            'person_id' => $this->nextPersonId,
            'deal_id' => $this->nextDealId,
            'done' => false,
        ]);

        $this->reset('nextAccountId', 'nextPersonId', 'next', 'pendingActivityId', 'nextDealId');
        $this->toast(__('Nästa steg är planerat.'));
    }

    /** Skipping is for accounts only: the last step of a deal waits until the next one is planned. */
    public function skipNext(): void
    {
        if ($this->pendingActivityId !== null) {
            $this->reset('nextAccountId', 'nextPersonId', 'next', 'pendingActivityId', 'nextDealId');
            $this->toast(__('Steget är inte klarmarkerat. En öppen affär behöver ett nästa steg.'), 'info');

            return;
        }

        $this->reset('nextAccountId', 'nextPersonId', 'next', 'nextDealId');
        $this->toast(__('Inget nästa steg planerat.'), 'info');
    }

    private function askNext(Activity $activity): void
    {
        $this->nextAccountId = $activity->account_id;
        $this->nextPersonId = $activity->person_id;
        $this->next = ['type' => ActivityType::Task->value, 'subject' => '', 'due_at' => CarbonImmutable::now(LogActivity::ZONE)->addWeekday()->setTime(9, 0)->format('Y-m-d\TH:i')];
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $now = CarbonImmutable::now();
        $zone = LogActivity::ZONE;
        $startOfToday = $now->setTimezone($zone)->startOfDay();
        $endOfToday = $startOfToday->endOfDay();
        $open = $this->scoped()
            ->whereNull('done_at')
            ->where(fn (Builder $query) => $query->whereNull('due_at')->orWhere('due_at', '<=', $startOfToday->addDays(self::UPCOMING_DAYS)))
            ->with(['account:id,name,phone', 'person:id,name,phone,mobile,do_not_contact_at', 'assignee:id,name', 'deal:id,title'])
            ->orderByRaw('due_at ASC NULLS LAST')
            ->orderBy('id')
            ->limit(300)
            ->get();

        /** @var Collection<int, Activity> $overdue */
        $overdue = $open->filter(fn (Activity $activity): bool => $activity->due_at !== null && $activity->due_at->lessThan($startOfToday));

        return view('crm::livewire.activities', [
            'overdue' => $overdue,
            'today' => $open->filter(fn (Activity $activity): bool => $activity->due_at !== null && $activity->due_at->betweenIncluded($startOfToday, $endOfToday)),
            'upcoming' => $open->filter(fn (Activity $activity): bool => $activity->due_at !== null && $activity->due_at->greaterThan($endOfToday)),
            'undated' => $open->filter(fn (Activity $activity): bool => $activity->due_at === null),
            'nextAccount' => $this->nextAccountId === null ? null : Account::query()->find($this->nextAccountId),
            'nextRequired' => $this->pendingActivityId !== null,
            'types' => array_filter(ActivityType::cases(), fn (ActivityType $type): bool => $type->canBePlanned()),
            'canManage' => $this->user()->can('manage', [Activity::class, $this->company()]),
            'now' => $now,
        ]);
    }

    /** @return Builder<Activity> */
    private function scoped(): Builder
    {
        $everyone = $this->who === 'alla' && $this->user()->can('manage', [Activity::class, $this->company()]);

        return Activity::query()->when(! $everyone, fn (Builder $query) => $query->where('assigned_to', $this->user()->id));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
