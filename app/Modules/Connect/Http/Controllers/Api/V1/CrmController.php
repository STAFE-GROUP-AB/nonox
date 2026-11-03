<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Modules\Connect\Http\Controllers\Api\V1\Concerns\ActsAsCredential;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\SaveAccount;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\Actions\SetAccountStatus;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRM over the API (ADR-043, ADR-032): accounts, people and activities,
 * read with crm:view and written with crm:manage through the same Actions
 * as the screens. Both scopes are false while the module is off. Another
 * company's record answers 404.
 */
final class CrmController
{
    use ActsAsCredential;

    private const array ACCOUNT_FIELDS = ['name', 'org_number', 'kind', 'owner_id', 'parent_id', 'sni_code', 'website', 'email', 'phone', 'address_line1', 'postal_code', 'city', 'country', 'source', 'tags'];

    private const array PERSON_FIELDS = ['name', 'title', 'email', 'phone', 'mobile', 'legal_basis', 'legal_basis_note', 'consent_at', 'is_active', 'informed_at', 'do_not_contact', 'do_not_contact_reason', 'account_id', 'role'];

    private const array ACTIVITY_FIELDS = ['account_id', 'person_id', 'type', 'call_outcome', 'subject', 'body', 'assigned_to', 'due_at', 'done'];

    public function accounts(Request $request): JsonResponse
    {
        $query = Account::query()->with('people:crm_people.id');

        if ($request->filled('status')) {
            $query->where('status', strtoupper((string) $request->query('status')));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }

        return CursorList::respond($request, $query, fn (Account $account): array => self::presentAccount($account));
    }

    public function account(int $account): JsonResponse
    {
        return CursorList::one(self::presentAccount(Account::query()->with('people:crm_people.id')->findOrFail($account)));
    }

    public function storeAccount(Request $request, SaveAccount $save): JsonResponse
    {
        $account = $save($this->user(), $this->company(), array_intersect_key($request->json()->all(), array_flip(self::ACCOUNT_FIELDS)));

        return CursorList::one(self::presentAccount($account->refresh()->load('people:crm_people.id')), 201)
            ->header('Location', route('connect.api.crm.accounts.show', $account->id));
    }

    /**
     * Changes the given fields; the others keep their values. A status
     * (and lost_reason for LOST) sets the status by hand.
     */
    public function updateAccount(Request $request, int $account, SaveAccount $save, SetAccountStatus $status): JsonResponse
    {
        $model = Account::query()->findOrFail($account);
        $body = $request->json()->all();
        $current = [...$model->only(self::ACCOUNT_FIELDS), 'kind' => $model->kind->value];
        $changes = array_intersect_key($body, array_flip(self::ACCOUNT_FIELDS));

        $saved = DB::transaction(function () use ($model, $current, $changes, $body, $save, $status): Account {
            $saved = $changes === [] ? $model : $save($this->user(), $this->company(), [...$current, ...$changes], $model);

            if (isset($body['status'])) {
                $saved = $status($this->user(), $this->company(), $saved, (string) $body['status'], isset($body['lost_reason']) ? (string) $body['lost_reason'] : null);
            }

            return $saved;
        });

        return CursorList::one(self::presentAccount($saved->refresh()->load('people:crm_people.id')));
    }

    public function people(Request $request): JsonResponse
    {
        $query = Person::query()->with('accounts:crm_accounts.id');

        if ($request->filled('account_id')) {
            $query->whereHas('accounts', fn ($accounts) => $accounts->whereKey($request->integer('account_id')));
        }

        return CursorList::respond($request, $query, fn (Person $person): array => self::presentPerson($person));
    }

    public function person(int $person): JsonResponse
    {
        return CursorList::one(self::presentPerson(Person::query()->with('accounts:crm_accounts.id')->findOrFail($person)));
    }

    public function storePerson(Request $request, SavePerson $save): JsonResponse
    {
        $person = $save($this->user(), $this->company(), array_intersect_key($request->json()->all(), array_flip(self::PERSON_FIELDS)));

        return CursorList::one(self::presentPerson($person->refresh()->load('accounts:crm_accounts.id')), 201)
            ->header('Location', route('connect.api.crm.people.show', $person->id));
    }

