<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\CallOutcome;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\CallList;
use App\Modules\Crm\Models\CallListItem;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Works a call list one company at a time (ADR-047). call() logs the call
 * with its outcome through LogActivity, so the "Kontakta inte" rules of
 * ADR-043 apply, and plans the next step if one is given, in one
 * transaction. Reached and wrong number finish the company on the list; no
 * answer and a left message put it last in the queue until
 * CallList::MAX_ATTEMPTS calls. skip() leaves a company without calling.
 */
final class WorkCallList
{
    public function __construct(private readonly LogActivity $logActivity) {}

    /**
     * @param  array{outcome?: mixed, person_id?: mixed, note?: mixed, next_type?: mixed, next_subject?: mixed, next_at?: mixed}  $input
     */
    public function call(User $user, Company $company, CallListItem $item, array $input): CallListItem
    {
        Gate::forUser($user)->authorize('manage', [Account::class, $company]);
        $this->guardOpen($company, $item);

        $validated = Validator::make($input, [
            'outcome' => ['required', Rule::enum(CallOutcome::class)],
            'person_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:10000'],
            'next_type' => ['nullable', Rule::in([ActivityType::Call->value, ActivityType::Meeting->value, ActivityType::Task->value])],
            'next_subject' => ['nullable', 'string', 'max:255'],
            'next_at' => ['nullable', 'date', 'required_with:next_type'],
        ], [
            'outcome.required' => __('Välj hur samtalet gick.'),
            'next_at.required_with' => __('Välj när nästa steg ska göras.'),
        ])->validate();

        $outcome = CallOutcome::from((string) $validated['outcome']);

        return DB::transaction(fn (): CallListItem => CurrentCompany::run($company, function () use ($user, $company, $item, $validated, $outcome): CallListItem {
            $item = CallListItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $personId = filled($validated['person_id'] ?? null) ? (int) $validated['person_id'] : null;

            $activity = ($this->logActivity)($user, $company, [
                'account_id' => $item->account_id,
                'person_id' => $personId,
                'type' => ActivityType::Call->value,
                'call_outcome' => $outcome->value,
                'subject' => $outcome->label(),
                'body' => $validated['note'] ?? null,
                'done' => true,
            ]);

            if (filled($validated['next_type'] ?? null)) {
                $type = ActivityType::from((string) $validated['next_type']);
                ($this->logActivity)($user, $company, [
                    'account_id' => $item->account_id,
                    'person_id' => $personId,
                    'type' => $type->value,
                    'subject' => trim((string) ($validated['next_subject'] ?? '')) ?: $type->label(),
                    'due_at' => $validated['next_at'],
                ]);
            }

            $attempts = $item->attempts + 1;
            $again = in_array($outcome, [CallOutcome::NoAnswer, CallOutcome::LeftMessage], true) && $attempts < CallList::MAX_ATTEMPTS;

            $item->forceFill([
                'attempts' => $attempts,
                'outcome' => $outcome,
                'activity_id' => $activity->id,
                'status' => $again ? CallListItem::PENDING : CallListItem::DONE,
                'position' => $again ? (int) CallListItem::query()->where('call_list_id', $item->call_list_id)->max('position') + 1 : $item->position,
                'done_at' => $again ? null : now(),
                'done_by' => $again ? null : $user->id,
            ])->save();

            Audit::record('crm.call_list_call', $item, ['call_list_id' => $item->call_list_id, 'account_id' => $item->account_id, 'outcome' => $outcome->value, 'again' => $again], $company, $user);

            return $item;
        }));
    }

    public function skip(User $user, Company $company, CallListItem $item): CallListItem
    {
        Gate::forUser($user)->authorize('manage', [Account::class, $company]);
        $this->guardOpen($company, $item);

        return CurrentCompany::run($company, function () use ($user, $company, $item): CallListItem {
            $item->forceFill(['status' => CallListItem::SKIPPED, 'done_at' => now(), 'done_by' => $user->id])->save();
            Audit::record('crm.call_list_skip', $item, ['call_list_id' => $item->call_list_id, 'account_id' => $item->account_id], $company, $user);

            return $item;
        });
    }

    public function archive(User $user, Company $company, CallList $list, bool $archived = true): void
    {
        Gate::forUser($user)->authorize('manage', [Account::class, $company]);

        CurrentCompany::run($company, function () use ($user, $company, $list, $archived): void {
            $list->forceFill(['archived_at' => $archived ? now() : null])->save();
            Audit::record($archived ? 'crm.call_list_archived' : 'crm.call_list_restored', $list, ['call_list_id' => $list->id], $company, $user);
        });
    }

    private function guardOpen(Company $company, CallListItem $item): void
    {
        $open = CurrentCompany::run($company, fn (): bool => CallList::query()->whereKey($item->call_list_id)->whereNull('archived_at')->exists());

        if (! $open || $item->status !== CallListItem::PENDING) {
            throw ValidationException::withMessages(['item' => __('Företaget är redan avklarat på ringlistan, eller listan är arkiverad.')]);
        }
    }
}
