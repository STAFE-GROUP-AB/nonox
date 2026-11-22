<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\PriceAnnouncer;
use App\Domain\Pricing\PriceChannel;
use App\Domain\Pricing\PriceInput;
use App\Domain\Pricing\PriceListKind;
use App\Domain\Pricing\PriceRounding;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPriceGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes a price list (ADR-082 §2): a standard list, a campaign
 * (always with a period, optionally for chosen customer price groups) or a
 * channel list. A list may follow the base price or another list of the same
 * currency by a percentage and a rounding rule. Every id it names must
 * belong to the company.
 */
class SavePriceList
{
    /**
     * @param  array<string, mixed>  $input  code, name, kind, currency, prices_include_vat, valid_from, valid_to (Y-m-d), is_default_for_currency, derived (bool), derived_percent (text such as "-12"), based_on_list_id (null = the base price), rounding_rule, channel, group_ids (campaigns)
     */
    public function __invoke(User $user, Company $company, array $input, ?PriceList $list = null): PriceList
    {
        if ($list !== null && (int) $list->company_id !== (int) $company->id) {
            throw new ModelNotFoundException;
        }

        $list === null
            ? Gate::forUser($user)->authorize('create', [PriceList::class, $company])
            : Gate::forUser($user)->authorize('update', $list);

        $input['code'] = strtoupper(trim((string) ($input['code'] ?? '')));
        $input['currency'] = strtoupper(trim((string) ($input['currency'] ?? 'SEK')));

        $validated = Validator::make($input, [
            'code' => ['required', 'string', 'max:20', Rule::unique('price_lists', 'code')->where('company_id', $company->id)->ignore($list?->id)],
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::enum(PriceListKind::class)],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'prices_include_vat' => ['boolean'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'is_default_for_currency' => ['boolean'],
            'derived' => ['boolean'],
            'derived_percent' => ['nullable', 'string'],
            'based_on_list_id' => ['nullable', 'integer'],
            'rounding_rule' => ['nullable', Rule::enum(PriceRounding::class)],
            'channel' => ['nullable', Rule::enum(PriceChannel::class)],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer'],
        ], [
            'code.unique' => __('Koden :code används redan av en annan prislista.', ['code' => $input['code']]),
            'code.max' => __('Koden får vara högst 20 tecken.'),
            'currency.regex' => __('Skriv valutan som tre bokstäver, till exempel SEK eller EUR.'),
            'valid_to.after_or_equal' => __('Slutdatumet kan inte ligga före startdatumet.'),
        ], [
            'code' => __('kod'),
            'name' => __('namn'),
            'kind' => __('typ'),
            'currency' => __('valuta'),
        ])->validate();

        $saved = DB::transaction(fn (): PriceList => CurrentCompany::run($company, function () use ($validated, $company, $user, $list): PriceList {
            $kind = PriceListKind::from((string) $validated['kind']);
            $currency = (string) $validated['currency'];
            $includesVat = (bool) ($validated['prices_include_vat'] ?? false);
            $from = $validated['valid_from'] ?? null;
            $to = $validated['valid_to'] ?? null;

            $this->guardKind($kind, $from, $to, $validated);

            $derived = (bool) ($validated['derived'] ?? false);
            $percent = $derived ? $this->percent((string) ($validated['derived_percent'] ?? '')) : null;
            $basedOn = $derived ? $this->basedOn($validated['based_on_list_id'] ?? null, $currency, $list) : null;
            $default = (bool) ($validated['is_default_for_currency'] ?? false);

            if ($default && $kind !== PriceListKind::Standard) {
                throw ValidationException::withMessages(['is_default_for_currency' => __('Bara en vanlig prislista kan vara standard för sin valuta.')]);
            }

            if ($list !== null) {
                $this->guardExisting($list, $kind, $currency, $includesVat);
            }

            $groupIds = $kind === PriceListKind::Campaign ? $this->groups((array) ($validated['group_ids'] ?? [])) : [];

            $attributes = [
                'code' => $validated['code'],
                'name' => trim((string) $validated['name']),
                'kind' => $kind,
                'currency' => $currency,
                'prices_include_vat' => $includesVat,
                'valid_from' => $from,
                'valid_to' => $to,
                'is_default_for_currency' => $default,
                'based_on_list_id' => $basedOn?->id,
                'derived_percent_bp' => $percent,
                'rounding_rule' => $derived ? PriceRounding::from((string) ($validated['rounding_rule'] ?? 'NONE')) : PriceRounding::None,
                'channel' => $kind === PriceListKind::Channel ? PriceChannel::from((string) $validated['channel']) : null,
            ];

            if ($default) {
                PriceList::query()->where('currency', $currency)->where('is_default_for_currency', true)->whereNull('archived_at')
                    ->when($list !== null, fn ($query) => $query->whereKeyNot($list?->id))
                    ->update(['is_default_for_currency' => false]);
            }

            if ($list === null) {
                $list = PriceList::create([...$attributes, 'created_by' => $user->id]);
                Audit::record('price_list.created', $list, ['kind' => $kind->value, 'currency' => $currency], $company, $user);
            } else {
                $list->update($attributes);
                Audit::record('price_list.updated', $list, ['kind' => $kind->value, 'currency' => $currency, 'fields' => array_keys($list->getChanges())], $company, $user);
            }

            $list->priceGroups()->syncWithPivotValues($groupIds, ['company_id' => $company->id]);

            return $list;
        }));

        if ($list !== null) {
            PriceAnnouncer::listChanged($company->id, $saved->id);
        }

        return $saved;
    }

