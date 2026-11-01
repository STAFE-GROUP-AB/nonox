<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollFileImport;
use App\Modules\Payroll\Models\PayrollImportMapping;
use App\Modules\Payroll\Models\PayrollImportSource;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The systems a company reads payroll files from, and per system which of
 * the company's pay codes each external code (löneart or tidkod) becomes,
 * or that its lines are left out. A mapping is saved once and used for
 * every later file from the same system. Changed in the payroll module's
 * settings (payroll:settings) or while reading a file into a payroll run
 * (payroll:run). Every change is audited.
 */
class PayrollImportSources
{
    /** Pay codes that a file never feeds: absence, sick pay, vacation and pension are calculated by the run. */
    public const array EXCLUDED_CATEGORIES = ['SICK', 'ABSENCE', 'VACATION', 'PENSION', 'ALLOWANCE_TAXABLE'];

    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * The pay codes an external code can become, for the mapping lists.
     *
     * @return Collection<int, PayCode>
     */
    public static function payCodes(): Collection
    {
        return PayCode::query()
            ->where('is_active', true)
            ->whereNotIn('category', self::EXCLUDED_CATEGORIES)
            ->orderBy('code')
            ->get(['id', 'code', 'name_sv', 'unit']);
    }

    public function create(User $user, Company $company, string $name): PayrollImportSource
    {
        $this->authorize($user, $company);
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withMessages(['source_name' => __('Skriv systemets namn, till exempel Personalkollen.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $name): PayrollImportSource {
            $existing = PayrollImportSource::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

            if ($existing !== null) {
                return $existing;
            }

            $source = PayrollImportSource::create(['name' => $name]);
            Audit::record('payroll.import_source_created', $source, ['name' => $name], $company, $user);

            return $source;
        });
    }

    /**
     * Adds or changes the mapping of one external code: to a pay code, or
     * left out ($payCodeId null and $ignored true). A code can be mapped
     * before any file has used it.
     */
    public function map(User $user, Company $company, PayrollImportSource $source, string $externalCode, ?int $payCodeId, bool $ignored = false): PayrollImportMapping
    {
        $this->authorize($user, $company);
        $code = mb_strtoupper(trim($externalCode));

        if ($code === '' || mb_strlen($code) > 40) {
            throw ValidationException::withMessages(['external_code' => __('Skriv koden som den står i filen, högst 40 tecken.')]);
        }

        if ($payCodeId === null && ! $ignored) {
            throw ValidationException::withMessages(['pay_code_id' => __('Välj vilken löneart koden blir, eller att raderna inte läses in.')]);
        }

        return CurrentCompany::run($company, fn (): PayrollImportMapping => DB::transaction(function () use ($user, $company, $source, $code, $payCodeId, $ignored): PayrollImportMapping {
            $source = PayrollImportSource::query()->findOrFail($source->id);

            if ($payCodeId !== null) {
                self::payCodes()->firstWhere('id', $payCodeId) ?? throw ValidationException::withMessages(['pay_code_id' => __('Lönearten kan inte användas för inlästa rader.')]);
            }

            $mapping = PayrollImportMapping::query()->firstOrNew(['payroll_import_source_id' => $source->id, 'external_code' => $code]);
            $before = $mapping->exists ? ['pay_code_id' => $mapping->pay_code_id, 'ignored' => $mapping->ignored] : null;
            $mapping->fill(['pay_code_id' => $ignored ? null : $payCodeId, 'ignored' => $ignored])->save();

            Audit::record('payroll.import_mapping_saved', $source, [
                'external_code' => $code,
                'before' => $before,
                'after' => ['pay_code_id' => $mapping->pay_code_id, 'ignored' => $mapping->ignored],
            ], $company, $user);

            return $mapping;
        }));
    }

    public function forget(User $user, Company $company, PayrollImportMapping $mapping): void
    {
        $this->authorize($user, $company, settingsOnly: true);

        CurrentCompany::run($company, function () use ($user, $company, $mapping): void {
            $mapping = PayrollImportMapping::query()->findOrFail($mapping->id);
            $mapping->delete();
            Audit::record('payroll.import_mapping_removed', $mapping, ['external_code' => $mapping->external_code], $company, $user);
        });
    }

    /** A system that no file has come from can be removed, with its mappings. */
    public function delete(User $user, Company $company, PayrollImportSource $source): void
    {
        $this->authorize($user, $company, settingsOnly: true);

        CurrentCompany::run($company, function () use ($user, $company, $source): void {
            $source = PayrollImportSource::query()->findOrFail($source->id);

            if (PayrollFileImport::query()->where('payroll_import_source_id', $source->id)->exists()) {
                throw ValidationException::withMessages(['source' => __(':name har lästa filer och kan inte tas bort.', ['name' => $source->name])]);
            }

            $source->delete();
            Audit::record('payroll.import_source_deleted', $source, ['name' => $source->name], $company, $user);
        });
    }

    private function authorize(User $user, Company $company, bool $settingsOnly = false): void
    {
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);

        $allowed = $user->hasTeamPermission($company, 'payroll:settings')
            || (! $settingsOnly && $user->hasTeamPermission($company, 'payroll:run'));

        if (! $allowed) {
            throw new AuthorizationException;
        }
    }
}
