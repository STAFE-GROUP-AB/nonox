<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Models\ClientProfile;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets the consultant responsible for a client company, and the accounting
 * firm's note about it. The consultant is chosen among the company's
 * members with the Redovisningskonsult role, by such a member or by the
 * company's owner or an administrator. Only consultants write and read the
 * note: it is the firm's, not the client's.
 */
class SaveClientProfile
{
    public const int NOTE_MAX = 2000;

    public function assign(User $user, Company $company, ?int $responsibleId): ClientProfile
    {
        if (! $user->ownsTeam($company) && ! in_array(self::roleOf($user, $company), [ClientProfile::FIRM_ROLE, 'admin'], true)) {
            throw new AuthorizationException;
        }

        if ($responsibleId !== null && ! $company->users()->whereKey($responsibleId)->wherePivot('role', ClientProfile::FIRM_ROLE)->exists()) {
            throw ValidationException::withMessages(['responsible' => __('Den ansvarige ska vara redovisningskonsult i företaget.')]);
        }

        return $this->save($company, function (ClientProfile $profile) use ($user, $company, $responsibleId): void {
            $before = $profile->responsible_user_id;
            $profile->forceFill(['responsible_user_id' => $responsibleId])->save();
            Audit::record('company.responsible_changed', $profile, ['before' => $before, 'after' => $responsibleId], $company, $user);
        });
    }

    public function note(User $user, Company $company, string $note): ClientProfile
    {
        if (! self::isConsultant($user, $company)) {
            throw new AuthorizationException;
        }

        $note = trim($note);

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['note' => __('Anteckningen får vara högst :max tecken.', ['max' => self::NOTE_MAX])]);
        }

        return $this->save($company, function (ClientProfile $profile) use ($user, $company, $note): void {
            $profile->forceFill(['note' => $note === '' ? null : $note, 'note_updated_by' => $user->id, 'note_updated_at' => now()])->save();
            // The note is the firm's: the audit trail says that it changed, not what it says.
            Audit::record('company.client_note_changed', $profile, ['length' => mb_strlen($note)], $company, $user);
        });
    }

    public static function isConsultant(User $user, Company $company): bool
    {
        return ! $user->ownsTeam($company) && self::roleOf($user, $company) === ClientProfile::FIRM_ROLE;
    }

    /** The member's role, read straight from the membership. */
    private static function roleOf(User $user, Company $company): ?string
    {
        $role = DB::table('team_user')->where('team_id', $company->id)->where('user_id', $user->id)->value('role');

        return is_string($role) ? $role : null;
    }

    /**
     * @param  callable(ClientProfile): void  $change
     */
    private function save(Company $company, callable $change): ClientProfile
    {
        return CurrentCompany::run($company, fn (): ClientProfile => DB::transaction(function () use ($change): ClientProfile {
            $profile = ClientProfile::query()->lockForUpdate()->firstOrCreate([]);
            $change($profile);

            return $profile;
        }));
    }
}
