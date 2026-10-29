<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits every query on a company-owned model to the current company. With no
 * current company it matches nothing: the scope fails closed (AGENTS.md §1.3).
 *
 * @implements Scope<Model>
 */
final class CompanyScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (app(CompanyContext::class)->bypassDepth > 0) {
            return;
        }

        $companyId = CurrentCompany::id();
        $column = $model->qualifyColumn('company_id');

        if ($companyId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($column, $companyId);
    }

    /**
     * Run a callback without the company filter. Every call needs a comment
     * explaining why it is safe (AGENTS.md §6.0).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function bypass(callable $callback): mixed
    {
        $context = app(CompanyContext::class);
        $context->bypassDepth++;

        try {
            return $callback();
        } finally {
            $context->bypassDepth--;
        }
    }
}
