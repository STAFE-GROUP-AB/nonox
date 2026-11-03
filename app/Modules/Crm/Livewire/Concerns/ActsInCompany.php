<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire\Concerns;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** The signed-in user and their current company, for the CRM's stage 2 pages. */
trait ActsInCompany
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

    /** The first message of a validation error, for a toast. */
    protected function firstError(ValidationException $exception): string
    {
        return (string) collect($exception->errors())->flatten()->first();
    }
}
