<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Company\OrganisationNumber;
use App\Domain\Group\ControlBasis;
use App\Models\Company;
use App\Models\CompanyHolding;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Records or changes shares the company holds in another legal person
 * (ADR-060, addendum 2026-11-07, 2.1): the holder's own register, written
 * with company:update (owner, administrator). The held company is named by
 * organisation number and name only. The control basis is entered by a
 * person and never derived from the voting share (S2-R1d).
 */
final class RecordHolding
{
    /**
     * @param  array<string, mixed>  $input  held_org_number, held_name, capital and voting (percent, "100" or "62,5"),
     *                                       control_basis, acquired_on and disposed_on (Y-m-d), common_control (bool or null)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, array $input, ?int $holdingId = null): CompanyHolding
    {
        if (! $user->hasTeamPermission($company, 'company:update')) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, function () use ($user, $company, $input, $holdingId): CompanyHolding {
            // Inside the company: another company's holding id answers 404.
            $holding = $holdingId === null ? null : CompanyHolding::query()->findOrFail($holdingId);
            $attributes = $this->validate($company, $input);

            return DB::transaction(function () use ($user, $holding, $attributes): CompanyHolding {
                $this->assertNoOtherActiveHolding($attributes, $holding);

                $before = $holding === null ? [] : $this->auditable($holding);
                $holding ??= new CompanyHolding;
                $holding->fill($attributes);
                // Field names only for the name and number: the log never keeps them as values.
                $changed = array_values(array_diff(array_keys($holding->getDirty()), ['company_id']));

                if ($before !== [] && $changed === []) {
                    return $holding;
                }

                $holding->save();

                Audit::record($before === [] ? 'company_holding.created' : 'company_holding.updated', $holding, [
                    ...Audit::changes($before, $this->auditable($holding)),
                    'changed' => $changed,
                ], $holding->company_id, $user);

                return $holding;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(Company $company, array $input): array
    {
        $validated = Validator::make($input, [
            'held_org_number' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($company): void {
                $number = OrganisationNumber::tryFrom((string) $value);

                if ($number === null) {
                    $fail(__('Organisationsnumret stämmer inte. Kontrollera siffrorna.'));

                    return;
                }

                if ($number->isPersonalIdentityNumber()) {
                    $fail(__('Ange organisationsnumret för ett aktiebolag eller en annan juridisk person, inte ett personnummer.'));

                    return;
                }

                if ($number->formatted() === OrganisationNumber::tryFrom((string) $company->org_number)?->formatted()) {
                    $fail(__('Ett företag kan inte registrera ett innehav i sig självt.'));
                }
            }],
            'held_name' => ['required', 'string', 'max:255'],
            'capital' => ['required'],
            'voting' => ['required'],
            'control_basis' => ['required', Rule::enum(ControlBasis::class)],
            'acquired_on' => ['required', 'date_format:Y-m-d'],
            'disposed_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:acquired_on'],
            'common_control' => ['nullable', 'boolean'],
        ], [
            'control_basis.required' => __('Välj vad det bestämmande inflytandet grundar sig på.'),
            'disposed_on.after_or_equal' => __('Avyttringsdagen kan inte vara före förvärvsdagen.'),
        ], [
            'held_org_number' => __('organisationsnummer'),
            'held_name' => __('namn'),
            'capital' => __('andel av kapitalet'),
            'voting' => __('andel av rösterna'),
            'acquired_on' => __('förvärvsdag'),
            'disposed_on' => __('avyttringsdag'),
        ])->validate();

        $commonControl = $validated['common_control'] ?? null;

        return [
            'held_org_number' => OrganisationNumber::tryFrom((string) $validated['held_org_number'])?->formatted(),
            'held_name' => trim((string) $validated['held_name']),
            'capital_bp' => $this->share('capital', $validated['capital']),
            'voting_bp' => $this->share('voting', $validated['voting']),
            'control_basis' => ControlBasis::from((string) $validated['control_basis']),
            'acquired_on' => CarbonImmutable::parse((string) $validated['acquired_on']),
            'disposed_on' => isset($validated['disposed_on']) ? CarbonImmutable::parse((string) $validated['disposed_on']) : null,
            'common_control' => $commonControl === null ? null : (bool) $commonControl,
        ];
    }

    /**
     * One active holding per held company and holder; a change is made on the existing one.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertNoOtherActiveHolding(array $attributes, ?CompanyHolding $holding): void
    {
        if ($attributes['disposed_on'] !== null) {
            return;
        }

        $other = CompanyHolding::query()
            ->where('held_org_number', $attributes['held_org_number'])
            ->whereNull('disposed_on')
            ->when($holding, fn ($query) => $query->whereKeyNot($holding?->id))
            ->lockForUpdate()
            ->exists();

        if ($other) {
            throw ValidationException::withMessages(['held_org_number' => __('Företaget har redan ett innehav i det här bolaget. Ändra det i stället.')]);
        }
    }

    private function share(string $field, mixed $value): int
    {
        $normal = str_replace([',', ' ', '%'], ['.', '', ''], trim((string) $value));

        if (! is_numeric($normal) || (float) $normal < 0 || (float) $normal > 100) {
            throw ValidationException::withMessages([$field => __('Andelen ska vara mellan 0 och 100 procent.')]);
        }

        return (int) round((float) $normal * 100);
    }

    /**
     * What the log keeps: shares, basis and dates; never the held company's name or number.
     *
     * @return array<string, mixed>
     */
    private function auditable(CompanyHolding $holding): array
    {
        return [
            'capital_bp' => $holding->capital_bp,
            'voting_bp' => $holding->voting_bp,
            'control_basis' => $holding->control_basis->value,
            'acquired_on' => $holding->acquired_on->toDateString(),
            'disposed_on' => $holding->disposed_on?->toDateString(),
            'common_control' => $holding->common_control,
        ];
    }
}
