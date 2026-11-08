<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Livewire\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Modules\Boardroom\BoardroomModule;
use App\Modules\ModuleManager;
use App\Support\Tenancy\ArchivedCompany;
use Illuminate\Auth\Access\AuthorizationException;
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

    /** Reading works for an archived company and with the module off (archive permissions). */
    protected function allowed(string $permission): bool
    {
        return (bool) ArchivedCompany::reading(fn (): bool => $this->user()->hasTeamPermission($this->company(), $permission));
    }

    /** Changes need the module on and the permission. */
    protected function mayChange(string $permission): bool
    {
        return app(ModuleManager::class)->isEnabled(BoardroomModule::KEY, $this->company())
            && $this->user()->hasTeamPermission($this->company(), $permission);
    }

    /** Runs an action and turns its refusals into a toast and the error bag; true when it went through. */
    protected function attempt(callable $action, string $prefix = 'form'): bool
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["{$prefix}.{$key}" => $messages])->all());
            $this->dispatch('toast', message: (string) collect($exception->errors())->flatten()->first(), type: 'error');

            return false;
        } catch (AuthorizationException $exception) {
            $this->dispatch('toast', message: $exception->getMessage() !== '' && $exception->getMessage() !== 'This action is unauthorized.' ? $exception->getMessage() : __('Du har inte behörighet att göra det.'), type: 'error');

            return false;
        }

        $this->resetErrorBag();

        return true;
    }
}
