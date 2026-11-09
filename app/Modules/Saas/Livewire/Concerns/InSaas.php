<?php

declare(strict_types=1);

namespace App\Modules\Saas\Livewire\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Modules\Saas\SaasModule;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The pages of Återkommande intäkter: the signed-in member, the current
 * company, and the permission check every page makes (ADR-080 §1).
 *
 * @mixin Component
 */
trait InSaas
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

    protected function authorizeView(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), SaasModule::VIEW), 403);
    }

    protected function canManage(): bool
    {
        return $this->user()->hasTeamPermission($this->company(), SaasModule::MANAGE);
    }
}
