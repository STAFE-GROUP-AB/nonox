<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Peppol\Domain\AccessPointFailed;
use App\Modules\Peppol\Domain\ParticipantLookup;
use App\Modules\Peppol\PeppolModule;
use App\Modules\Peppol\Support\AccessPoints;
use App\Support\Peppol\PeppolIdentifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Asks the network (through the access point's SMP lookup) whether a
 * customer can receive Peppol BIS Billing 3.0 invoices, before the first
 * one is sent. At most 30 lookups a minute per company.
 */
class LookupParticipant
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function __invoke(User $user, Company $company, string $identifier): ParticipantLookup
    {
        abort_unless($user->hasTeamPermission($company, 'invoices:create') || $user->hasTeamPermission($company, 'invoices:send'), 403);
        $this->modules->ensureEnabled(PeppolModule::KEY, $company);

        $participant = PeppolIdentifier::tryFrom($identifier)
            ?? throw ValidationException::withMessages(['peppol_id' => __('Skriv Peppol-ID som schema och nummer, till exempel 0007:5561234567.')]);

        if (RateLimiter::tooManyAttempts("peppol-lookup:{$company->id}", 30)) {
            throw ValidationException::withMessages(['peppol_id' => __('För många kontroller just nu. Försök igen om en minut.')]);
        }

        RateLimiter::hit("peppol-lookup:{$company->id}", 60);

        try {
            return AccessPoints::make()->lookup($participant);
        } catch (AccessPointFailed|ConnectionException) {
            throw ValidationException::withMessages(['peppol_id' => __('Peppol-nätverket svarar inte just nu. Försök igen senare.')]);
        }
    }
}
