<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Actions\Invoicing\SaveArticle;
use App\Domain\Units\UnitRegister;
use App\Models\Article;
use App\Modules\Connect\Http\Controllers\Api\V1\Concerns\ActsAsCredential;
use App\Modules\Connect\Http\Resources\ApiPresenter;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Connect\Support\ApiInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Articles over the API (ADR-032), through SaveArticle like the UI. Prices
 * are whole öre excluding VAT; the VAT rate is in basis points (2500 is
 * 25 %); the revenue account follows the rate unless one is given.
 */
final class ArticleController
{
    use ActsAsCredential;

    public function index(Request $request): JsonResponse
    {
        $query = Article::query()->with('account');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return CursorList::respond($request, $query, fn (Article $article): array => ApiPresenter::article($article));
    }

    public function show(Article $article): JsonResponse
    {
        return CursorList::one(ApiPresenter::article($article->load('account')));
    }

    public function store(Request $request, SaveArticle $save): JsonResponse
    {
        $article = $save($this->user(), $this->company(), $this->input(ApiInput::body($request->json()->all())));

        return CursorList::one(ApiPresenter::article($article->refresh()->load('account')), 201)
            ->header('Location', route('connect.api.articles.show', $article));
    }

    public function update(Request $request, Article $article, SaveArticle $save): JsonResponse
    {
        $current = ApiPresenter::article($article->load('account'));
        $body = [...array_intersect_key($current, array_flip(['number', 'name', 'unit', 'price_ore', 'vat_rate', 'account', 'is_active'])), ...ApiInput::body($request->json()->all())];

        $saved = $save($this->user(), $this->company(), $this->input($body), $article);

        return CursorList::one(ApiPresenter::article($saved->refresh()->load('account')));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function input(array $body): array
    {
        $input = array_intersect_key($body, array_flip(['number', 'name', 'unit', 'vat_rate', 'account', 'is_active']));

        // unit_id names a unit of the company's register; a unit text must be one of its codes or aliases (ADR-071 §12.9).
        if (($body['unit_id'] ?? null) !== null) {
            $unit = UnitRegister::load()->find($body['unit_id'])
                ?? throw ValidationException::withMessages(['unit_id' => __('Enheten finns inte i företagets enhetslista.')]);
            UnitRegister::load()->assertSelectable(['unit_id' => $unit->id]);
            $input['unit'] = $unit->code;
        }

        if (array_key_exists('price_ore', $body)) {
            $input['price'] = ApiInput::kronor($body['price_ore'], 'price_ore');
        }

        return $input;
    }
}
