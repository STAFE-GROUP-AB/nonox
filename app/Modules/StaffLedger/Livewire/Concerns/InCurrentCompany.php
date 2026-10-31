<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Livewire\Concerns;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * @mixin Component
 */
trait InCurrentCompany
{
    protected function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }

    protected function allowed(string $permission): bool
    {
        return $this->user()->hasTeamPermission($this->company(), $permission);
    }

    /** The first validation message, for a toast. */
    protected function firstError(ValidationException $exception): string
    {
        return (string) collect($exception->errors())->flatten()->first();
    }
}
