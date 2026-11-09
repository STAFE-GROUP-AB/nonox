<?php

declare(strict_types=1);

namespace App\Modules\Saas\Console;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\Saas\SaasModule;

/** The companies a saas command runs for: one by id, or every company with the module on. */
final class Companies
{
    /** @return list<Company>|null null when the id names no company */
    public static function from(mixed $identifier): ?array
    {
        if (is_string($identifier) && $identifier !== '') {
            $company = ctype_digit($identifier) ? Company::query()->find((int) $identifier) : null;

            return $company instanceof Company ? [$company] : null;
        }

        $ids = app(ModuleManager::class)->companiesWith(SaasModule::KEY);

        return array_values(Company::query()->whereIn('id', $ids)->orderBy('id')->get()->all());
    }
}
