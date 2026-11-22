<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\PriceAnnouncer;
use App\Domain\Pricing\PriceInput;
use App\Domain\Units\ArticleUnits;
use App\Domain\Units\UnitConverter;
use App\Models\Article;
use App\Models\Company;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Unit;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Adds or changes one price of an article in a list (ADR-082 §2): a price in
 * the list's currency or a discount on the base price, from a quantity (a
 * quantity ladder is several items), in a unit, for a period. The list, the
 * article and the unit must belong to the company, the unit must convert to
 * the article's, and two items for the same article, unit and step may not
 * overlap in time. A price change is a new item with a later start, so the
 * old price stays answerable.
 */
class SavePriceListItem
{
    /**
     * @param  ?PriceListItem  $replaces  the running price this new one follows: it ends the day before the new one starts
     * @param  array<string, mixed>  $input  article_id, unit_id (null = the article's sales unit), min_quantity (text), mode (price or discount), price (text, in the list's currency) or discount (percent text), valid_from, valid_to (Y-m-d)
     */
    public function __invoke(User $user, Company $company, PriceList $list, array $input, ?PriceListItem $item = null, ?PriceListItem $replaces = null): PriceListItem
    {
        if ((int) $list->company_id !== (int) $company->id || ($item !== null && (int) $item->price_list_id !== (int) $list->id)) {
            throw new ModelNotFoundException;
        }

        Gate::forUser($user)->authorize('update', $list);

        $validated = Validator::make($input, [
            'article_id' => ['required', 'integer'],
            'unit_id' => ['nullable', 'integer'],
            'min_quantity' => ['nullable', 'string'],
            'mode' => ['required', 'in:price,discount'],
            'price' => ['nullable', 'string'],
            'discount' => ['nullable', 'string'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ], [
            'article_id.required' => __('Välj en artikel.'),
            'valid_to.after_or_equal' => __('Slutdatumet kan inte ligga före startdatumet.'),
        ])->validate();

        $saved = DB::transaction(fn (): PriceListItem => CurrentCompany::run($company, function () use ($validated, $company, $user, $list, $item, $replaces): PriceListItem {
            $list = PriceList::query()->whereKey($list->id)->lockForUpdate()->firstOrFail();

            if ($list->archived_at !== null) {
                throw ValidationException::withMessages(['article_id' => __('Listan är arkiverad. Återställ den för att ändra priser.')]);
            }

            if ($item !== null && $item->archived_at !== null) {
                throw ValidationException::withMessages(['article_id' => __('Priset är arkiverat och kan inte ändras.')]);
            }

            $article = Article::query()->with('stockUnit')->find((int) $validated['article_id'])
                ?? throw ValidationException::withMessages(['article_id' => __('Artikeln finns inte.')]);
            $unit = $this->unit($validated['unit_id'] ?? null, $article);
            $minQuantity = PriceInput::quantityMilli((string) ($validated['min_quantity'] ?? ''))
                ?? throw ValidationException::withMessages(['min_quantity' => __('Skriv antalet som ett tal, till exempel 10 eller 2,5.')]);
            [$priceMinor, $discountBp] = $this->amount($validated, $list);
            $from = $validated['valid_from'] ?? null;
            $to = $validated['valid_to'] ?? null;

            if ($item !== null && $this->hasStarted($item)) {
                return $this->endStarted($item, $list, $article, $unit, $minQuantity, $priceMinor, $discountBp, $from, $to, $company, $user);
            }

            if ($replaces !== null) {
                $this->endReplaced($replaces, $list, $article, $unit, $minQuantity, $from, $company, $user);
            }

            $this->refuseOverlap($list, $article, $unit, $minQuantity, $from, $to, $item);

            $attributes = [
                'article_id' => $article->id,
                'unit_id' => $unit?->id,
                'min_quantity_milli' => $minQuantity,
                'price_minor' => $priceMinor,
                'discount_bp' => $discountBp,
                'valid_from' => $from,
                'valid_to' => $to,
            ];

            if ($item === null) {
                $item = PriceListItem::create([...$attributes, 'price_list_id' => $list->id]);
                Audit::record('price_list_item.created', $item, ['price_list_id' => $list->id, 'article_id' => $article->id, 'price_minor' => $priceMinor, 'discount_bp' => $discountBp], $company, $user);
            } else {
                $item->update($attributes);
                Audit::record('price_list_item.updated', $item, ['price_list_id' => $list->id, 'article_id' => $article->id, 'fields' => array_keys($item->getChanges())], $company, $user);
            }

            return $item;
        }));

        PriceAnnouncer::listChanged($company->id, $list->id, $saved->valid_from?->toDateString());

        return $saved;
    }

    /** A price that has started to apply is history: only its end may be set (ADR-082 §11). */
    private function hasStarted(PriceListItem $item): bool
    {
        return $item->valid_from === null || $item->valid_from->lessThanOrEqualTo(CarbonImmutable::today());
    }

    /** Only valid_to may differ on a price that has started, and never to a day that has passed; an ended price is read-only. */
    private function endStarted(PriceListItem $item, PriceList $list, Article $article, ?Unit $unit, int $minQuantity, ?int $priceMinor, ?int $discountBp, ?string $from, ?string $to, Company $company, User $user): PriceListItem
    {
        $today = CarbonImmutable::today();

        if ($item->valid_to !== null && $item->valid_to->lessThan($today)) {
            throw ValidationException::withMessages(['valid_from' => __('Priset har tagit slut och kan inte ändras.')]);
        }

        $unchanged = $item->article_id === $article->id
            && $item->unit_id === $unit?->id
            && $item->min_quantity_milli === $minQuantity
            && $item->price_minor === $priceMinor
            && $item->discount_bp === $discountBp
            && $item->valid_from?->toDateString() === $from;

        if (! $unchanged) {
            throw ValidationException::withMessages(['valid_from' => __('Priset har börjat gälla och kan inte ändras i efterhand. Använd Nytt pris från datum, så finns det gamla kvar i historiken.')]);
        }

        if ($to !== null && CarbonImmutable::parse($to)->lessThan($today->subDay())) {
            throw ValidationException::withMessages(['valid_to' => __('Slutdatumet kan inte ligga före i går, för då ändras dagar som redan har gått.')]);
        }

        $this->refuseOverlap($list, $article, $unit, $minQuantity, $item->valid_from?->toDateString(), $to, $item);

        $item->update(['valid_to' => $to]);
        Audit::record('price_list_item.ended', $item, ['price_list_id' => $list->id, 'article_id' => $article->id], $company, $user);

        return $item;
    }

    private function endReplaced(PriceListItem $replaces, PriceList $list, Article $article, ?Unit $unit, int $minQuantity, ?string $from, Company $company, User $user): void
    {
        $replaced = PriceListItem::query()->where('price_list_id', $list->id)->whereNull('archived_at')->find($replaces->id)
            ?? throw new ModelNotFoundException;

        if ($replaced->article_id !== $article->id || $replaced->unit_id !== $unit?->id || $replaced->min_quantity_milli !== $minQuantity) {
            throw ValidationException::withMessages(['article_id' => __('Det nya priset måste gälla samma artikel, enhet och antal som det pris det följer.')]);
        }
        $start = $from === null ? null : CarbonImmutable::parse($from);

        if ($start === null || ($replaced->valid_from !== null && $start->lessThanOrEqualTo($replaced->valid_from))) {
            throw ValidationException::withMessages(['valid_from' => __('Det nya priset måste börja efter att det gamla började gälla.')]);
        }

        if ($replaced->valid_to === null || $replaced->valid_to->greaterThanOrEqualTo($start)) {
            $replaced->update(['valid_to' => $start->subDay()->toDateString()]);
            Audit::record('price_list_item.ended', $replaced, ['price_list_id' => $list->id, 'article_id' => $replaced->article_id], $company, $user);
        }
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
     * @return array{0: ?int, 1: ?int} price in the list's currency or discount in basis points
     */
    private function amount(array $validated, PriceList $list): array
    {
        if ($validated['mode'] === 'discount') {
            $bp = PriceInput::percentToBasisPoints((string) ($validated['discount'] ?? ''));

            if ($bp === null || $bp < 0 || $bp > 10000) {
                throw ValidationException::withMessages(['discount' => __('Skriv rabatten som procent mellan 0 och 100, till exempel 12,5.')]);
            }

            return [null, $bp];
        }

        $minor = PriceInput::minor((string) ($validated['price'] ?? ''), $list->currency);

        if ($minor === null) {
            throw ValidationException::withMessages(['price' => __('Priset går inte att läsa. Skriv till exempel 149,00.')]);
        }

        return [$minor, null];
    }

    private function refuseOverlap(PriceList $list, Article $article, ?Unit $unit, int $minQuantity, ?string $from, ?string $to, ?PriceListItem $self): void
    {
        $overlapping = PriceListItem::query()
            ->where('price_list_id', $list->id)
            ->where('article_id', $article->id)
            ->where('min_quantity_milli', $minQuantity)
            ->whereNull('archived_at')
            ->when($unit === null, fn ($query) => $query->whereNull('unit_id'), fn ($query) => $query->where('unit_id', $unit?->id))
            ->when($self !== null, fn ($query) => $query->whereKeyNot($self?->id))
            ->whereRaw("COALESCE(valid_to, 'infinity'::date) >= COALESCE(?::date, '-infinity'::date)", [$from])
            ->whereRaw("COALESCE(valid_from, '-infinity'::date) <= COALESCE(?::date, 'infinity'::date)", [$to])
            ->first();

        if ($overlapping === null) {
            return;
        }

        $period = $overlapping->valid_from === null && $overlapping->valid_to === null
            ? __('utan slutdatum')
            : __(':from – :to', ['from' => $overlapping->valid_from?->toDateString() ?? '…', 'to' => $overlapping->valid_to?->toDateString() ?? '…']);

        throw ValidationException::withMessages(['valid_from' => __('Artikeln har redan ett pris i listan för samma antal och enhet som gäller :period. Avsluta det först, eller välj en period som inte överlappar.', ['period' => $period])]);
    }
}