    public function updatePerson(Request $request, int $person, SavePerson $save): JsonResponse
    {
        $model = Person::query()->findOrFail($person);
        $current = [...$model->only(['name', 'title', 'email', 'phone', 'mobile', 'legal_basis_note', 'is_active']), 'legal_basis' => $model->legal_basis->value, 'consent_at' => $model->consent_at?->toDateString(), 'informed_at' => $model->informed_at?->toDateString(), 'do_not_contact' => $model->doNotContact(), 'do_not_contact_reason' => $model->do_not_contact_reason?->value];
        $changes = array_intersect_key($request->json()->all(), array_flip(array_diff(self::PERSON_FIELDS, ['account_id', 'role'])));

        $saved = $save($this->user(), $this->company(), [...$current, ...$changes], $model);

        return CursorList::one(self::presentPerson($saved->refresh()->load('accounts:crm_accounts.id')));
    }

    public function activities(Request $request): JsonResponse
    {
        $query = Activity::query();

        if ($request->filled('account_id')) {
            $query->where('account_id', $request->integer('account_id'));
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->integer('assigned_to'));
        }

        if ($request->has('open')) {
            $request->boolean('open') ? $query->whereNull('done_at') : $query->whereNotNull('done_at');
        }

        return CursorList::respond($request, $query, fn (Activity $activity): array => self::presentActivity($activity));
    }

    public function activity(int $activity): JsonResponse
    {
        return CursorList::one(self::presentActivity(Activity::query()->findOrFail($activity)));
    }

    public function storeActivity(Request $request, LogActivity $log): JsonResponse
    {
        $activity = $log($this->user(), $this->company(), array_intersect_key($request->json()->all(), array_flip(self::ACTIVITY_FIELDS)));

        return CursorList::one(self::presentActivity($activity->refresh()), 201)
            ->header('Location', route('connect.api.crm.activities.show', $activity->id));
    }

    public function updateActivity(Request $request, int $activity, LogActivity $log): JsonResponse
    {
        $model = Activity::query()->findOrFail($activity);
        $saved = $log($this->user(), $this->company(), array_intersect_key($request->json()->all(), array_flip(self::ACTIVITY_FIELDS)), $model);

        return CursorList::one(self::presentActivity($saved->refresh()));
    }

    /** @return array<string, mixed> */
    public static function presentAccount(Account $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'kind' => $account->kind->value,
            'status' => $account->status->value,
            'lost_reason' => $account->lost_reason,
            'status_set_by_hand_at' => self::time($account->status_set_manually_at),
            'customer_id' => $account->customer_id,
            'org_number' => $account->org_number,
            'owner_id' => $account->owner_id,
            'parent_id' => $account->parent_id,
            'sni_code' => $account->sni_code,
            'website' => $account->website,
            'email' => $account->email,
            'phone' => $account->phone,
            'address_line1' => $account->address_line1,
            'postal_code' => $account->postal_code,
            'city' => $account->city,
            'country' => $account->country,
            'source' => $account->source,
            'tags' => $account->tags,
            'people' => $account->people->map(fn (Person $person): array => ['person_id' => $person->id, 'role' => $person->link->role->value])->values()->all(),
            'last_activity_at' => self::time($account->last_activity_at),
            'created_at' => self::time($account->created_at),
            'updated_at' => self::time($account->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function presentPerson(Person $person): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'title' => $person->title,
            'email' => $person->email,
            'phone' => $person->phone,
            'mobile' => $person->mobile,
            'legal_basis' => $person->legal_basis->value,
            'legal_basis_note' => $person->legal_basis_note,
            'consent_at' => $person->consent_at?->toDateString(),
            'is_active' => $person->is_active,
            'informed_at' => $person->informed_at?->toDateString(),
            'do_not_contact' => $person->doNotContact(),
            'do_not_contact_reason' => $person->do_not_contact_reason?->value,
            'accounts' => $person->accounts->map(fn (Account $account): array => ['account_id' => $account->id, 'role' => $account->link->role->value])->values()->all(),
            'created_at' => self::time($person->created_at),
            'updated_at' => self::time($person->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function presentActivity(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'account_id' => $activity->account_id,
            'person_id' => $activity->person_id,
            'type' => $activity->type->value,
            'call_outcome' => $activity->call_outcome?->value,
            'subject' => $activity->subject,
            'body' => $activity->body,
            'assigned_to' => $activity->assigned_to,
            'due_at' => self::time($activity->due_at),
            'done_at' => self::time($activity->done_at),
            'created_by' => $activity->created_by,
            'created_at' => self::time($activity->created_at),
            'updated_at' => self::time($activity->updated_at),
        ];
    }

    private static function time(?DateTimeInterface $time): ?string
    {
        return $time?->format(DATE_ATOM);
    }
}
