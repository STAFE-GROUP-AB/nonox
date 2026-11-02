<?php

declare(strict_types=1);

namespace App\Modules\Connect\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\ConnectModule;
use App\Modules\Connect\Models\ConnectSftpAccount;
use App\Modules\Connect\Models\ConnectSftpKey;
use App\Modules\Connect\Sftp\SshPublicKey;
use App\Modules\Connect\Support\PasskeyStepUp;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Lets an SSH key log in to the company's SFTP drop (ADR-032, "SFTP"). Only
 * an owner or administrator, only after a fresh passkey confirmation, and
 * only ed25519 or RSA of at least 3072 bits (SshPublicKey). Only the public
 * key is given; its fingerprint is shown so it can be compared with the
 * sender's.
 */
final class AddSftpKey
{
    public const int MAX_KEYS = 10;

    /**
     * @param  array<string, mixed>  $input  name, public_key
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, array $input): ConnectSftpKey
    {
        if (! $user->hasTeamPermission($company, 'connect:manage')) {
            throw new AuthorizationException;
        }

        app(ModuleManager::class)->ensureEnabled(ConnectModule::KEY, $company);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'public_key' => ['required', 'string', 'max:4096'],
        ], [
            'public_key.required' => __('Klistra in den publika nyckeln.'),
        ], [
            'name' => __('namn'),
            'public_key' => __('publik nyckel'),
        ])->validate();

        $key = SshPublicKey::parse((string) $validated['public_key']);

        if ($key === null) {
            throw ValidationException::withMessages(['public_key' => SshPublicKey::refusal((string) $validated['public_key'])]);
        }

        $account = CurrentCompany::run($company, fn (): ?ConnectSftpAccount => ConnectSftpAccount::query()->first());

        if (! $account instanceof ConnectSftpAccount) {
            throw ValidationException::withMessages(['public_key' => __('Slå på SFTP först.')]);
        }

        $active = CurrentCompany::run($company, fn () => ConnectSftpKey::query()->active()->where('account_id', $account->id)->pluck('fingerprint'));

        if ($active->contains($key->fingerprint())) {
            throw ValidationException::withMessages(['public_key' => __('Den nyckeln finns redan.')]);
        }

        if ($active->count() >= self::MAX_KEYS) {
            throw ValidationException::withMessages(['public_key' => __('Ett företag kan ha högst :max SFTP-nycklar. Ta bort en som inte används.', ['max' => self::MAX_KEYS])]);
        }

        PasskeyStepUp::consume($user);

        return CurrentCompany::run($company, fn (): ConnectSftpKey => DB::transaction(function () use ($user, $company, $account, $key, $validated): ConnectSftpKey {
            $stored = ConnectSftpKey::create([
                'account_id' => $account->id,
                'name' => trim((string) $validated['name']),
                'algorithm' => $key->algorithm,
                'bits' => $key->bits,
                'public_key' => $key->line(),
                'fingerprint' => $key->fingerprint(),
                'created_by' => $user->id,
            ]);

            Audit::record('connect.sftp.key_added', $stored, [
                'name' => $stored->name,
                'algorithm' => $key->label(),
                'fingerprint' => $stored->fingerprint,
            ], $company, $user);

            return $stored;
        }));
    }
}
