<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\RegistryCompany;
use App\Models\User;
use App\Modules\Crm\Actions\WorkCallList;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\CallOutcome;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\CallList;
use App\Modules\Crm\Models\CallListItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One call list (ADR-047), worked one company at a time: the company with
 * its phone, the contact people that may be called, the last activities
 * and what the register says; one tap logs the call's outcome (with an
 * optional note and next step) and moves on. Progress as done of total.
 * Another company's list answers 404.
 */
class CallListPage extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $callListId;

    public string $personId = '';

    public string $note = '';

    public string $nextType = '';

    public string $nextAt = '';

    public string $nextSubject = '';

    public function mount(int $callListId): void
    {
        abort_unless($this->user()->can('viewAny', [Account::class, $this->company()]), 403);

        $this->callListId = $callListId;
        // Another company's list is not found: 404, never 403.
        $this->list();
    }

    public function call(string $outcome, WorkCallList $work): void
    {
        $item = $this->current();
        abort_if($item === null, 404);

        $work->call($this->user(), $this->company(), $item, [
            'outcome' => $outcome,
            'person_id' => $this->personId === '' ? null : (int) $this->personId,
            'note' => $this->note === '' ? null : $this->note,
            'next_type' => $this->nextType === '' ? null : $this->nextType,
            'next_subject' => $this->nextSubject,
            'next_at' => $this->nextAt === '' ? null : $this->nextAt,
        ]);

        $this->reset('personId', 'note', 'nextType', 'nextAt', 'nextSubject');
        $this->toast(__('Samtalet är loggat: :outcome.', ['outcome' => mb_strtolower(CallOutcome::from($outcome)->label())]));
    }

    public function skip(WorkCallList $work): void
    {
        $item = $this->current();
        abort_if($item === null, 404);

        $work->skip($this->user(), $this->company(), $item);
        $this->reset('personId', 'note', 'nextType', 'nextAt', 'nextSubject');
        $this->toast(__('Företaget är överhoppat.'));
    }

    public function archive(WorkCallList $work): void
    {
        $list = $this->list();
        $archiving = $list->archived_at === null;
        $work->archive($this->user(), $this->company(), $list, $archiving);
        $this->toast($archiving ? __('Ringlistan är arkiverad.') : __('Ringlistan är återställd.'));
    }

    public function render(): View
    {
        $list = $this->list()->load('assignee:id,name');
        $items = CallListItem::query()
            ->where('call_list_id', $list->id)
            ->with('account:id,name,city,phone,status,org_number')
            ->orderByRaw("CASE status WHEN 'PENDING' THEN 0 ELSE 1 END")
            ->orderBy('position')
            ->get();
        $current = $items->first(fn (CallListItem $item): bool => $item->status === CallListItem::PENDING);
        $account = $current === null ? null : Account::query()
            ->with(['people' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
            ->find($current->account_id);
        $done = $items->where('status', '<>', CallListItem::PENDING)->count();

        return view('crm::livewire.call-list', [
            'list' => $list,
            'items' => $items,
            'current' => $current,
            'account' => $account,
            'register' => $account === null ? null : RegistryCompany::query()->where('org_number', RegistryCompany::digits($account->org_number) ?? '')->first(),
            'recent' => $account === null ? collect() : Activity::query()->where('account_id', $account->id)->whereNotNull('done_at')->with('person:id,name')->orderByDesc('done_at')->limit(3)->get(),
            'done' => $done,
            'total' => $items->count(),
            'outcomes' => CallOutcome::cases(),
            'nextTypes' => [ActivityType::Call, ActivityType::Meeting, ActivityType::Task],
            'canWork' => $list->archived_at === null && $this->user()->can('manage', [Account::class, $this->company()]),
            'canManage' => $this->user()->can('manage', [Account::class, $this->company()]),
            'now' => CarbonImmutable::now(),
        ]);
    }

    private function current(): ?CallListItem
    {
        return CallListItem::query()
            ->where('call_list_id', $this->list()->id)
            ->where('status', CallListItem::PENDING)
            ->orderBy('position')
            ->first();
    }

    private function list(): CallList
    {
        return CallList::query()->findOrFail($this->callListId);
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
