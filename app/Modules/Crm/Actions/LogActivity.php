<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\CallOutcome;
use App\Modules\Crm\Domain\DoNotContact;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Logs an activity on an account, or changes one (ADR-043). A note is done
 * when it is written; a call, meeting, e-mail or task is done when `done`
 * is set, and otherwise planned, with a due time if it has one. The
 * activity belongs to the person who logs it unless assigned to another
 * member. Times are entered and shown in Europe/Stockholm.
 */
final class LogActivity
{
    public const string ZONE = 'Europe/Stockholm';

    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input, ?Activity $activity = null): Activity
    {
        $activity === null
            ? Gate::forUser($user)->authorize('manage', [Activity::class, $company])
            : Gate::forUser($user)->authorize('update', $activity);

        $memberIds = CompanyMembers::ids($company);

        $validated = Validator::make($input, [
            'account_id' => [$activity === null ? 'required' : 'sometimes', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($company): void {
                if (! CurrentCompany::run($company, fn (): bool => Account::query()->whereKey((int) $value)->exists())) {
                    $fail(__('Kunden finns inte.'));
                }
            }],
            'person_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($company): void {
                if (! CurrentCompany::run($company, fn (): bool => Person::query()->whereKey((int) $value)->exists())) {
                    $fail(__('Personen finns inte.'));
                }
            }],
            'type' => [$activity === null ? 'required' : 'sometimes', Rule::enum(ActivityType::class)],
            'call_outcome' => ['nullable', Rule::enum(CallOutcome::class)],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'assigned_to' => ['nullable', 'integer', Rule::in($memberIds)],
            'due_at' => ['nullable', 'date'],
            'done' => ['sometimes', 'boolean'],
        ], [
            'assigned_to.in' => __('Aktiviteten kan bara tilldelas någon som är med i företaget.'),
        ], [
            'subject' => __('rubrik'),
            'due_at' => __('tid'),
        ])->validate();

        $type = ActivityType::from((string) ($validated['type'] ?? $activity?->type->value));
        $personId = array_key_exists('person_id', $validated) ? $validated['person_id'] : $activity?->person_id;

        $this->guardDoNotContact($company, $type, $personId === null ? null : (int) $personId, $activity);

        $subject = trim((string) ($validated['subject'] ?? $activity->subject ?? ''));
        $dueAt = array_key_exists('due_at', $validated)
            ? (blank($validated['due_at']) ? null : CarbonImmutable::parse((string) $validated['due_at'], self::ZONE))
            : $activity?->due_at;
        $done = $type === ActivityType::Note || (bool) ($validated['done'] ?? ($activity !== null && ! $activity->isOpen()));

        $attributes = [
            'type' => $type,
            'subject' => $subject === '' ? $type->label() : $subject,
            'call_outcome' => $type === ActivityType::Call ? ($validated['call_outcome'] ?? $activity?->call_outcome?->value) : null,
            'due_at' => $type === ActivityType::Note ? null : $dueAt,
            'done_at' => $done ? ($activity->done_at ?? now()) : null,
        ];

        foreach (['account_id', 'person_id', 'body', 'assigned_to'] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = is_string($validated[$field]) ? (trim($validated[$field]) === '' ? null : trim($validated[$field])) : $validated[$field];
            }
        }

        return DB::transaction(fn (): Activity => CurrentCompany::run($company, function () use ($user, $company, $attributes, $activity): Activity {
            if ($activity === null) {
                $activity = Activity::create([...$attributes, 'assigned_to' => $attributes['assigned_to'] ?? $user->id, 'created_by' => $user->id]);
                Audit::record('crm.activity_created', $activity, ['type' => $activity->type->value, 'account_id' => $activity->account_id, 'done' => ! $activity->isOpen()], $company, $user);
            } else {
                $before = $activity->only(['type', 'due_at', 'done_at', 'assigned_to', 'person_id', 'account_id']);
                $activity->fill($attributes)->save();
                Audit::record('crm.activity_updated', $activity, ['fields' => array_keys(Audit::changes(array_map(fn (mixed $value): mixed => $value instanceof \BackedEnum ? $value->value : $value, $before), array_map(fn (mixed $value): mixed => $value instanceof \BackedEnum ? $value->value : $value, $activity->only(array_keys($before)))))], $company, $user);
            }

            self::touch($activity);

            return $activity;
        }));
    }

    /** The account and the person were in contact now: the clock of the GDPR prune starts again. */
    public static function touch(Activity $activity): void
    {
        Account::query()->whereKey($activity->account_id)->update(['last_activity_at' => now()]);

        if ($activity->person_id !== null) {
            Person::query()->whereKey($activity->person_id)->update(['last_activity_at' => now()]);
        }
    }

    /**
     * "Kontakta inte" (ADR-043). After an objection (art. 21) only notes can
     * be written about the person: no new call, meeting, e-mail or task, and
     * no activity changed into one. After a restriction (art. 18) the data
     * may only be stored: one note documenting the restriction is allowed,
     * nothing more, and no activity is changed into another type.
     */
    private function guardDoNotContact(Company $company, ActivityType $type, ?int $personId, ?Activity $activity): void
    {
        if ($personId === null) {
            return;
        }

        $person = CurrentCompany::run($company, fn (): ?Person => Person::query()->whereKey($personId)->whereNotNull('do_not_contact_at')->first());

        if ($person === null) {
            return;
        }

        $isNew = $activity === null || $activity->person_id !== $personId;
        $typeChanged = $activity !== null && $activity->type !== $type;

        if ($type !== ActivityType::Note && ($isNew || $typeChanged)) {
            throw ValidationException::withMessages(['person_id' => __('Personen har bett att inte bli kontaktad. Du kan bara skriva en anteckning om personen.')]);
        }

        if ($person->do_not_contact_reason === DoNotContact::Restriction && $isNew
            && CurrentCompany::run($company, fn (): bool => Activity::query()->where('person_id', $personId)->where('type', ActivityType::Note->value)->where('created_at', '>=', $person->do_not_contact_at)->exists())) {
            throw ValidationException::withMessages(['person_id' => __('Behandlingen av personen är begränsad. Bara en anteckning om begränsningen får skrivas, och den finns redan.')]);
        }
    }
}
