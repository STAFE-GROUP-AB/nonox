<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\DealStageChange;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates a deal, or changes an open one (ADR-043 stage 2). A new deal needs
 * its next step in the same form (`next_step`: type, subject, due_at,
 * assigned_to), created as an open activity linked to the deal in the same
 * transaction: no deal starts without one. The value is öre excluding VAT
 * (`value_ore`), or kronor as typed (`value`, "12 500" or "12 500,50").
 * Without a pipeline the deal goes into the default one, without a stage
 * into its first stage. A change of stage goes through MoveDeal, so the
 * history and the audit log see it. A won or lost deal is not changed here.
 */
final class SaveDeal
{
    public function __construct(
        private readonly LogActivity $log,
        private readonly MoveDeal $move,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input, ?Deal $deal = null): Deal
    {
        $deal === null
            ? Gate::forUser($user)->authorize('manage', [Deal::class, $company])
            : Gate::forUser($user)->authorize('update', $deal);

        if ($deal !== null && ! $deal->isOpen()) {
            throw ValidationException::withMessages(['deal' => __('Affären är avslutad och kan inte ändras.')]);
        }

        $input = $this->readValue($input);
        $creating = $deal === null;

        $validated = Validator::make($input, [
            'account_id' => [$creating ? 'required' : 'sometimes', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($company): void {
                if (! CurrentCompany::run($company, fn (): bool => Account::query()->whereKey((int) $value)->exists())) {
                    $fail(__('Kunden finns inte.'));
                }
            }],
            'person_id' => ['nullable', 'integer'],
            'pipeline_id' => ['nullable', 'integer'],
            'stage_id' => ['nullable', 'integer'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'value_ore' => ['sometimes', 'integer', 'min:0', 'max:'.Deal::MAX_VALUE_ORE],
            'expected_close_date' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'integer', Rule::in(CompanyMembers::ids($company))],
            'next_step' => [$creating ? 'required' : 'prohibited', 'array'],
            'next_step.type' => [$creating ? 'required' : 'sometimes', Rule::in(array_column(array_filter(ActivityType::cases(), fn (ActivityType $type): bool => $type->canBePlanned()), 'value'))],
            'next_step.subject' => ['nullable', 'string', 'max:255'],
            'next_step.due_at' => ['nullable', 'date'],
            'next_step.assigned_to' => ['nullable', 'integer'],
        ], [
            'owner_id.in' => __('Ansvarig säljare måste vara med i företaget.'),
            'next_step.required' => __('En ny affär behöver ett nästa steg, så att den inte glöms bort.'),
            'value_ore.min' => __('Värdet kan inte vara negativt.'),
            'value_ore.max' => __('Värdet är för stort.'),
        ], [
            'title' => __('rubrik'),
            'value_ore' => __('värde'),
            'expected_close_date' => __('förväntat avslut'),
            'next_step.type' => __('typ av nästa steg'),
            'next_step.due_at' => __('tid för nästa steg'),
        ])->validate();

        return DB::transaction(fn (): Deal => CurrentCompany::run($company, function () use ($user, $company, $validated, $deal, $creating): Deal {
            $accountId = (int) ($validated['account_id'] ?? $deal?->account_id);
            [$pipeline, $stage] = $this->placement($validated, $deal);
            $personId = array_key_exists('person_id', $validated) ? $validated['person_id'] : $deal?->person_id;

            $customer = Customer::query()->whereIn('id', Account::query()->whereKey($accountId)->select('customer_id'))->first();

            // As on the account card (canSell): no new business with a customer whose processing is restricted or who is inactive.
            if ($customer instanceof Customer && ($customer->isRestricted() || ! $customer->is_active)) {
                throw ValidationException::withMessages(['account_id' => __('Kunden är inaktiv eller har begränsad behandling. Affärer kan inte läggas upp eller ändras.')]);
            }

            if ($personId !== null && ! Account::query()->whereKey($accountId)->whereHas('people', fn ($people) => $people->whereKey((int) $personId))->exists()) {
                throw ValidationException::withMessages(['person_id' => __('Personen hör inte till kunden. Koppla personen till kunden först.')]);
            }

            $attributes = [
                'account_id' => $accountId,
                'person_id' => $personId === null ? null : (int) $personId,
                'title' => trim((string) ($validated['title'] ?? $deal?->title)),
                'value_ore' => (int) ($validated['value_ore'] ?? $deal->value_ore ?? 0),
                'expected_close_date' => array_key_exists('expected_close_date', $validated)
                    ? (blank($validated['expected_close_date']) ? null : substr((string) $validated['expected_close_date'], 0, 10))
                    : $deal?->expected_close_date?->toDateString(),
                'owner_id' => array_key_exists('owner_id', $validated) ? $validated['owner_id'] : ($deal->owner_id ?? $user->id),
            ];

            if ($creating) {
                $deal = Deal::create([...$attributes, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'created_by' => $user->id]);
                DealStageChange::create(['deal_id' => $deal->id, 'from_stage_id' => null, 'to_stage_id' => $stage->id, 'user_id' => $user->id, 'changed_at' => now()]);
                Audit::record('crm.deal_created', $deal, ['deal_id' => $deal->id, 'account_id' => $deal->account_id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id], $company, $user);

                /** @var array<string, mixed> $next */
                $next = $validated['next_step'];
                ($this->log)($user, $company, [
                    ...array_intersect_key($next, array_flip(['type', 'subject', 'due_at', 'assigned_to'])),
                    'assigned_to' => $next['assigned_to'] ?? $deal->owner_id ?? $user->id,
                    'account_id' => $deal->account_id,
                    // A contact person who must not be contacted is on the deal, but not on its calls and meetings.
                    'person_id' => $deal->person_id !== null && Person::query()->whereKey($deal->person_id)->whereNull('do_not_contact_at')->exists() ? $deal->person_id : null,
                    'deal_id' => $deal->id,
                    'done' => false,
                ]);
            } else {
                $snapshot = fn (Deal $deal): array => [...$deal->only(array_keys($attributes)), 'expected_close_date' => $deal->expected_close_date?->toDateString()];
                $before = $snapshot($deal);
                $deal->fill($attributes)->save();
                Audit::record('crm.deal_updated', $deal, ['deal_id' => $deal->id, 'fields' => array_keys(Audit::changes($before, $snapshot($deal))['after'])], $company, $user);

                if ($stage->id !== $deal->stage_id) {
                    $deal = ($this->move)($user, $company, $deal, $stage);
                }
            }

            Account::query()->whereKey($deal->account_id)->update(['last_activity_at' => now()]);

            return $deal;
        }));
    }

