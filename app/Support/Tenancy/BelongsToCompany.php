<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Required on every model with company data (AGENTS.md §1.3). Every such model
 * must also be registered in tests/Tenancy/TenancyIsolationTest.php.
 *
 * @mixin Model
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            $current = CurrentCompany::id();
            $given = $model->getAttribute('company_id');

            if ($given === null) {
                if ($current === null) {
                    throw MissingCompanyContext::whileCreating($model::class);
                }

                $model->setAttribute('company_id', $current);

                return;
            }

            if ($current === null && app(CompanyContext::class)->bypassDepth === 0) {
                throw MissingCompanyContext::whileCreating($model::class);
            }

            if ($current !== null && (int) $given !== $current) {
                throw MissingCompanyContext::foreignCompany($model::class);
            }
        });

        // After the hook above has set company_id: an archived company is read-only (ADR-024).
        static::creating(fn (Model $model) => ArchivedCompany::guardWrite($model, $model->getAttribute('company_id')));

        static::updating(function (Model $model): void {
            if ($model->isDirty('company_id')) {
                throw MissingCompanyContext::whileMoving($model::class);
            }

            ArchivedCompany::guardWrite($model, $model->getAttribute('company_id'));
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
