<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Auth\Access\Response;

/**
 * The first of three levels of voucher immutability (AGENTS.md §1.2): nobody
 * may update or delete a posted voucher, whatever their role.
 *
 * A member limited to cost centres or projects (ADR-081 gap 10) reaches a
 * voucher only when its rows are inside the scope; any other answers 404, as
 * another company's voucher does.
 */
class VoucherPolicy
{
    public function viewAny(User $user, Company $company): bool|Response
    {
        if ($user->hasTeamPermission($company, 'vouchers:view')) {
            return true;
        }

        return DimensionScope::limits($user, $company) ? Response::denyAsNotFound() : false;
    }

    public function view(User $user, Voucher $voucher): bool|Response
    {
        return $this->can($user, $voucher, 'vouchers:view');
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'vouchers:create');
    }

    public function update(User $user, Voucher $voucher): bool|Response
    {
        if ($voucher->isPosted()) {
            return false;
        }

        return $this->can($user, $voucher, 'vouchers:create');
    }

    public function delete(User $user, Voucher $voucher): bool|Response
    {
        return $this->update($user, $voucher);
    }

    /**
     * Underlag can be added to drafts and to posted vouchers, never removed
     * from posted ones (removal goes through update, which needs a draft).
     */
    public function attach(User $user, Voucher $voucher): bool|Response
    {
        return $this->can($user, $voucher, 'vouchers:create');
    }

    public function post(User $user, Voucher $voucher): bool|Response
    {
        if ($voucher->isPosted()) {
            return false;
        }

        return $this->can($user, $voucher, 'vouchers:post');
    }

    public function correct(User $user, Voucher $voucher): bool|Response
    {
        if (! $voucher->isPosted() || $voucher->corrected_by_voucher_id !== null) {
            return false;
        }

        return $this->can($user, $voucher, 'vouchers:correct');
    }

    private function can(User $user, Voucher $voucher, string $permission): bool|Response
    {
        $company = Company::query()->find($voucher->company_id);

        if (! $company instanceof Company) {
            return false;
        }

        // A limited member is answered 404 for whatever the scope keeps from them, as for another company's voucher.
        if (! $user->hasTeamPermission($company, $permission)) {
            return DimensionScope::limits($user, $company) ? Response::denyAsNotFound() : false;
        }

        return DimensionScope::for($user, $company)->allowsRecord($voucher) ? true : Response::denyAsNotFound();
    }
}