    /** @param  array<string, mixed>  $validated */
    private function guardKind(PriceListKind $kind, ?string $from, ?string $to, array $validated): void
    {
        if ($kind === PriceListKind::Campaign && ($from === null || $to === null)) {
            throw ValidationException::withMessages(['valid_from' => __('En kampanj behöver både ett startdatum och ett slutdatum.')]);
        }

        if ($kind === PriceListKind::Channel && ! in_array($validated['channel'] ?? null, [PriceChannel::Pos->value, PriceChannel::Portal->value], true)) {
            throw ValidationException::withMessages(['channel' => __('Välj om kanalprislistan gäller kassan eller kundportalen.')]);
        }
    }

    private function percent(string $input): int
    {
        $basisPoints = PriceInput::percentToBasisPoints($input);

        if ($basisPoints === null || $basisPoints < -9900 || $basisPoints > 100000) {
            throw ValidationException::withMessages(['derived_percent' => __('Skriv procenten som ett tal mellan −99 och 1 000, till exempel −12 för 12 % lägre.')]);
        }

        return $basisPoints;
    }

    private function basedOn(mixed $id, string $currency, ?PriceList $list): ?PriceList
    {
        if ($id === null || $id === '') {
            return null;
        }

        $basedOn = PriceList::query()->whereKey((int) $id)->whereNull('archived_at')->first();

        if ($basedOn === null) {
            throw ValidationException::withMessages(['based_on_list_id' => __('Prislistan finns inte.')]);
        }

        if ($basedOn->kind === PriceListKind::Campaign) {
            throw ValidationException::withMessages(['based_on_list_id' => __('En lista kan inte följa en kampanj.')]);
        }

        if ($basedOn->currency !== $currency) {
            throw ValidationException::withMessages(['based_on_list_id' => __('Listan måste ha samma valuta som den lista den följer.')]);
        }

        $step = $basedOn;

        for ($depth = 0; $step !== null && $depth < 20; $depth++) {
            if ($list !== null && $step->id === $list->id) {
                throw ValidationException::withMessages(['based_on_list_id' => __('Listorna skulle följa varandra i en ring. Välj en annan lista.')]);
            }

            $step = $step->based_on_list_id === null ? null : PriceList::query()->find($step->based_on_list_id);
        }

        return $basedOn;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function groups(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $found = CustomerPriceGroup::query()->whereIn('id', $ids)->whereNull('archived_at')->pluck('id')->all();

        if (count($found) !== count($ids)) {
            throw ValidationException::withMessages(['group_ids' => __('En vald kundgrupp finns inte.')]);
        }

        return $ids;
    }

    /** What an existing list cannot change while it is in use: its prices mean something only in its currency and VAT basis. */
    private function guardExisting(PriceList $list, PriceListKind $kind, string $currency, bool $includesVat): void
    {
        if (($currency !== $list->currency || $includesVat !== $list->prices_include_vat)
            && (PriceListItem::query()->where('price_list_id', $list->id)->exists() || PriceList::query()->where('based_on_list_id', $list->id)->exists())) {
            throw ValidationException::withMessages(['currency' => __('Valutan och momsgrunden kan inte ändras när listan har priser eller följs av en annan lista. Gör en ny lista.')]);
        }

        if ($kind === PriceListKind::Campaign && PriceList::query()->where('based_on_list_id', $list->id)->exists()) {
            throw ValidationException::withMessages(['kind' => __('Andra listor följer den här listan, och en lista kan inte följa en kampanj. Låt dem följa något annat först.')]);
        }

        if ($kind !== PriceListKind::Standard && $list->kind === PriceListKind::Standard
            && (Customer::query()->where('price_list_id', $list->id)->exists() || CustomerPriceGroup::query()->where('price_list_id', $list->id)->exists())) {
            throw ValidationException::withMessages(['kind' => __('Listan används av kunder eller kundgrupper och måste förbli en vanlig prislista.')]);
        }
    }
}
