<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Domain\Invoicing\InvoiceCalculator;
use App\Models\Account;
use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Support\ArticleDetails;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or updates an article. The revenue account follows the VAT rate
 * unless another is chosen: 3001 (25 %), 3002 (12 %), 3003 (6 %), 3004
 * (momsfri), as in the BAS chart.
 */
class SaveArticle
{
    public const array DEFAULT_ACCOUNTS = [2500 => 3001, 1200 => 3002, 600 => 3003, 0 => 3004];

    /**
     * @param  array<string, mixed>  $input  number, name, unit, price (kronor as written), vat_rate, account (number, optional), and any of the advanced fields in ArticleDetails::KEYS
     */
    public function __invoke(User $user, Company $company, array $input, ?Article $article = null): Article
    {
        $article === null
            ? Gate::forUser($user)->authorize('create', [Article::class, $company])
            : Gate::forUser($user)->authorize('update', $article);

        $validated = Validator::make($input, [
            'number' => ['required', 'string', 'max:30', Rule::unique('articles', 'number')->where('company_id', $company->id)->ignore($article?->id)],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:10'],
            'price' => ['required', 'string'],
            'vat_rate' => ['required', 'integer', Rule::in(InvoiceCalculator::VAT_RATES)],
            'account' => ['nullable', 'integer', 'between:3000,3999'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'number.unique' => __('Artikelnumret :number används redan.', ['number' => $input['number'] ?? '']),
            'account.between' => __('Välj ett intäktskonto mellan 3000 och 3999.'),
        ], [
            'number' => __('artikelnummer'),
            'name' => __('benämning'),
            'unit' => __('enhet'),
            'price' => __('pris'),
        ])->validate();

        try {
            $price = Money::parse((string) $validated['price']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['price' => __('Priset går inte att läsa. Skriv till exempel 149,00.')]);
        }

        return DB::transaction(fn (): Article => CurrentCompany::run($company, function () use ($validated, $input, $price, $article, $company, $user): Article {
            $rate = (int) $validated['vat_rate'];
            $number = (int) ($validated['account'] ?? self::DEFAULT_ACCOUNTS[$rate]);
            $account = Account::query()->where('number', $number)->first()
                ?? throw ValidationException::withMessages(['account' => __('Konto :number finns inte i kontoplanen.', ['number' => $number])]);

            $attributes = [
                'number' => trim((string) $validated['number']),
                'name' => trim((string) $validated['name']),
                'unit' => trim((string) $validated['unit']),
                'price_ore' => $price->ore,
                'vat_rate' => $rate,
                'account_id' => $account->id,
                'is_active' => (bool) ($validated['is_active'] ?? $article->is_active ?? true),
                ...ArticleDetails::attributes($input, $company, $article),
            ];

            if ($article === null) {
                $article = Article::create($attributes);
                Audit::record('article.created', $article, ['number' => $article->number, 'name' => $article->name], $company, $user);

                return $article;
            }

            $article->update($attributes);
            Audit::record('article.updated', $article, ['number' => $article->number, 'name' => $article->name], $company, $user);

            return $article;
        }));
    }
}
