<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\CallOutcome;
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
 */
final class CompleteActivity
{
    /**
     * @param  array<string, mixed>  $input  call_outcome and note, both optional
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
        ])->validate();

        $note = trim((string) ($validated['note'] ?? ''));

        return DB::transaction(fn (): Activity => CurrentCompany::run($company, function () use ($user, $company, $activity, $validated, $note): Activity {
            $activity->forceFill([
                'done_at' => now(),
                'call_outcome' => $activity->type === ActivityType::Call ? ($validated['call_outcome'] ?? $activity->call_outcome?->value) : null,
                'body' => $note === '' ? $activity->body : trim(($activity->body === null ? '' : $activity->body."\n\n").$note),
            ])->save();

            LogActivity::touch($activity);
            Audit::record('crm.activity_completed', $activity, ['type' => $activity->type->value, 'account_id' => $activity->account_id], $company, $user);

            return $activity;
        }));
    }
}
