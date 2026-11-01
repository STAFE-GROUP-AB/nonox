<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\PasskeysResetMail;
use App\Models\User;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Passkeys\Passkey;

/**
 * The last way back (ADR-029): the one running the server removes a user's
 * passkeys, for an owner who has lost every device and has no one else in
 * the company to ask. The user's sessions end, the reset goes to the audit
 * log of each of their companies, and the user is emailed.
 */
class ResetPasskeysCommand extends Command
{
    protected $signature = 'passkeys:reset {email : Användarens e-postadress} {--force : Fråga inte om bekräftelse}';

    protected $description = 'Tar bort alla passkeys för en användare som har tappat bort sina enheter.';

    public function handle(): int
    {
        $user = User::query()->byEmail((string) $this->argument('email'))->first();

        if (! $user instanceof User) {
            $this->error('Det finns ingen användare med den adressen.');

            return self::FAILURE;
        }

        $count = $user->passkeys()->count();

        if ($count === 0) {
            $this->comment("{$user->name} har inga passkeys.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Ta bort {$count} passkeys för {$user->name} och logga ut hen överallt?")) {
            $this->comment('Inget har ändrats.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $count): void {
            Passkey::query()->where('user_id', $user->id)->delete();

            $user->forceFill(['sessions_valid_from' => CarbonImmutable::now()])->save();

            foreach ($user->companies() as $company) {
                Audit::record('passkey.reset', $user, ['member' => $user->name, 'removed' => $count, 'via' => 'console'], $company);
            }
        });

        Mail::to($user->email)->send(new PasskeysResetMail($user->name, __('Den som driver NONOX-servern'), null));

        $this->info("{$count} passkeys för {$user->name} är borttagna. Hen lägger till en ny vid nästa inloggning.");

        return self::SUCCESS;
    }
}
