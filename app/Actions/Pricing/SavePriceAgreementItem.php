<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\PriceInput;
use App\Domain\Pricing\PriceListKind;
use App\Domain\Units\ArticleUnits;
use App\Domain\Units\UnitConverter;
use App\Models\Article;
use App\Models\Company;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Models\PriceList;
use App\Models\Unit;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Adds or changes one term of a draft price agreement (ADR-082 §4, P5): for
 * one article or for all articles; from a quantity, in a unit; a fixed price
 * in the agreement's currency, or a discount on the ordinary price or on a
 * named price list ("the reseller list minus 3 %"). All articles take a
 * discount only, since one fixed price for every article means nothing.
 * Two terms for the same target, unit and step are refused. The article,
 * unit and list must belong to the company; the list must be an ordinary or
 * channel list in the agreement's currency.
 */
class SavePriceAgreementItem
{
    /**
     * @param  array<string, mixed>  $input  target (article|all), article_id, unit_id, min_quantity (text), mode (price|discount), price, discount (percent text), base_price_list_id
     */
    public function __invoke(User $user, Company $company, PriceAgreement $agreement, array $input, ?PriceAgreementItem $item = null): PriceAgreementItem
    {
        if ((int) $agreement->company_id !== (int) $company->id || ($item !== null && (int) $item->price_agreement_id !== (int) $agreement->id)) {
            throw new ModelNotFoundException;
        }

        Gate::forUser($user)->authorize('changeTerms', $agreement);

        $validated = Validator::make($input, [
            'target' => ['required', 'in:article,all'],
            'article_id' => ['nullable', 'integer', 'required_if:target,article'],
            'unit_id' => ['nullable', 'integer'],
            'min_quantity' => ['nullable', 'string'],
            'mode' => ['required', 'in:price,discount'],
            'price' => ['nullable', 'string'],
            'discount' => ['nullable', 'string'],
            'base_price_list_id' => ['nullable', 'integer'],
        ], [
            'article_id.required_if' => __('Välj en artikel.'),
        ])->validate();

        return DB::transaction(fn (): PriceAgreementItem => CurrentCompany::run($company, function () use ($validated, $company, $user, $agreement, $item): PriceAgreementItem {
            $agreement = PriceAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if (! $agreement->status->isDraft()) {
                throw ValidationException::withMessages(['article_id' => __('Avtalet är inte längre ett utkast. Gör en ny version med Ändra avtal.')]);
            }

            $all = $validated['target'] === 'all';
            $article = $all ? null : (Article::query()->with('stockUnit')->find((int) $validated['article_id'])
                ?? throw ValidationException::withMessages(['article_id' => __('Artikeln finns inte.')]));

            if ($all && $validated['mode'] === 'price') {
                throw ValidationException::withMessages(['mode' => __('Alla artiklar kan bara få en rabatt i procent, inte ett fast pris.')]);
            }

            $unit = $article === null ? null : $this->unit($validated['unit_id'] ?? null, $article);
            $minQuantity = PriceInput::quantityMilli((string) ($validated['min_quantity'] ?? ''))
                ?? throw ValidationException::withMessages(['min_quantity' => __('Skriv antalet som ett tal, till exempel 10 eller 2,5.')]);
            [$priceMinor, $discountBp] = $this->amount($validated, $agreement);
            $listId = $discountBp === null ? null : $this->list($validated['base_price_list_id'] ?? null, $agreement);

            $this->refuseDuplicate($agreement, $article, $unit, $minQuantity, $item);

            $attributes = [
                'article_id' => $article?->id,
                'applies_to_all' => $all,
                'unit_id' => $unit?->id,
                'min_quantity_milli' => $minQuantity,
                'price_minor' => $priceMinor,
                'discount_bp' => $discountBp,
                'base_price_list_id' => $listId,
            ];

            if ($item === null) {
                $item = PriceAgreementItem::create([...$attributes, 'price_agreement_id' => $agreement->id]);
                Audit::record('price_agreement_item.created', $item, ['price_agreement_id' => $agreement->id, 'article_id' => $article?->id, 'price_minor' => $priceMinor, 'discount_bp' => $discountBp], $company, $user);

                return $item;
            }

            $item = PriceAgreementItem::query()->whereKey($item->id)->firstOrFail();
            $item->update($attributes);
            Audit::record('price_agreement_item.updated', $item, ['price_agreement_id' => $agreement->id, 'fields' => array_keys($item->getChanges())], $company, $user);

            return $item;
        }));
    }

