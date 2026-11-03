<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\CallOutcome;
use App\Modules\Crm\Domain\DealNextStep;
use App\Modules\Crm\Models\Activity;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Marks an open activity as done, with the outcome of a call and a short
 * note on how it went (ADR-043). The screen then asks for the next step,
 * which is a new activity (LogActivity); skipping it is one tap.
 *
 * Stage 2: the last open step of an open deal cannot be skipped. It is
 * completed only together with the next step (`next`: type, subject,
 * due_at, assigned_to, person_id), which is created in the same
 * transaction; or the deal is won or lost first (DealNextStep).
 */
final class CompleteActivity
{
    public function __construct(private readonly LogActivity $log) {}

    /**
     * @param  array<string, mixed>  $input  call_outcome, note and next, all optional
     */
    public function __invoke(User $user, Company $company, Activity $activity, array $input = []): Activity
    {
        Gate::forUser($user)->authorize('update', $activity);

        if (! $activity->isOpen()) {
            throw ValidationException::withMessages(['activity' => __('Aktiviteten är redan klar.')]);
        }

        $validated = Validator::make($input, [
            'call_outcome' => ['nullable', Rule::enum(CallOutcome::class)],
            'note' => ['nullable', 'string', 'max:10000'],
            'next' => ['nullable', 'array'],
            // The next step is planned: a note is done when written and would leave the deal without one.
            'next.type' => ['sometimes', Rule::in(array_column(array_filter(ActivityType::cases(), fn (ActivityType $type): bool => $type->canBePlanned()), 'value'))],
        ], [], ['next.type' => __('typ')])->validate();

        $note = trim((string) ($validated['note'] ?? ''));
        // validate() keeps only the keys with rules, so the next step is read from the input; LogActivity validates it.
        $next = isset($validated['next'], $input['next']) && is_array($input['next']) ? $input['next'] : null;

        if ($next === null) {
            CurrentCompany::run($company, fn () => DealNextStep::guardLeaving($activity));
        }

        return DB::transaction(fn (): Activity => CurrentCompany::run($company, function () use ($user, $company, $activity, $validated, $note, $next): Activity {
            $activity->forceFill([
                'done_at' => now(),
                'call_outcome' => $activity->type === ActivityType::Call ? ($validated['call_outcome'] ?? $activity->call_outcome?->value) : null,
                'body' => $note === '' ? $activity->body : trim(($activity->body === null ? '' : $activity->body."\n\n").$note),
            ])->save();

            LogActivity::touch($activity);
            Audit::record('crm.activity_completed', $activity, ['type' => $activity->type->value, 'account_id' => $activity->account_id, 'deal_id' => $activity->deal_id], $company, $user);

            if ($next !== null) {
                ($this->log)($user, $company, [
                    'type' => ActivityType::Task->value,
                    'person_id' => $activity->person_id,
                    ...array_intersect_key($next, array_flip(['type', 'subject', 'body', 'due_at', 'assigned_to', 'person_id'])),
                    'account_id' => $activity->account_id,
                    'deal_id' => $activity->deal_id,
                    'done' => false,
                ]);
            }

            return $activity;
        }));
    }
}
