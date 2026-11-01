<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Domain\Company\AccountingFirms;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Links a client company to its accounting firm, and removes the link
 * (ADR-030). The client's owner chooses the firm among those a consultant of
 * the client is in (AccountingFirms::eligibleFor); either side's owner can
 * remove the link. The link is branding only: it never gives the firm, or
 * anyone in it, access to the client. Every change is written to the audit
 * log of both companies.
 */
class AccountingFirmLink
{
    public const string BY_CLIENT = 'client';

    public const string BY_FIRM = 'firm';

    public const string NO_CONSULTANT = 'no_consultant';

    public const string FIRM_DISABLED = 'firm_disabled';

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function link(User $user, Company $client, int $firmId): void
    {
        self::authorizeOwner($user, $client);

        if ($client->isArchived()) {
            throw ValidationException::withMessages(['firm' => __('Företaget är arkiverat och kan inte ändras.')]);
        }

        // An unknown firm, one without a shared consultant and a company that is not a firm get the same answer.
        if (! AccountingFirms::isEligible($client, $firmId)) {
            throw ValidationException::withMessages(['firm' => __('Välj en byrå i listan. Byrån behöver ha en redovisningskonsult i ert företag.')]);
        }

        DB::transaction(function () use ($user, $client, $firmId): void {
            $client = Company::query()->lockForUpdate()->findOrFail($client->id);

            if ($client->accounting_firm_id === $firmId) {
                return;
            }

            if ($client->accounting_firm_id !== null) {
                $this->detach($client, self::BY_CLIENT, $user);
            }

            $firm = Company::query()->findOrFail($firmId);

            $client->forceFill([
                'accounting_firm_id' => $firm->id,
                'accounting_firm_linked_at' => now(),
                'accounting_firm_linked_by' => $user->id,
            ])->save();

            Audit::record('company.accounting_firm_linked', $client, ['firm_id' => $firm->id, 'firm_name' => $firm->name], $client, $user);
            Audit::record('company.firm_client_linked', $firm, ['client_id' => $client->id, 'client_name' => $client->name], $firm, $user);
        });
    }

    /**
     * The client's owner removes the company's link.
     *
     * @throws AuthorizationException
     */
    public function unlinkFromClient(User $user, Company $client): void
    {
        self::authorizeOwner($user, $client);

        DB::transaction(function () use ($user, $client): void {
            $client = Company::query()->lockForUpdate()->findOrFail($client->id);

            if ($client->accounting_firm_id !== null) {
                $this->detach($client, self::BY_CLIENT, $user);
            }
        });
    }

    /**
     * The firm's owner removes a client's link to the firm. A company that
     * is not linked to this firm is "not found", whatever it is.
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException<Company>
     */
    public function unlinkFromFirm(User $user, Company $firm, int $clientId): void
    {
        self::authorizeOwner($user, $firm);

        DB::transaction(function () use ($user, $firm, $clientId): void {
            $client = Company::query()->where('accounting_firm_id', $firm->id)->lockForUpdate()->findOrFail($clientId);

            $this->detach($client, self::BY_FIRM, $user, $firm);
        });
    }

    /** Removes the link and records it in both companies. Call inside a transaction. */
    public function detach(Company $client, string $by, ?User $user = null, ?Company $firm = null): void
    {
        $firm = $firm?->id === $client->accounting_firm_id ? $firm : Company::query()->find($client->accounting_firm_id);

        $client->forceFill(['accounting_firm_id' => null, 'accounting_firm_linked_at' => null, 'accounting_firm_linked_by' => null])->save();

        Audit::record('company.accounting_firm_unlinked', $client, ['firm_id' => $firm?->id, 'firm_name' => $firm?->name, 'by' => $by], $client, $user);

        if ($firm instanceof Company) {
            Audit::record('company.firm_client_unlinked', $firm, ['client_id' => $client->id, 'client_name' => $client->name, 'by' => $by], $firm, $user);
        }
    }

    /** @throws AuthorizationException */
    private static function authorizeOwner(User $user, Company $company): void
    {
        if (! $user->ownsTeam($company)) {
            throw new AuthorizationException(__('Bara ägaren kan ändra redovisningsbyrån.'));
        }
    }
}
