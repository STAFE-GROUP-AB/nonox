<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\RegistryCompany;
use App\Models\User;
use App\Modules\Crm\Domain\ProspectSearch;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Support\SuppressionList;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Adds companies from the register as prospects (ADR-047), through
 * SaveAccount with source "Företagsregistret". A company that already is
 * an account (same organisation number) is not added again but counted as
 * existing, so a call list can still include it. A company whose register
 * phone or e-mail is on the company's suppression list (someone objected,
 * ADR-043) is not added. Only legal persons are in the register; no person
 * data is copied.
 */
final class AddProspects
{
    public const string SOURCE = 'Företagsregistret';

    public function __construct(private readonly SaveAccount $saveAccount) {}

    /**
     * @param  list<string>  $orgNumbers  ten digits each
     * @return array{added: list<int>, existing: list<int>, suppressed: int, missing: int}
     */
    public function __invoke(User $user, Company $company, array $orgNumbers, ?int $ownerId = null): array
    {
        Gate::forUser($user)->authorize('manage', [Account::class, $company]);

        $orgNumbers = array_values(array_unique(array_filter(array_map(fn (string $number): ?string => RegistryCompany::digits($number), $orgNumbers))));

        if ($orgNumbers === []) {
            throw ValidationException::withMessages(['selection' => __('Välj minst ett företag.')]);
        }

        if (count($orgNumbers) > ProspectSearch::MAX_ADD) {
            throw ValidationException::withMessages(['selection' => __('Högst :max företag åt gången.', ['max' => ProspectSearch::MAX_ADD])]);
        }

        return DB::transaction(fn (): array => CurrentCompany::run($company, function () use ($user, $company, $orgNumbers, $ownerId): array {
            $register = RegistryCompany::query()->whereIn('org_number', $orgNumbers)->get()->keyBy('org_number');
            $existing = Account::query()
                ->whereNotNull('org_number')
                ->whereIn(DB::raw("regexp_replace(org_number, '\\D', '', 'g')"), $orgNumbers)
                ->get(['id', 'org_number'])
                ->mapWithKeys(fn (Account $account): array => [(string) preg_replace('/\D/', '', (string) $account->org_number) => $account->id]);

            $added = [];
            $found = [];
            $suppressed = 0;
            $missing = 0;

            foreach ($orgNumbers as $org) {
                if ($existing->has($org)) {
                    $found[] = (int) $existing->get($org);

                    continue;
                }

                $row = $register->get($org);

                if (! $row instanceof RegistryCompany) {
                    $missing++;

                    continue;
                }

                if (($row->phone !== null || $row->email !== null) && SuppressionList::match($row->email, $row->phone)) {
                    $suppressed++;

                    continue;
                }

                $account = ($this->saveAccount)($user, $company, [
                    'name' => $row->name,
                    'org_number' => $row->formattedOrgNumber(),
                    'kind' => 'COMPANY',
                    'owner_id' => $ownerId ?? $user->id,
                    'sni_code' => $row->sni_code,
                    'phone' => $row->hasPhoneBlock() ? null : $row->phone,
                    'email' => $row->email_block || filter_var((string) $row->email, FILTER_VALIDATE_EMAIL) === false ? null : $row->email,
                    'address_line1' => $row->address_line1,
                    'postal_code' => $row->postal_code === null ? null : (strlen($row->postal_code) === 5 ? substr($row->postal_code, 0, 3).' '.substr($row->postal_code, 3) : $row->postal_code),
                    'city' => $row->town(),
                    'source' => self::SOURCE,
                ]);
                $added[] = $account->id;
            }

            // Counts only: the append-only log keeps no names or numbers.
            Audit::record('crm.prospects_added', null, ['added' => count($added), 'existing' => count($found), 'suppressed' => $suppressed], $company, $user);

            return ['added' => $added, 'existing' => $found, 'suppressed' => $suppressed, 'missing' => $missing];
        }));
    }
}
