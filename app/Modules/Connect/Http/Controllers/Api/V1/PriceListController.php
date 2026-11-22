<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Actions\Pricing\SavePriceList;
use App\Actions\Pricing\SavePriceListItem;
use App\Domain\Pricing\PriceAnnouncer;
use App\Domain\Pricing\PriceInput;
use App\Domain\Units\UnitRegister;
use App\Models\Article;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Modules\Connect\Http\Controllers\Api\V1\Concerns\ActsAsCredential;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Connect\Http\Resources\PricePresenter;
use App\Modules\Connect\Support\ApiInput;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Price lists over the API (ADR-082 P9, ADR-032): the same Actions as the
 * screens (SavePriceList, SavePriceListItem), so every rule and every audit
 * entry is the same. Read with prices:view, written with price-lists:manage.
 * Another company's list is "not found". The bulk route saves each row on
 * its own and reports the rows that were refused, the way the screen's file
 * import does.
 */
final class PriceListController
{
    use ActsAsCredential;

    /** The most rows one bulk call takes. */
    public const int BULK_LIMIT = 1000;

    private const array LIST_FIELDS = ['code', 'name', 'kind', 'currency', 'prices_include_vat', 'valid_from', 'valid_to', 'is_default_for_currency', 'based_on_list_id', 'derived_percent_bp', 'rounding_rule', 'channel', 'group_ids'];

    public function index(Request $request): JsonResponse
    {
        Gate::forUser($this->user())->authorize('viewAny', [PriceList::class, $this->company()]);

        $filters = $this->filters($request);
        $query = PriceList::query()->with('priceGroups');

        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        if ($filters['currency'] !== null) {
            $query->where('currency', $filters['currency']);
        }

        if ($filters['valid_on'] !== null) {
            $this->validOn($query, $filters['valid_on']);
        }

        if ($filters['article'] !== null) {
            $query->whereExists(fn ($items) => $items->selectRaw('1')->from('price_list_items')
                ->whereColumn('price_list_items.price_list_id', 'price_lists.id')
                ->whereNull('price_list_items.archived_at')
                ->whereIn('price_list_items.article_id', Article::query()->where('number', $filters['article'])->select('id')));
        }

        return CursorList::respond($request, $query, fn (PriceList $list): array => PricePresenter::list($list));
    }

    public function show(int $priceList): JsonResponse
    {
        return CursorList::one(PricePresenter::list($this->find($priceList, 'view')->load('priceGroups')));
    }

    public function items(Request $request, int $priceList): JsonResponse
    {
        $list = $this->find($priceList, 'view');
        $filters = $this->filters($request);
        $query = PriceListItem::query()->where('price_list_id', $list->id)->whereNull('archived_at')->with(['article:id,number', 'unit:id,code']);

        if ($filters['valid_on'] !== null) {
            $this->validOn($query, $filters['valid_on']);
        }

        if ($filters['article'] !== null) {
            $query->whereIn('article_id', Article::query()->where('number', $filters['article'])->select('id'));
        }

        return CursorList::respond($request, $query, fn (PriceListItem $item): array => PricePresenter::listItem($item));
    }

    public function store(Request $request, SavePriceList $save): JsonResponse
    {
        $list = $save($this->user(), $this->company(), $this->listInput(ApiInput::body($request->json()->all())));

        return CursorList::one(PricePresenter::list($list->refresh()->load('priceGroups')), 201)
            ->header('Location', route('connect.api.price-lists.show', $list->id));
    }

    /** Changes the given fields; the rest are kept. */
    public function update(Request $request, int $priceList, SavePriceList $save): JsonResponse
    {
        $list = $this->find($priceList, 'update')->load('priceGroups');
        $current = PricePresenter::list($list);
        $body = [...array_intersect_key($current, array_flip(self::LIST_FIELDS)), ...array_intersect_key(ApiInput::body($request->json()->all()), array_flip(self::LIST_FIELDS))];

        $saved = $save($this->user(), $this->company(), $this->listInput($body), $list);

        return CursorList::one(PricePresenter::list($saved->refresh()->load('priceGroups')));
    }

