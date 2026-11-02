<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Agreements\Domain\LocalOverrides;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\CollectiveAgreement;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The company's local deviations from a central agreement (ADR-033 §3):
 * recorded by a person with agreements:manage, by hand or by accepting a
 * proposal LocalAgreementAgent read from the local agreement, and used in
 * a payroll run only after a person with agreements:verify has verified
 * them against the local agreement. Kept in collective_agreements.overrides
 * by path; every change is audited. The central values are never changed.
 */
class LocalAgreements
{
    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * Records (or replaces) one deviation, unverified.
     *
     * @param  ?array{document_id?: ?int, page?: ?int, origin?: string, description?: ?string}  $from
     */
    public function record(User $user, Company $company, CollectiveAgreement $binding, string $path, mixed $value, ?array $from = null): CollectiveAgreement
    {
        $this->authorize($user, $company, 'agreements:manage');

        return CurrentCompany::run($company, fn (): CollectiveAgreement => DB::transaction(function () use ($user, $company, $binding, $path, $value, $from): CollectiveAgreement {
            /** @var CollectiveAgreement $binding */
            $binding = CollectiveAgreement::query()->lockForUpdate()->findOrFail($binding->id);
            $rules = self::rules($binding) ?? throw ValidationException::withMessages(['override' => [__('Det finns ingen avtalsdata för bindningen.')]]);
            $problem = $value === null ? __('Ange värdet som det lokala avtalet säger.') : LocalOverrides::problem($path, $value, $rules);

            if ($problem !== null) {
                throw ValidationException::withMessages(['override' => [$problem]]);
            }

            $page = (int) ($from['page'] ?? 0);
            $overrides = (array) $binding->overrides;
            $before = $overrides[$path] ?? null;
            $overrides[$path] = array_filter([
                'value' => $value,
                'label' => LocalOverrides::label($path),
                'description' => isset($from['description']) ? mb_substr((string) $from['description'], 0, 500) : null,
                'source' => LocalOverrides::SOURCE_ID.($page > 0 ? "#s. {$page}" : ''),
                'document_id' => $from['document_id'] ?? null,
                'origin' => $from['origin'] ?? 'MANUAL',
                'recorded_by' => $user->id,
                'recorded_at' => now()->toIso8601String(),
                'applied' => LocalOverrides::applied($path),
            ], fn (mixed $item): bool => $item !== null);
            $binding->forceFill(['overrides' => $overrides])->save();

            Audit::record('agreements.local_override_recorded', $binding, ['path' => $path, 'before' => $before['value'] ?? null, 'after' => $value, 'origin' => $overrides[$path]['origin']], $company, $user);

            return $binding;
        }));
    }

    /** Marks a deviation as checked against the local agreement; from then on runs use it. */
    public function verify(User $user, Company $company, CollectiveAgreement $binding, string $path): CollectiveAgreement
    {
        $this->authorize($user, $company, 'agreements:verify');

        return CurrentCompany::run($company, fn (): CollectiveAgreement => DB::transaction(function () use ($user, $company, $binding, $path): CollectiveAgreement {
            /** @var CollectiveAgreement $binding */
            $binding = CollectiveAgreement::query()->lockForUpdate()->findOrFail($binding->id);
            $overrides = (array) $binding->overrides;

            if (! isset($overrides[$path]) || ! is_array($overrides[$path])) {
                throw ValidationException::withMessages(['override' => [__('Avvikelsen finns inte.')]]);
            }

            $overrides[$path] = [...$overrides[$path], 'verified_by' => $user->name, 'verified_by_id' => $user->id, 'verified_at' => now()->toIso8601String()];
            $binding->forceFill(['overrides' => $overrides])->save();
            Audit::record('agreements.local_override_verified', $binding, ['path' => $path, 'value' => $overrides[$path]['value'] ?? null], $company, $user);

            return $binding;
        }));
    }

    public function remove(User $user, Company $company, CollectiveAgreement $binding, string $path): CollectiveAgreement
    {
        $this->authorize($user, $company, 'agreements:manage');

        return CurrentCompany::run($company, fn (): CollectiveAgreement => DB::transaction(function () use ($user, $company, $binding, $path): CollectiveAgreement {
            /** @var CollectiveAgreement $binding */
            $binding = CollectiveAgreement::query()->lockForUpdate()->findOrFail($binding->id);
            $overrides = (array) $binding->overrides;
            $before = $overrides[$path] ?? null;
            unset($overrides[$path]);
            $binding->forceFill(['overrides' => $overrides])->save();
            Audit::record('agreements.local_override_removed', $binding, ['path' => $path, 'before' => is_array($before) ? ($before['value'] ?? null) : null], $company, $user);

            return $binding;
        }));
    }

    /** The newest version's rules for a binding, to check a deviation against. */
    public static function rules(CollectiveAgreement $binding): ?AgreementRules
    {
        return $binding->agreement_key === null ? null : AgreementRuleVersion::query()
            ->where('agreement_key', $binding->agreement_key)
            ->where('variant', $binding->variant)
            ->orderByDesc('valid_from')
            ->first()
            ?->agreementRules();
    }

    private function authorize(User $user, Company $company, string $permission): void
    {
        $this->modules->ensureEnabled(AgreementsModule::KEY, $company);

        if (! $user->hasTeamPermission($company, $permission)) {
            throw new AuthorizationException;
        }
    }
}