    private function unit(mixed $id, Article $article): ?Unit
    {
        if ($id === null || $id === '') {
            return null;
        }

        $unit = Unit::query()->whereKey((int) $id)->whereNull('archived_at')->first()
            ?? throw ValidationException::withMessages(['unit_id' => __('Enheten finns inte.')]);

        $stock = ArticleUnits::stockCode($article);

        if (UnitConverter::enteredPerStock($stock, $unit->code, ArticleUnits::conversions($article)) === null) {
            throw ValidationException::withMessages(['unit_id' => __('Enheten :unit går inte att räkna om till artikelns enhet :stock. Lägg in en omräkning på artikeln först.', ['unit' => $unit->code, 'stock' => $stock])]);
        }

        return $unit;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: ?int, 1: ?int} price in the agreement's currency or discount in basis points
     */
    private function amount(array $validated, PriceAgreement $agreement): array
    {
        if ($validated['mode'] === 'discount') {
            $bp = PriceInput::percentToBasisPoints((string) ($validated['discount'] ?? ''));

            if ($bp === null || $bp < 0 || $bp > 10000) {
                throw ValidationException::withMessages(['discount' => __('Skriv rabatten som procent mellan 0 och 100, till exempel 12,5.')]);
            }

            return [null, $bp];
        }

        $minor = PriceInput::minor((string) ($validated['price'] ?? ''), $agreement->currency);

        if ($minor === null) {
            throw ValidationException::withMessages(['price' => __('Priset går inte att läsa. Skriv till exempel 149,00.')]);
        }

        return [$minor, null];
    }

    /** The named list a discount is taken from, or null for the ordinary price. */
    private function list(mixed $id, PriceAgreement $agreement): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        $list = PriceList::query()->whereKey((int) $id)->whereNull('archived_at')->where('kind', '<>', PriceListKind::Campaign->value)->first()
            ?? throw ValidationException::withMessages(['base_price_list_id' => __('Prislistan finns inte. Välj en vanlig prislista.')]);

        if ($list->currency !== $agreement->currency) {
            throw ValidationException::withMessages(['base_price_list_id' => __('Prislistan är i :list men avtalet i :agreement. Välj en lista i avtalets valuta.', ['list' => $list->currency, 'agreement' => $agreement->currency])]);
        }

        return $list->id;
    }

    private function refuseDuplicate(PriceAgreement $agreement, ?Article $article, ?Unit $unit, int $minQuantity, ?PriceAgreementItem $self): void
    {
        $exists = PriceAgreementItem::query()
            ->where('price_agreement_id', $agreement->id)
            ->where('min_quantity_milli', $minQuantity)
            ->when($article === null, fn ($query) => $query->where('applies_to_all', true), fn ($query) => $query->where('article_id', $article?->id))
            ->when($unit === null, fn ($query) => $query->whereNull('unit_id'), fn ($query) => $query->where('unit_id', $unit?->id))
            ->when($self !== null, fn ($query) => $query->whereKeyNot($self?->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['min_quantity' => $article === null
                ? __('Avtalet har redan en rabatt för alla artiklar från samma antal. Ändra den i stället.')
                : __('Avtalet har redan ett pris för artikeln från samma antal och enhet. Ändra det i stället.')]);
        }
    }
}
