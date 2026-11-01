<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Actions\Privacy\DeleteAccount as DeleteAccountAction;
use App\Models\User;
use App\Support\Tenancy\SpansCompanies;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * "Radera mitt konto" on the profile page (GDPR art. 17). The person
 * confirms by typing RADERA; the account is then deleted or pseudonymised
 * (DeleteAccount) and the person is signed out.
 */
class DeleteAccount extends Component implements SpansCompanies
{
    public const string CONFIRMATION_WORD = 'RADERA';

    public bool $confirming = false;

    public string $confirmation = '';

    public function confirmDeletion(): void
    {
        $this->resetErrorBag();
        $this->confirmation = '';
        $this->confirming = true;
    }

    public function deleteAccount(DeleteAccountAction $deleteAccount): void
    {
        if (mb_strtoupper(trim($this->confirmation)) !== self::CONFIRMATION_WORD) {
            $this->addError('confirmation', __('Skriv :word för att bekräfta.', ['word' => self::CONFIRMATION_WORD]));

            return;
        }

        /** @var User $user */
        $user = Auth::user();

        if ($deleteAccount->ownedCompanies($user) !== []) {
            $this->confirming = false;
            $this->addError('account', __('Du är ägare till :companies. Överlåt ägarskapet till någon annan eller arkivera företaget innan du raderar ditt konto.', ['companies' => implode(', ', $deleteAccount->ownedCompanies($user))]));

            return;
        }

        $deleteAccount($user);

        // Not logout(): it saves a new remember token (writing a deleted row
        // back) and audits the sign-out under the erased user. Forgetting the
        // user and invalidating the session ends the sign-in all the same.
        Auth::guard('web')->forgetUser();
        session()->invalidate();
        session()->regenerateToken();

        Toast::flash(__('Ditt konto är raderat och du är utloggad.'));

        $this->redirect('/');
    }

    public function render(DeleteAccountAction $deleteAccount): View
    {
        /** @var User $user */
        $user = Auth::user();

        return view('livewire.profile.delete-account', [
            'ownedCompanies' => $deleteAccount->ownedCompanies($user),
            'word' => self::CONFIRMATION_WORD,
        ]);
    }
}
