<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Livewire\Concerns;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Modules\WorkOrders\Models\WorkOrder;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * What every Arbetsorder page shares: the user and company, the permission
 * checks, and running an action with its errors on the form and a toast.
 *
 * @mixin Component
 */
trait WorkOrdersPage
{
    use InCurrentCompany;
    use ShowsToasts;

    protected function can(string $ability): bool
    {
        return Gate::forUser($this->user())->allows($ability, [WorkOrder::class, $this->company()]);
    }

    /** A work order of the company the user may see; another company's id or an unassigned one answers 404. */
    protected function findWorkOrder(int $id): WorkOrder
    {
        $workOrder = WorkOrder::query()->findOrFail($id);
        abort_unless(Gate::forUser($this->user())->allows('view', $workOrder), 404);

        return $workOrder;
    }

    /**
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
