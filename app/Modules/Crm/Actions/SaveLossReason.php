<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Models\LossReason;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Adds a loss reason to the company's list, or renames, archives or takes
 * back one (ADR-043 stage 2). An archived reason stays on the deals lost
 * for it, so the figures keep their history, but is not offered again.
 */
final class SaveLossReason
{
    /**
     * @param  array<string, mixed>  $input  name, archived
     */
    public function __invoke(User $user, Company $company, array $input, ?LossReason $reason = null): LossReason
    {
        $reason === null
            ? Gate::forUser($user)->authorize('settings', [LossReason::class, $company])
            : Gate::forUser($user)->authorize('configure', $reason);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100', Rule::unique('crm_loss_reasons', 'name')->where('company_id', $company->id)->ignore($reason?->id)],
            'archived' => ['sometimes', 'boolean'],
        ], [
            'name.unique' => __('Orsaken finns redan.'),
        ], ['name' => __('orsak')])->validate();

        return DB::transaction(fn (): LossReason => CurrentCompany::run($company, function () use ($user, $company, $validated, $reason): LossReason {
            $creating = $reason === null;
            $reason ??= new LossReason(['sort' => (int) LossReason::query()->max('sort') + 1]);
            $archived = (bool) ($validated['archived'] ?? $reason->archived_at !== null);

            $reason->fill(['name' => trim((string) $validated['name'])])
                ->forceFill(['archived_at' => $archived ? ($reason->archived_at ?? now()) : null])
                ->save();

            Audit::record($creating ? 'crm.loss_reason_created' : 'crm.loss_reason_updated', $reason, ['loss_reason_id' => $reason->id, 'archived' => $archived], $company, $user);

            return $reason;
        }));
    }
}