    /**
     * The pipeline and stage the deal goes into: the given ones, or the
     * deal's own, or the default pipeline's first stage. An archived
     * pipeline takes no new deals.
     *
     * @param  array<string, mixed>  $validated
     * @return array{Pipeline, PipelineStage}
     */
    private function placement(array $validated, ?Deal $deal): array
    {
        $stageId = $validated['stage_id'] ?? null;
        $pipelineId = $validated['pipeline_id'] ?? null;

        if ($stageId !== null) {
            $stage = PipelineStage::query()->find((int) $stageId);

            if (! $stage instanceof PipelineStage || ($pipelineId !== null && $stage->pipeline_id !== (int) $pipelineId)) {
                throw ValidationException::withMessages(['stage_id' => __('Steget finns inte i säljtratten.')]);
            }

            $pipelineId = $stage->pipeline_id;
        }

        // Locked, so ArchivePipeline cannot archive it while this deal goes in.
        $pipeline = match (true) {
            $pipelineId !== null => Pipeline::query()->lockForUpdate()->find((int) $pipelineId),
            $deal !== null => Pipeline::query()->lockForUpdate()->find($deal->pipeline_id),
            default => Pipeline::query()->active()->ordered()->lockForUpdate()->first(),
        };

        if (! $pipeline instanceof Pipeline) {
            throw ValidationException::withMessages(['pipeline_id' => __('Säljtratten finns inte. Lägg till en under CRM → Inställningar.')]);
        }

        if ($pipeline->isArchived() && ($deal === null || $deal->pipeline_id !== $pipeline->id)) {
            throw ValidationException::withMessages(['pipeline_id' => __('Säljtratten är arkiverad och tar inte emot nya affärer.')]);
        }

        $stage ??= $deal !== null && $deal->pipeline_id === $pipeline->id
            ? PipelineStage::query()->find($deal->stage_id)
            : $pipeline->stages()->first();

        if (! $stage instanceof PipelineStage) {
            throw ValidationException::withMessages(['stage_id' => __('Säljtratten har inga steg. Lägg till ett under CRM → Inställningar.')]);
        }

        return [$pipeline, $stage];
    }

    /**
     * `value` as typed, in kronor, becomes value_ore.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function readValue(array $input): array
    {
        if (! array_key_exists('value', $input) || array_key_exists('value_ore', $input)) {
            return $input;
        }

        $value = trim((string) $input['value']);
        unset($input['value']);

        if ($value === '') {
            return [...$input, 'value_ore' => 0];
        }

        try {
            return [...$input, 'value_ore' => Money::parse($value)->ore];
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['value' => __('Skriv värdet i kronor, till exempel 12 500.')]);
        }
    }
}
