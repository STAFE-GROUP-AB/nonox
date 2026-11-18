<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Domain\Projects\MilestoneStatus;
use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectBillingMilestone;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or changes a milestone of a project's billing plan (ADR-081 gap 6,
 * E8): a description, an optional date, and either an amount excluding VAT
 * or a share of the contract sum, planned or ready to invoice. An invoiced
 * milestone cannot be changed. A change is made only to the version the
 * person saw (its token is compared under the row lock). The shares of a
 * plan together never pass 100 %. The audit entry holds ids, field names,
 * dates, amounts and enums only.
 */
class SaveBillingMilestone
{
    /**
     * @param  array<string, mixed>  $input  description, due_on (Y-m-d, optional), kind (AMOUNT or SHARE), amount (kronor as typed), share (percent as typed), status (PLANNED or READY)
     */
    public function __invoke(User $user, Company $company, Project $project, array $input, ?ProjectBillingMilestone $milestone = null, ?string $token = null): ProjectBillingMilestone
    {
        Gate::forUser($user)->authorize('manageBillingPlan', $project);

        $validated = Validator::make($input, [
            'description' => ['required', 'string', 'max:255'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'kind' => ['required', Rule::in(['AMOUNT', 'SHARE'])],
            'amount' => ['nullable', 'string', 'max:30'],
            'share' => ['nullable', 'string', 'max:10'],
            'status' => ['required', Rule::in([MilestoneStatus::Planned->value, MilestoneStatus::Ready->value])],
        ], [
            'description.required' => __('Skriv vad delfaktureringen gäller.'),
            'due_on.date_format' => __('Datumet ska skrivas som ÅÅÅÅ-MM-DD.'),
        ], [
            'description' => __('beskrivning'),
            'due_on' => __('datum'),
            'status' => __('status'),
        ])->validate();

        $due = null;

        if (filled($validated['due_on'] ?? null)) {
            $due = CarbonImmutable::createFromFormat('!Y-m-d', (string) $validated['due_on']);

            if (! $due instanceof CarbonImmutable || $due->toDateString() !== $validated['due_on']) {
                throw ValidationException::withMessages(['due_on' => __('Datumet finns inte.')]);
            }
        }

        $amount = $validated['kind'] === 'AMOUNT' ? self::amount($validated['amount'] ?? null) : null;
        $share = $validated['kind'] === 'SHARE' ? self::share($validated['share'] ?? null) : null;

        return DB::transaction(fn (): ProjectBillingMilestone => CurrentCompany::run($company, function () use ($user, $company, $project, $milestone, $token, $validated, $due, $amount, $share): ProjectBillingMilestone {
            /** @var Project $locked */
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if ($share !== null && $locked->contract_sum_ore === null) {
                throw ValidationException::withMessages(['share' => __('En andel kräver en kontraktssumma i projektregistret.')]);
            }

            $current = null;

            if ($milestone !== null) {
                $current = ProjectBillingMilestone::query()->where('project_id', $locked->id)->whereKey($milestone->id)->lockForUpdate()->firstOrFail();

                if ($token === null || ! hash_equals($current->versionToken(), $token)) {
                    throw ValidationException::withMessages(['description' => __('Delfaktureringen har ändrats sedan du öppnade den. Öppna den igen.')]);
                }

                if ($current->effectiveStatus() === MilestoneStatus::Invoiced) {
                    throw ValidationException::withMessages(['description' => __('Delfaktureringen är redan fakturerad och kan inte ändras.')]);
                }
            }

            if ($share !== null) {
                $others = (int) ProjectBillingMilestone::query()->where('project_id', $locked->id)
                    ->when($current instanceof ProjectBillingMilestone, fn ($query) => $query->whereKeyNot((int) $current?->id))
                    ->sum('share_bp');

                if ($others + $share > 10_000) {
                    throw ValidationException::withMessages(['share' => __('Andelarna i planen blir tillsammans mer än 100 %.')]);
                }
            }

            $attributes = [
                'description' => trim((string) $validated['description']),
                'due_on' => $due?->toDateString(),
                'amount_ore' => $amount,
                'share_bp' => $share,
                'status' => (string) $validated['status'],
                // A milestone freed by a deleted draft invoice starts again without its old status.
                'invoice_id' => null,
            ];

            if ($current === null) {
                $position = (int) ProjectBillingMilestone::query()->where('project_id', $locked->id)->max('position') + 1;
                $saved = ProjectBillingMilestone::query()->create([...$attributes, 'project_id' => $locked->id, 'position' => $position, 'created_by' => $user->id]);
                Audit::record('projects.milestone_created', $saved, ['project_id' => $locked->id, 'amount_ore' => $amount, 'share_bp' => $share, 'status' => $saved->status->value, 'due_on' => $saved->due_on?->toDateString()], $company, $user);

                return $saved;
            }

            $before = self::details($current);
            $current->update($attributes);
            Audit::record('projects.milestone_updated', $current, ['project_id' => $locked->id, ...Audit::changes($before, self::details($current))], $company, $user);

            return $current;
        }));
    }

    /** @return array<string, int|string|null> */
    private static function details(ProjectBillingMilestone $milestone): array
    {
        return [
            'due_on' => $milestone->due_on?->toDateString(),
            'amount_ore' => $milestone->amount_ore?->ore,
            'share_bp' => $milestone->share_bp,
            'status' => $milestone->status->value,
        ];
    }

    private static function amount(mixed $value): int
    {
        $text = trim((string) $value);
        $unreadable = ValidationException::withMessages(['amount' => __('Skriv beloppet exklusive moms, till exempel 25 000 eller 25 000,00.')]);

        if ($text === '') {
            throw $unreadable;
        }

        try {
            $money = Money::parse($text);
        } catch (InvalidArgumentException) {
            throw $unreadable;
        }

        if ($money->ore <= 0) {
            throw ValidationException::withMessages(['amount' => __('Beloppet ska vara större än noll.')]);
        }

        return $money->ore;
    }

    /** A percentage as typed (25, 12,5 or 12.50) in basis points. */
    private static function share(mixed $value): int
    {
        $text = str_replace([' ', '%'], '', trim((string) $value));

        if (preg_match('/^(\d{1,3})(?:[.,](\d{1,2}))?$/', $text, $match) !== 1) {
            throw ValidationException::withMessages(['share' => __('Skriv andelen i procent, till exempel 25 eller 12,5.')]);
        }

        $bp = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');

        if ($bp < 1 || $bp > 10_000) {
            throw ValidationException::withMessages(['share' => __('Andelen ska vara mellan 0,01 och 100 %.')]);
        }

        return $bp;
    }
}