    /**
     * Adds up to 1 000 prices. Each row is saved on its own: the rows that
     * were refused come back with their position and reasons, the rest are
     * kept. 201 when every row was saved, 200 when some were, 422 when none.
     */
    public function storeItems(Request $request, int $priceList, SavePriceListItem $save): JsonResponse
    {
        $list = $this->find($priceList, 'update');
        $body = ApiInput::body($request->json()->all());
        $rows = $body['items'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            throw ValidationException::withMessages(['items' => __('Skicka en lista med priser i items.')]);
        }

        if (count($rows) > self::BULK_LIMIT) {
            throw ValidationException::withMessages(['items' => __('Högst :limit priser i taget.', ['limit' => self::BULK_LIMIT])]);
        }

        $articles = Article::query()->whereIn('number', array_values(array_filter(array_map(fn (mixed $row): string => is_array($row) && is_string($row['article'] ?? null) ? trim($row['article']) : '', $rows))))->pluck('id', 'number');
        $units = UnitRegister::load();
        $saved = [];
        $errors = [];

        PriceAnnouncer::batch(function () use ($rows, $articles, $units, $list, $save, &$saved, &$errors): void {
            foreach ($rows as $index => $row) {
                try {
                    $input = $this->itemInput(is_array($row) ? $row : [], $articles, $units);
                    $replaces = is_array($row) && ($row['replace_running'] ?? false) === true ? $this->running($list, $input) : null;
                    $saved[$index] = $save($this->user(), $this->company(), $list, $input, null, $replaces);
                } catch (ValidationException $exception) {
                    $errors[] = ['index' => $index, 'errors' => $exception->errors()];
                } catch (AuthorizationException|ModelNotFoundException $exception) {
                    throw $exception;
                } catch (Throwable) {
                    $errors[] = ['index' => $index, 'errors' => ['row' => [__('Raden kunde inte sparas.')]]];
                }
            }
        });

        (new Collection(array_values($saved)))->load(['article:id,number', 'unit:id,code']);

        $status = $errors === [] ? 201 : ($saved === [] ? 422 : 200);

        return new JsonResponse([
            'data' => [
                'saved' => count($saved),
                'failed' => count($errors),
                'items' => array_map(fn (PriceListItem $item, int $index): array => ['index' => $index, ...PricePresenter::listItem($item)], array_values($saved), array_keys($saved)),
                'errors' => $errors,
            ],
        ], $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function find(int $id, string $ability): PriceList
    {
        $list = PriceList::query()->findOrFail($id);
        Gate::forUser($this->user())->authorize($ability, $list);

        return $list;
    }

    /** @return array{valid_on: ?string, article: ?string, currency: ?string} */
    private function filters(Request $request): array
    {
        $input = Validator::make($request->query(), [
            'valid_on' => ['nullable', 'date_format:Y-m-d'],
            'article' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'regex:/^[A-Za-z]{3}$/'],
        ])->validate();

        return [
            'valid_on' => $input['valid_on'] ?? null,
            'article' => isset($input['article']) ? (string) $input['article'] : null,
            'currency' => isset($input['currency']) ? strtoupper((string) $input['currency']) : null,
        ];
    }

    /** @param  Builder<*>  $query */
    private function validOn(Builder $query, string $date): void
    {
        $query->where(fn (Builder $range) => $range->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
            ->where(fn (Builder $range) => $range->whereNull('valid_to')->orWhere('valid_to', '>=', $date));
    }

    /**
     * The API's body as SavePriceList reads it: a derived list is one with
     * derived_percent_bp (hundredths of a percent, 1050 is 10,5 %).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function listInput(array $body): array
    {
        $input = array_intersect_key($body, array_flip(self::LIST_FIELDS));
        $percent = $input['derived_percent_bp'] ?? null;
        unset($input['derived_percent_bp']);

        if ($percent !== null) {
            if (! is_int($percent)) {
                throw ValidationException::withMessages(['derived_percent_bp' => __('Skriv procenten i hundradels procent som ett heltal, till exempel -1200 för 12 % lägre.')]);
            }

            $input['derived'] = true;
            $input['derived_percent'] = PriceInput::basisPointsToPercent($percent);
        }

        foreach (['code', 'name', 'kind', 'currency', 'rounding_rule', 'channel'] as $text) {
            if (array_key_exists($text, $input) && $input[$text] !== null && ! is_string($input[$text])) {
                throw ValidationException::withMessages([$text => __('Skriv värdet som text.')]);
            }
        }

        return $input;
    }

    /**
     * One price row as SavePriceListItem reads it: the article by number,
     * the unit by code, the price in the list's minor unit or a discount in
     * basis points.
     *
     * @param  array<string, mixed>  $row
     * @param  SupportCollection<string, int>  $articles
     * @return array<string, mixed>
     */
    private function itemInput(array $row, SupportCollection $articles, UnitRegister $units): array
    {
        $number = is_string($row['article'] ?? null) ? trim($row['article']) : '';
        $articleId = $number === '' ? ($row['article_id'] ?? null) : $articles->get($number);

        if (! is_int($articleId)) {
            throw ValidationException::withMessages(['article' => __('Artikeln finns inte.')]);
        }

        $unitId = $row['unit_id'] ?? null;

        if ($unitId === null && is_string($row['unit'] ?? null) && $row['unit'] !== '') {
            $unitId = ($units->match($row['unit']) ?? throw ValidationException::withMessages(['unit' => __('Enheten finns inte.')]))->id;
        }

        $hasPrice = array_key_exists('price_minor', $row) && $row['price_minor'] !== null;
        $hasDiscount = array_key_exists('discount_bp', $row) && $row['discount_bp'] !== null;

        if ($hasPrice === $hasDiscount) {
            throw ValidationException::withMessages(['price_minor' => __('Skriv antingen price_minor eller discount_bp.')]);
        }

        return [
            'article_id' => $articleId,
            'unit_id' => $unitId,
            'min_quantity' => ApiInput::quantity($row['min_quantity'] ?? '0', 'min_quantity'),
            'mode' => $hasPrice ? 'price' : 'discount',
            'price' => $hasPrice ? ApiInput::kronor($row['price_minor'], 'price_minor') : null,
            'discount' => $hasDiscount ? ApiInput::percent($row['discount_bp'], 'discount_bp') : null,
            'valid_from' => $row['valid_from'] ?? null,
            'valid_to' => $row['valid_to'] ?? null,
        ];
    }

    /**
     * The price running for the same article, unit and quantity step, which a
     * new price with replace_running takes over from the day it starts.
     *
     * @param  array<string, mixed>  $input
     */
    private function running(PriceList $list, array $input): ?PriceListItem
    {
        $from = $input['valid_from'] ?? null;
        $minimum = PriceInput::quantityMilli((string) $input['min_quantity']);

        if (! is_string($from) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || $minimum === null) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $from);

        if ($parsed === null) {
            return null;
        }

        $start = $parsed->toDateString();

        return PriceListItem::query()
            ->where('price_list_id', $list->id)
            ->where('article_id', $input['article_id'])
            ->where('min_quantity_milli', $minimum)
            ->when($input['unit_id'] === null, fn (Builder $query) => $query->whereNull('unit_id'), fn (Builder $query) => $query->where('unit_id', $input['unit_id']))
            ->whereNull('archived_at')
            ->where(fn (Builder $query) => $query->whereNull('valid_from')->orWhere('valid_from', '<', $start))
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $start))
            ->orderByDesc('valid_from')
            ->first();
    }
}
