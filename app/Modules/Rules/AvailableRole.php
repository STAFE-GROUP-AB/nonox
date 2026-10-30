<?php

declare(strict_types=1);

namespace App\Modules\Rules;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A role may be given only when it is a core role or its module is on for
 * the company (PAYROLL_MODULE.md A.7).
 */
final readonly class AvailableRole implements ValidationRule
{
    public function __construct(private Company $company) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $module = app(ModuleRegistry::class)->moduleForRole((string) $value);

        if ($module !== null && ! app(ModuleManager::class)->isEnabled($module->key(), $this->company)) {
            $fail(__('Rollen hör till modulen :module, som inte är påslagen.', ['module' => $module->name()]));
        }
    }
}
