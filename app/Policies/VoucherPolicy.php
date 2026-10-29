<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Models\Voucher;

/**
 * The first of three levels of voucher immutability (AGENTS.md §1.2): nobody
 * may update or delete a posted voucher, whatever their role.
 */
class VoucherPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'vouchers:view');
    }

    public function view(User $user, Voucher $voucher): bool
    {
        return $this->can($user, $voucher, 'vouchers:view');
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'vouchers:create');
    }

    public function update(User $user, Voucher $voucher): bool
    {
        return ! $voucher->isPosted() && $this->can($user, $voucher, 'vouchers:create');
    }

    public function delete(User $user, Voucher $voucher): bool
    {
        return ! $voucher->isPosted() && $this->can($user, $voucher, 'vouchers:create');
    }

    /**
     * Underlag can be added to drafts and to posted vouchers, never removed
     * from posted ones (removal goes through update, which needs a draft).
     */
    public function attach(User $user, Voucher $voucher): bool
    {
        return $this->can($user, $voucher, 'vouchers:create');
    }

    public function post(User $user, Voucher $voucher): bool
    {
        return ! $voucher->isPosted() && $this->can($user, $voucher, 'vouchers:post');
    }

    public function correct(User $user, Voucher $voucher): bool
    {
        return $voucher->isPosted()
            && $voucher->corrected_by_voucher_id === null
            && $this->can($user, $voucher, 'vouchers:correct');
    }

    private function can(User $user, Voucher $voucher, string $permission): bool
    {
        $company = Company::query()->find($voucher->company_id);

        return $company instanceof Company && $user->hasTeamPermission($company, $permission);
    }
}
