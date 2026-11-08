<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Actions;

use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Models\User;
use App\Modules\Boardroom\Domain\HolderKind;
use App\Modules\Boardroom\Models\ShareEvent;
use App\Modules\Boardroom\Models\Shareholder;
use App\Modules\Boardroom\Support\BoardroomAccess;
use App\Modules\Payroll\Domain\PersonalNumber;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Adds a shareholder, or corrects one that no entry names yet. The register
 * needs a name, an identity and a postal address for every holder (ABL
 * 5 kap. 5 § 2): a natural person a Swedish personal number or another
 * identification number with its country, a legal entity an organisation
 * number or another identification number. Once a holder is in the register,
 * new details go in as an entry (RecordShareEvent::changeDetails), so the
 * history keeps the old ones. The audit log gets ids and field names only.
 */
final class SaveShareholder
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input, ?Shareholder $holder = null): Shareholder
    {
        BoardroomAccess::authorizeEntry($user, $company);

        return CurrentCompany::run($company, fn (): Shareholder => DB::transaction(function () use ($user, $company, $input, $holder): Shareholder {
            if ($holder !== null && self::inRegister($holder)) {
                throw new LogicException('A holder in the register changes through an ADDRESS_CHANGE entry.');
            }

            $holder ??= new Shareholder(['created_by' => $user->id]);
            $created = ! $holder->exists;
            $this->apply($holder, $input);

            Audit::record($created ? 'share_register.holder_added' : 'share_register.holder_updated', $holder, ['kind' => $holder->kind->value], $company, $user);

            return $holder;
        }));
    }

    /** Removes a holder no entry names, such as one added by mistake. */
    public function delete(User $user, Company $company, Shareholder $holder): void
    {
        BoardroomAccess::authorizeEntry($user, $company);

        CurrentCompany::run($company, function () use ($user, $company, $holder): void {
            if (self::inRegister($holder)) {
                throw ValidationException::withMessages(['holder' => __('Ägaren finns i aktiebokens historik och kan inte tas bort. Uppgifterna sparas i minst tio år (aktiebolagslagen 5 kap. 3 §).')]);
            }

            $holder->delete();
            Audit::record('share_register.holder_removed', $holder, ['kind' => $holder->kind->value], $company, $user);
        });
    }

    public static function inRegister(Shareholder $holder): bool
    {
        return ShareEvent::query()->where(fn ($query) => $query->where('to_holder_id', $holder->id)->orWhere('from_holder_id', $holder->id))->exists();
    }

    /**
     * Validates and writes the details onto the holder.
     *
     * @param  array<string, mixed>  $input
     */
    public function apply(Shareholder $holder, array $input): void
    {
        $data = Validator::make($input, [
            'kind' => ['required', Rule::in([HolderKind::Person->value, HolderKind::Entity->value])],
            'name' => ['required', 'string', 'max:255'],
            'personal_number' => ['nullable', 'string', 'max:20'],
            'org_number' => ['nullable', 'string', 'max:20'],
            'other_id' => ['nullable', 'string', 'max:50'],
            'other_id_country' => ['nullable', 'string', 'size:2'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
        ], [], [
            'name' => __('Namn'),
            'address_line1' => __('Adress'),
            'postal_code' => __('Postnummer'),
            'city' => __('Ort'),
        ])->validate();

        $kind = HolderKind::from((string) $data['kind']);
        $personal = trim((string) ($data['personal_number'] ?? ''));
        $org = trim((string) ($data['org_number'] ?? ''));
        $other = trim((string) ($data['other_id'] ?? ''));
        $otherCountry = strtoupper(trim((string) ($data['other_id_country'] ?? '')));
        $values = ['personal_number' => null, 'org_number' => null, 'other_id' => null, 'other_id_country' => null];

        if ($kind === HolderKind::Person && $personal !== '') {
            $number = PersonalNumber::tryFrom($personal);

            if ($number === null) {
                throw ValidationException::withMessages(['personal_number' => __('Personnumret är inte giltigt. Skriv alla tolv siffror, ÅÅÅÅMMDD-NNNN.')]);
            }

            $values['personal_number'] = $number->digits;
        } elseif ($kind === HolderKind::Entity && $org !== '') {
            $number = OrganisationNumber::tryFrom($org);

            if ($number === null) {
                throw ValidationException::withMessages(['org_number' => __('Organisationsnumret är inte giltigt.')]);
            }

            $values['org_number'] = $number->formatted();
        } elseif ($other !== '' && $otherCountry !== '') {
            $values['other_id'] = $other;
            $values['other_id_country'] = $otherCountry;
        } else {
            // ABL 5 kap. 5 § 2: personnummer, organisationsnummer eller annat identifieringsnummer.
            throw ValidationException::withMessages([$kind === HolderKind::Person ? 'personal_number' : 'org_number' => $kind === HolderKind::Person
                ? __('Ange personnummer, eller ett annat id-nummer och landet som utfärdat det.')
                : __('Ange organisationsnummer, eller ett annat id-nummer och landet som utfärdat det.')]);
        }

        $holder->fill([
            'kind' => $kind->value,
            'name' => trim((string) $data['name']),
            ...$values,
            'address_line1' => trim((string) $data['address_line1']),
            'address_line2' => isset($data['address_line2']) && trim((string) $data['address_line2']) !== '' ? trim((string) $data['address_line2']) : null,
            'postal_code' => trim((string) $data['postal_code']),
            'city' => trim((string) $data['city']),
            'country' => strtoupper(trim((string) ($data['country'] ?? '')) ?: 'SE'),
        ])->save();
    }
}
