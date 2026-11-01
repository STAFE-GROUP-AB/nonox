<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks a company as an accounting firm, and whether its clients see the
 * firm's logo and colours (ADR-030). Owner only. A company that stops being
 * a firm loses its clients' links, each recorded in both audit logs.
 */
class UpdateAccountingFirmSettings
{
    public function __construct(private readonly AccountingFirmLink $links) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, bool $isFirm, bool $brandForClients): void
    {
        if (! $user->ownsTeam($company)) {
            throw new AuthorizationException(__('Bara ägaren kan ändra redovisningsbyrån.'));
        }

        if ($company->isArchived()) {
            throw ValidationException::withMessages(['firm' => __('Företaget är arkiverat och kan inte ändras.')]);
        }

        DB::transaction(function () use ($user, $company, $isFirm, $brandForClients): void {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $before = ['is_accounting_firm' => $company->is_accounting_firm, 'firm_brand_for_clients' => $company->firm_brand_for_clients];

            if ($isFirm && $company->accounting_firm_id !== null) {
                // A firm shows its own brand; it does not show another firm's.
                $this->links->detach($company, AccountingFirmLink::BY_CLIENT, $user);
            }

            $company->forceFill(['is_accounting_firm' => $isFirm, 'firm_brand_for_clients' => $brandForClients])->save();

            if (! $isFirm) {
                $company->firmClients()->lockForUpdate()->get()
                    ->each(fn (Company $client) => $this->links->detach($client, AccountingFirmLink::FIRM_DISABLED, $user, $company));
            }

            $after = ['is_accounting_firm' => $isFirm, 'firm_brand_for_clients' => $brandForClients];

            if ($before !== $after) {
                Audit::record('company.accounting_firm_settings_changed', $company, ['before' => $before, 'after' => $after], $company, $user);
            }
        });
    }
}
