<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Http\Middleware\EndExpiredSessions;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\ConnectModule;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\SpansCompanies;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Ends every other session of the user, for example on a lost phone. Works
 * without a password: sessions that began before now are ended by
 * EndExpiredSessions on their next request.
 */
class SignOutEverywhere extends Component implements SpansCompanies
{
    use ShowsToasts;

    public function signOutOthers(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $now = CarbonImmutable::now();

        $user->forceFill(['sessions_valid_from' => $now])->save();
        session()->put(EndExpiredSessions::STARTED, $now->getTimestamp());

        Audit::record('user.signed_out_everywhere', $user, [], null, $user);

        $this->toast(__('Du är utloggad från alla andra enheter.'));
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $company = $user->currentCompany();

        return view('livewire.profile.sign-out-everywhere', [
            'hours' => EndExpiredSessions::ABSOLUTE_HOURS,
            // The way to the keys and apps only where Connect is on: Anslutningar answers 404 elsewhere.
            'connectOn' => $company instanceof Company && app(ModuleManager::class)->isEnabled(ConnectModule::KEY, $company),
        ]);
    }
}
