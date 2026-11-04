<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Actions;

use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inbox\InboxModule;
use App\Modules\ModuleManager;
use App\Modules\Peppol\Domain\AccessPointFailed;
use App\Modules\Peppol\Domain\RegistrationStatus;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\PeppolModule;
use App\Modules\Peppol\Support\AccessPoints;
use App\Support\Audit\Audit;
use App\Support\Peppol\PeppolIdentifier;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;

/**
 * The company's Peppol settings (ADR-050): its own Peppol ID, whether it
 * receives invoices, and its registration as a receiver.
 *
 * A Swedish company is addressed by its own organisation number (0007) or
 * by a GLN (0088), which Upphandlingsmyndigheten advises sole traders to
 * use instead of a personal identity number. A 0007 ID must be the
 * company's own number. A GLN cannot be checked here; the access-point
 * provider identifies the company before it publishes it.
 *
 * Registering publishes the company as a receiver in the provider's SMP.
 * It needs the explicit consent of someone who may represent the company,
 * which is recorded (who and when), and an account with the provider that
 * the operator has linked; until then the registration waits.
 */
class SavePeppolSettings
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function __invoke(User $user, Company $company, string $identifier, bool $receive, bool $register = false, bool $consent = false): PeppolParticipant
    {
        abort_unless($user->hasTeamPermission($company, 'settings:manage'), 403);
        $this->modules->ensureEnabled(PeppolModule::KEY, $company);

        $participant = PeppolIdentifier::tryFrom($identifier);
        $own = OrganisationNumber::tryFrom((string) $company->org_number);

        $error = match (true) {
            $participant === null => __('Skriv Peppol-ID som schema och nummer, till exempel 0007:5599000006.'),
            ! in_array($participant->scheme, [PeppolIdentifier::ORGANISATION_NUMBER, PeppolIdentifier::GLN], true) => __('Ett svenskt företag har organisationsnummer (0007) eller GLN (0088) som Peppol-ID.'),
            $participant->scheme === PeppolIdentifier::ORGANISATION_NUMBER && $participant->value !== $own?->digits => __('Peppol-ID med 0007 måste vara företagets eget organisationsnummer.'),
            // Sending may use 0007; receiving publishes the ID in the open Peppol Directory.
            ($receive || $register) && $participant->scheme === PeppolIdentifier::ORGANISATION_NUMBER && PeppolIdentifier::usesPersonalIdentityNumber($company) => __('En enskild firma registreras med ett GLN (0088), så att personnumret inte publiceras i Peppols öppna adressbok.'),
            $receive && ! $this->modules->isEnabled(InboxModule::KEY, $company) => __('Slå på Inkorg först. E-fakturor som tas emot hamnar i inkorgen för attest.'),
            $register && ! $consent => __('Bekräfta att du får företräda företaget och godkänner registreringen.'),
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['identifier' => $error]);
        }

        /** @var PeppolIdentifier $participant */
        // One company per Peppol ID on the installation, or inbound documents would have two receivers.
        $taken = CompanyScope::bypass(fn (): bool => PeppolParticipant::query()->where('identifier', $participant->toString())->where('company_id', '<>', $company->id)->exists());

        if ($taken) {
            throw ValidationException::withMessages(['identifier' => __('Peppol-ID:t kan inte användas för det här företaget.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $participant, $receive, $register): PeppolParticipant {
            $record = PeppolParticipant::query()->first() ?? new PeppolParticipant;
            $changed = $record->exists && $record->identifier !== $participant->toString();

            // Receiving off, or another ID: the company leaves the Peppol Directory first, so suppliers stop sending here.
            if ($record->exists && (! $receive || $changed)) {
                $this->deregister($user, $company, $record);
            }

            $record->fill(['identifier' => $participant->toString(), 'receive_enabled' => $receive]);

            $record->save();

            Audit::record('peppol.settings_saved', $record, ['scheme' => $participant->scheme, 'receive' => $receive, 'identifier_changed' => $changed], $company, $user);

            if ($register) {
                $this->register($user, $company, $record);
            }

            return $record->refresh();
        });
    }

    /** @throws ValidationException when the access point could not remove the registration; nothing is changed then */
    public function deregister(User $user, Company $company, PeppolParticipant $participant): void
    {
        if (! in_array($participant->registration_status, [RegistrationStatus::Registered, RegistrationStatus::Pending], true)) {
            return;
        }

        if ($participant->canSend()) {
            try {
                AccessPoints::make($participant->provider)->deregister($participant);
            } catch (AccessPointFailed|ConnectionException $exception) {
                $participant->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();

                throw ValidationException::withMessages(['identifier' => __('Peppol-operatören kunde inte ta bort registreringen i Peppols adressbok. Försök igen om en stund.')]);
            }
        }

        $participant->forceFill(['registration_status' => RegistrationStatus::NotRegistered, 'registered_at' => null, 'last_error' => null])->save();
        Audit::record('peppol.deregistered', $participant, ['provider' => $participant->provider], $company, $user);
    }

    private function register(User $user, Company $company, PeppolParticipant $participant): void
    {
        $participant->forceFill(['consent_by' => $user->id, 'consent_at' => now()]);

        if (! $participant->canSend()) {
            $participant->forceFill(['registration_status' => RegistrationStatus::Pending])->save();
            Audit::record('peppol.registration_requested', $participant, ['scheme' => $participant->participant()->scheme], $company, $user);

            return;
        }

        try {
            $status = AccessPoints::make($participant->provider)->register($participant, $company);
            $participant->forceFill(['registration_status' => $status, 'registered_at' => $status === RegistrationStatus::Registered ? now() : null, 'last_error' => null])->save();
        } catch (AccessPointFailed $exception) {
            $participant->forceFill(['registration_status' => RegistrationStatus::Failed, 'last_error' => $exception->getMessage()])->save();
        }

        Audit::record('peppol.registered', $participant, ['status' => $participant->registration_status->value, 'provider' => $participant->provider], $company, $user);
    }
}
