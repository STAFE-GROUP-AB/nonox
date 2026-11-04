<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Events\UserAccountDeleting;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\LoginCode;
use App\Models\TeamInvitation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Radera mitt konto" (GDPR art. 17). Refused while the user owns a company
 * that is not archived: the company needs an owner, so ownership is
 * transferred or the company archived first.
 *
 * Everything that belongs to the account itself goes: memberships, sessions,
 * API tokens, sign-in codes, pending invitations and the profile photo. Then
 * every foreign key to users is checked. If nothing refers to the user any
 * more, the row is deleted. Otherwise the row is kept but pseudonymised
 * (name "Raderad användare", an address at the reserved .invalid domain,
 * no way to sign in), because posted vouchers, invoices and the audit log
 * must keep showing that the same person did what (bokföringslagen 7 kap.,
 * GDPR art. 17.3 b).
 *
 * The audit entry holds no personal data: no name, no address, no IP.
 *
 * TODO(legal-verify): whether BFNAR 2013:2 requires the company to be able to
 * name the person behind a posting after the account is pseudonymised (today
 * only the user id remains).
 */
class DeleteAccount
{
    public const string DELETED = 'deleted';

    public const string PSEUDONYMISED = 'pseudonymised';

    public const string PSEUDONYM = 'Raderad användare';

    /** RFC 2606: .invalid never resolves, so no mail can ever reach the address. */
    public const string PLACEHOLDER_DOMAIN = 'konto.invalid';

    /** @return self::DELETED|self::PSEUDONYMISED */
    public function __invoke(User $user): string
    {
        $owned = $this->ownedCompanies($user);

        if ($owned !== []) {
            throw ValidationException::withMessages([
                'account' => __('Du är ägare till :companies. Överlåt ägarskapet till någon annan eller arkivera företaget innan du raderar ditt konto.', ['companies' => implode(', ', $owned)]),
            ]);
        }

        return DB::transaction(function () use ($user): string {
            $email = mb_strtolower($user->email);
            $companyIds = array_values(DB::table('team_user')->where('user_id', $user->id)->pluck('team_id')->map(fn (mixed $id): int => (int) $id)->all());

            DB::table('team_user')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            LoginCode::query()->whereRaw('lower(email) = ?', [$email])->delete();
            TeamInvitation::query()->whereRaw('lower(email) = ?', [$email])->delete();
            // The user's own filters and notices on Mina företag (ADR-028) are nobody else's.
            DB::table('saved_views')->where('user_id', $user->id)->delete();
            // Passkeys (ADR-029) and the recently opened records in search (ADR-026) are the user's alone.
            DB::table('passkeys')->where('user_id', $user->id)->delete();
            DB::table('recent_items')->where('user_id', $user->id)->delete();
            $user->notifications()->delete();
            // Modules remove what is the user's alone (CRM calendar links and connections, ADR-049).
            event(new UserAccountDeleting($user->id));

            if ($user->profile_photo_path !== null) {
                Storage::disk((string) config('jetstream.profile_photo_disk', 'public'))->delete($user->profile_photo_path);
            }

            $outcome = $this->isReferenced($user) ? self::PSEUDONYMISED : self::DELETED;

            if ($outcome === self::DELETED) {
                $user->delete();
            } else {
                $this->pseudonymise($user);
            }

            $this->audit($user->id, $outcome, $companyIds);

            return $outcome;
        });
    }

    /**
     * Names of the companies the user owns that are not archived.
     *
     * @return list<string>
     */
    public function ownedCompanies(User $user): array
    {
        return array_values(Company::query()
            ->where('user_id', $user->id)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->values()
            ->all());
    }

    /**
     * Whether any row anywhere still refers to the user through a foreign
     * key. Read from the database catalogue, so a table added later is
     * covered without changing this class.
     */
    private function isReferenced(User $user): bool
    {
        $references = DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS table_name, a.attname AS column_name
            FROM pg_constraint c
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
            WHERE c.contype = 'f' AND c.confrelid = 'users'::regclass
            SQL);

        foreach ($references as $reference) {
            if (DB::table((string) $reference->table_name)->where((string) $reference->column_name, $user->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function pseudonymise(User $user): void
    {
        $now = CarbonImmutable::now();

        $user->forceFill([
            'name' => self::PSEUDONYM,
            'email' => 'raderad-'.$user->id.'-'.Str::lower(Str::random(20)).'@'.self::PLACEHOLDER_DOMAIN,
            'email_verified_at' => null,
            'remember_token' => null,
            'current_team_id' => null,
            'profile_photo_path' => null,
            'hidden_panels' => null,
            'sessions_valid_from' => $now,
            'anonymised_at' => $now,
        ])->save();
    }

    /**
     * One entry for the account and one per company it was a member of, so
     * each company's history shows that the member left. No IP or browser:
     * the person asked to be erased.
     *
     * @param  list<int>  $companyIds
     */
    private function audit(int $userId, string $outcome, array $companyIds): void
    {
        foreach ([null, ...$companyIds] as $companyId) {
            AuditLog::create([
                'company_id' => $companyId,
                // A deleted row cannot be referred to; the id stays in subject_id.
                'user_id' => $outcome === self::PSEUDONYMISED ? $userId : null,
                'action' => 'user.account_deleted',
                'subject_type' => (new User)->getMorphClass(),
                'subject_id' => (string) $userId,
                'payload' => ['outcome' => $outcome],
            ]);
        }
    }
}
