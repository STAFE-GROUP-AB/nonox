<?php

declare(strict_types=1);

namespace App\Actions\Hq;

use App\Domain\Company\OrganisationNumber;
use App\Models\RegistryCompany;
use App\Models\RegistryExclusion;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\CompanyRegistry\Import\RegistryWriter;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * An objection or erasure request about the instance's company register
 * (ADR-047, GDPR art. 21 and 17). The data is removed from the register at
 * once and every later import leaves it out (RegistryWriter). Only an HQ
 * admin (the caller checks HqAccess and the passkey) or the console
 * (registry:exclude). The audit log keeps the exclusion's id, the field and
 * the reason code, never the organisation number or a name.
 */
final class ExcludeFromRegistry
{
    /**
     * @param  array<string, mixed>  $input  org_number, field (ALL|NAME|PHONE|EMAIL), reason (OBJECTION|ERASURE)
     */
    public function __invoke(?User $by, array $input): RegistryExclusion
    {
        $validated = Validator::make($input, [
            'org_number' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $number = OrganisationNumber::tryFrom((string) $value);
                $digits = RegistryCompany::digits((string) $value);

                if ($number === null || $digits === null || (int) $digits[2] < 2) {
                    $fail(__('Ange ett giltigt organisationsnummer för ett företag (inte ett personnummer).'));
                }
            }],
            'field' => ['required', Rule::in(RegistryExclusion::FIELDS)],
            'reason' => ['required', Rule::in(RegistryExclusion::REASONS)],
        ], [], ['org_number' => __('organisationsnummer')])->validate();

        $org = (string) RegistryCompany::digits((string) $validated['org_number']);
        $field = (string) $validated['field'];

        return DB::transaction(function () use ($by, $org, $field, $validated): RegistryExclusion {
            $exclusion = RegistryExclusion::query()->firstOrCreate(
                ['org_number' => $org, 'field' => $field],
                ['reason' => $validated['reason'], 'created_by' => $by?->id],
            );

            match ($field) {
                RegistryExclusion::ALL => RegistryWriter::forget([$org]),
                RegistryExclusion::NAME => $this->replaceName($org),
                RegistryExclusion::PHONE => DB::table('company_registry')->where('org_number', $org)->update(['phone' => null, 'updated_at' => now()]),
                default => DB::table('company_registry')->where('org_number', $org)->update(['email' => null, 'updated_at' => now()]),
            };

            Audit::instance('hq.registry_exclusion_added', $exclusion, ['exclusion_id' => $exclusion->id, 'field' => $field, 'reason' => $exclusion->reason], $by);

            return $exclusion;
        });
    }

    public function remove(?User $by, RegistryExclusion $exclusion): void
    {
        DB::transaction(function () use ($by, $exclusion): void {
            Audit::instance('hq.registry_exclusion_removed', $exclusion, ['exclusion_id' => $exclusion->id, 'field' => $exclusion->field], $by);
            $exclusion->delete();
        });
    }

    private function replaceName(string $org): int
    {
        DB::table('company_registry_changes')->where('org_number', $org)->where('field', 'name')->delete();

        return DB::table('company_registry')->where('org_number', $org)->update(['name' => RegistryExclusion::replacementName($org), 'updated_at' => now()]);
    }
}
