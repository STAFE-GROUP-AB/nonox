<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Actions\Pricing\ActivatePriceAgreement;
use App\Actions\Pricing\SavePriceAgreement;
use App\Actions\Pricing\SavePriceAgreementItem;
use App\Actions\Pricing\TerminatePriceAgreement;
use App\Domain\Pricing\PriceAgreementStatus;
use App\Domain\Units\UnitRegister;
use App\Models\Article;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Modules\Connect\Http\Controllers\Api\V1\Concerns\ActsAsCredential;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Connect\Http\Resources\PricePresenter;
use App\Modules\Connect\Support\ApiInput;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Customer price agreements over the API (ADR-082 P9, ADR-032): the same
 * Actions as the screens, so a draft is the only thing whose terms change,
 * activation needs a reason, and a termination never reaches back in time.
 * Read with prices:view, written with price-agreements:manage. Sending an
 * agreement for signing stays in the app. Another company's agreement is
 * "not found".
 */
final class PriceAgreementController
{
    use ActsAsCredential;

    private const array FIELDS = ['customer_id', 'title', 'currency', 'valid_from', 'valid_to', 'allow_campaigns', 'crm_agreement_id'];

    public function index(Request $request): JsonResponse
    {
        Gate::forUser($this->user())->authorize('viewAny', [PriceAgreement::class, $this->company()]);

        $filters = Validator::make($request->query(), [
            'customer_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(PriceAgreementStatus::class)],
            'currency' => ['nullable', 'regex:/^[A-Za-z]{3}$/'],
            'valid_on' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();

        $query = PriceAgreement::query();

        if (isset($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['currency'])) {
            $query->where('currency', strtoupper((string) $filters['currency']));
        }

        if (isset($filters['valid_on'])) {
            $date = (string) $filters['valid_on'];
            $query->where('valid_from', '<=', $date)
                ->where(fn (Builder $range) => $range->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
                ->where(fn (Builder $range) => $range->whereNull('terminated_on')->orWhere('terminated_on', '>=', $date));
        }

        return CursorList::respond($request, $query, fn (PriceAgreement $agreement): array => PricePresenter::agreement($agreement));
    }

    public function show(int $agreement): JsonResponse
    {
        return CursorList::one($this->presentWithItems($this->find($agreement, 'view')));
    }

    public function store(Request $request, SavePriceAgreement $save): JsonResponse
    {
        $agreement = $save($this->user(), $this->company(), array_intersect_key(ApiInput::body($request->json()->all()), array_flip(self::FIELDS)));

        return CursorList::one($this->presentWithItems($agreement->refresh()), 201)
            ->header('Location', route('connect.api.price-agreements.show', $agreement->id));
    }

    /** Changes the given terms of a draft; the rest are kept. */
    public function update(Request $request, int $agreement, SavePriceAgreement $save): JsonResponse
    {
        $model = $this->find($agreement, 'view');
        $current = [
            'customer_id' => $model->customer_id,
            'title' => $model->title,
            'currency' => $model->currency,
            'valid_from' => $model->valid_from->toDateString(),
            'valid_to' => $model->valid_to?->toDateString(),
            'allow_campaigns' => $model->allow_campaigns,
            'crm_agreement_id' => $model->crm_agreement_id,
        ];
        $changes = array_intersect_key(ApiInput::body($request->json()->all()), array_flip(self::FIELDS));

        $saved = $save($this->user(), $this->company(), [...$current, ...$changes], $model);

        return CursorList::one($this->presentWithItems($saved->refresh()));
    }

    /**
     * Adds up to 1 000 terms to a draft, each row on its own, answered like
     * the price list route (201, 200 or 422 with the refused rows).
     */
    public function storeItems(Request $request, int $agreement, SavePriceAgreementItem $save): JsonResponse
    {
        $model = $this->find($agreement, 'update');
        $rows = ApiInput::body($request->json()->all())['items'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            throw ValidationException::withMessages(['items' => __('Skicka en lista med villkor i items.')]);
        }

        if (count($rows) > PriceListController::BULK_LIMIT) {
            throw ValidationException::withMessages(['items' => __('Högst :limit villkor i taget.', ['limit' => PriceListController::BULK_LIMIT])]);
        }

        $articles = Article::query()->whereIn('number', array_values(array_filter(array_map(fn (mixed $row): string => is_array($row) && is_string($row['article'] ?? null) ? trim($row['article']) : '', $rows))))->pluck('id', 'number');
        $units = UnitRegister::load();
        $saved = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            try {
                $saved[$index] = $save($this->user(), $this->company(), $model, $this->itemInput(is_array($row) ? $row : [], $articles, $units));
            } catch (ValidationException $exception) {
                $errors[] = ['index' => $index, 'errors' => $exception->errors()];
            } catch (AuthorizationException|DeadlockException|ModelNotFoundException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                report($exception);

                $errors[] = ['index' => $index, 'errors' => ['row' => [__('Raden kunde inte sparas.')]]];
            }
        }

        (new Collection(array_values($saved)))->load(['article:id,number', 'unit:id,code']);

        return new JsonResponse([
            'data' => [
                'saved' => count($saved),
                'failed' => count($errors),
                'items' => array_map(fn (PriceAgreementItem $item, int $index): array => ['index' => $index, ...PricePresenter::agreementItem($item)], array_values($saved), array_keys($saved)),
                'errors' => $errors,
            ],
        ], $errors === [] ? 201 : ($saved === [] ? 422 : 200), [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Activates a draft without a signature; reason (5–500 characters) says why and is kept on the agreement. */
    public function activate(Request $request, int $agreement, ActivatePriceAgreement $activate): JsonResponse
    {
        $reason = ApiInput::body($request->json()->all())['reason'] ?? '';
        $saved = $activate($this->user(), $this->company(), $this->find($agreement, 'view'), is_string($reason) ? $reason : '');

        return CursorList::one($this->presentWithItems($saved->refresh()));
    }

    /** Ends an active agreement on terminated_on (Y-m-d, today or later). */
    public function terminate(Request $request, int $agreement, TerminatePriceAgreement $terminate): JsonResponse
    {
        $day = ApiInput::body($request->json()->all())['terminated_on'] ?? '';
        $saved = $terminate($this->user(), $this->company(), $this->find($agreement, 'view'), is_string($day) ? $day : '');

        return CursorList::one($this->presentWithItems($saved->refresh()));
    }

    private function find(int $id, string $ability): PriceAgreement
    {
        $agreement = PriceAgreement::query()->findOrFail($id);
        Gate::forUser($this->user())->authorize($ability, $agreement);

        return $agreement;
    }

    /** @return array<string, mixed> */
    private function presentWithItems(PriceAgreement $agreement): array
    {
        $items = PriceAgreementItem::query()->where('price_agreement_id', $agreement->id)->with(['article:id,number', 'unit:id,code'])->orderBy('id')->get();

        return [...PricePresenter::agreement($agreement), 'items' => $items->map(fn (PriceAgreementItem $item): array => PricePresenter::agreementItem($item))->all()];
    }

    /**
     * One term as SavePriceAgreementItem reads it: article by number (or
     * target "all" for a discount on every article), unit by code, a price in
     * the agreement's minor unit or a discount in basis points, and for a
     * discount optionally the price list it is taken from.
     *
     * @param  array<string, mixed>  $row
     * @param  SupportCollection<string, int>  $articles
     * @return array<string, mixed>
     */
    private function itemInput(array $row, SupportCollection $articles, UnitRegister $units): array
    {
        $all = ($row['target'] ?? 'article') === 'all';
        $number = is_string($row['article'] ?? null) ? trim($row['article']) : '';
        $articleId = $all ? null : ($number === '' ? ($row['article_id'] ?? null) : $articles->get($number));

        if (! $all && ! is_int($articleId)) {
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
            'target' => $all ? 'all' : 'article',
            'article_id' => $articleId,
            'unit_id' => $unitId,
            'min_quantity' => ApiInput::quantity($row['min_quantity'] ?? '0', 'min_quantity'),
            'mode' => $hasPrice ? 'price' : 'discount',
            'price' => $hasPrice ? ApiInput::kronor($row['price_minor'], 'price_minor') : null,
            'discount' => $hasDiscount ? ApiInput::percent($row['discount_bp'], 'discount_bp') : null,
            'base_price_list_id' => $row['base_price_list_id'] ?? null,
        ];
    }
}
