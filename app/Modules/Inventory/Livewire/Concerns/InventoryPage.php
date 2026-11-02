<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire\Concerns;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * What every Lager page shares: the current user and company, the
 * permission checks, and running an action with its errors shown on the
 * form and in a toast.
 *
 * @mixin Component
 */
trait InventoryPage
{
    use InCurrentCompany;
    use ShowsToasts;

    protected function can(string $ability): bool
    {
        return Gate::forUser($this->user())->allows($ability, [StockMovement::class, $this->company()]);
    }

    protected function seesPrices(): bool
    {
        return $this->can('seePrices');
    }

    /**
     * Runs the action; on a validation error shows it under the field
     * (prefixed) and as a toast, and returns false.
     *
     * @param  Closure(): mixed  $action
     */
    protected function attempt(Closure $action, string $success, string $prefix = 'form.'): bool
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["{$prefix}{$key}" => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return false;
        }

        $this->resetErrorBag();

        if ($success !== '') {
            $this->toast($success);
        }

        return true;
    }
}
