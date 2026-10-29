<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Auth\SendLoginCode;
use App\Actions\Auth\VerifyLoginCode;
use App\Models\User;
use App\Support\LoginCodeAutofill;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Passwordless sign-in (ADR-000): email → one-time code → signed in. A new
 * address only adds a name step. The verified address is kept in the session,
 * never trusted from the browser.
 */
class LoginForm extends Component
{
    private const string PENDING_EMAIL = 'login.pending_email';

    private const string VERIFIED_EMAIL = 'login.verified_email';

    public string $email = '';

    public string $code = '';

    public string $name = '';

    #[Locked]
    public string $step = 'email';

    public function mount(): void
    {
        $this->email = LoginCodeAutofill::email() ?? '';
    }

    public function sendCode(SendLoginCode $sendLoginCode, VerifyLoginCode $verifyLoginCode): void
    {
        $this->validate(
            ['email' => ['required', 'email', 'max:255']],
            attributes: ['email' => __('e-postadress')],
        );

        $email = SendLoginCode::normalise($this->email);

        if ($this->tooManyAttempts('send:'.request()->ip(), 10) || $this->tooManyAttempts('send:'.$email, 5)) {
            $this->addError('email', __('För många försök. Vänta en stund och försök igen.'));

            return;
        }

        $sendLoginCode($email, request()->ip());

        session()->put(self::PENDING_EMAIL, $email);
        $this->email = $email;
        $this->code = '';
        $this->step = 'code';

        $autofilledCode = LoginCodeAutofill::code($email);

        if ($autofilledCode !== null) {
            $this->code = $autofilledCode;
            $this->verify($verifyLoginCode);
        }
    }

    public function verify(VerifyLoginCode $verifyLoginCode): void
    {
        $email = session()->get(self::PENDING_EMAIL);

        if (! is_string($email)) {
            $this->startOver();

            return;
        }

        $this->validate(
            ['code' => ['required', 'regex:/^\s*\d{6}\s*$/']],
            ['code.regex' => __('Koden består av sex siffror.')],
            ['code' => __('kod')],
        );

        if ($this->tooManyAttempts('verify:'.request()->ip(), 20)) {
            $this->addError('code', __('För många försök. Vänta en stund och försök igen.'));

            return;
        }

        if (! $verifyLoginCode($email, $this->code)) {
            $this->addError('code', __('Koden stämmer inte eller har slutat gälla. Kontrollera mejlet eller skicka en ny kod.'));

            return;
        }

        session()->forget(self::PENDING_EMAIL);

        $user = User::where('email', $email)->first();

        if ($user instanceof User) {
            $this->signIn($user);

            return;
        }

        session()->put(self::VERIFIED_EMAIL, $email);
        $this->step = 'name';
    }

    public function createAccount(): void
    {
        $email = session()->get(self::VERIFIED_EMAIL);

        if (! is_string($email)) {
            $this->startOver();

            return;
        }

        $this->validate(
            ['name' => ['required', 'string', 'max:255']],
            attributes: ['name' => __('namn')],
        );

        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => trim($this->name), 'email_verified_at' => now()],
        );

        session()->forget(self::VERIFIED_EMAIL);

        $this->signIn($user, __('Ditt konto är skapat. Välkommen, :name!', ['name' => $user->name]));
    }

    public function startOver(): void
    {
        session()->forget([self::PENDING_EMAIL, self::VERIFIED_EMAIL]);

        $this->reset('code', 'name');
        $this->step = 'email';
    }

    public function render(): View
    {
        return view('livewire.auth.login-form', [
            'autofill' => LoginCodeAutofill::enabled(),
        ]);
    }

    private function signIn(User $user, ?string $message = null): void
    {
        Auth::login($user);

        session()->regenerate();

        Toast::flash($message ?? __('Du är inloggad. Välkommen tillbaka, :name!', ['name' => $user->name]));

        $this->redirectIntended(route('dashboard'));
    }

    private function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        $key = 'login-code:'.$key;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return true;
        }

        RateLimiter::hit($key, 600);

        return false;
    }
}
