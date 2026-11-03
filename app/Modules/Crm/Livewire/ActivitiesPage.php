<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Actions\CompleteActivity;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Mina aktiviteter (ADR-043): the user's open activities, overdue first,
 * then today's and the coming ones. Done is one tap; the page then asks for
 * the account's next step, and skipping it is one more tap. Those who
 * manage the CRM can look at everyone's.
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

    /** @var array<string, string> */
    public array $next = ['type' => 'TASK', 'subject' => '', 'due_at' => ''];

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Activity::class, $this->company()]), 403);
    }

    public function done(int $activityId, CompleteActivity $complete): void
    {
        $activity = $this->scoped()->findOrFail($activityId);

        try {
            $complete($this->user(), $this->company(), $activity);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->nextAccountId = $activity->account_id;
        $this->nextPersonId = $activity->person_id;
        $this->next = ['type' => ActivityType::Task->value, 'subject' => '', 'due_at' => CarbonImmutable::now(LogActivity::ZONE)->addWeekday()->setTime(9, 0)->format('Y-m-d\TH:i')];
        $this->resetErrorBag();
        $this->toast(__('Klart. Vad är nästa steg?'));
    }

    public function saveNext(LogActivity $log): void
    {
        if ($this->nextAccountId === null) {
            return;
        }

        $log($this->user(), $this->company(), [
            ...$this->next,
            'account_id' => $this->nextAccountId,
            'person_id' => $this->nextPersonId,
            'done' => false,
        ]);

        $this->reset('nextAccountId', 'nextPersonId', 'next');
        $this->toast(__('Nästa steg är planerat.'));
    }

    public function skipNext(): void
    {
        $this->reset('nextAccountId', 'nextPersonId', 'next');
        $this->toast(__('Inget nästa steg planerat.'), 'info');
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
            ->with(['account:id,name,phone', 'person:id,name,phone,mobile,do_not_contact_at', 'assignee:id,name'])
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
