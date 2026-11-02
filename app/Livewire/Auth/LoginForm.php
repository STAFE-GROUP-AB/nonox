<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Auth\SendLoginCode;
use App\Actions\Auth\VerifyLoginCode;
use App\Domain\Auth\PasskeyRequirement;
use App\Livewire\Auth\Concerns\HandlesPasskeyStep;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Auth\PasskeySession;
use App\Support\Branding\InvitationBrand;
use App\Support\LoginCodeAutofill;
use App\Support\Tenancy\SpansCompanies;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Passwordless sign-in (ADR-000): email → one-time code → signed in. Only
 * existing users and people with a valid invitation to a company get a code
 * (ADR-038): there is no self-registration, new users come through the
 * waiting list. Any other address sees exactly the same next step, so the
 * form does not tell whether an address has an account. An invited address
 * without an account adds a name step, and not even that when the invitation
 * carries the name. The verified address is kept in the session, never
 * trusted from the browser.
 *
 * A user who has passkeys, or who must have one (PasskeyRequirement), passes
 * a passkey step after the code and before the session starts (ADR-029).
 */
class LoginForm extends Component implements SpansCompanies
{
    use HandlesPasskeyStep;

    private const string PENDING_EMAIL = 'login.pending_email';

    private const string VERIFIED_EMAIL = 'login.verified_email';

    /** The user who has passed the code and waits for the passkey step, with a deadline. */
    private const string PASSKEY_USER = 'login.passkey_user';

    private const int PASSKEY_STEP_MINUTES = 10;

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

        if ($this->tooManyAttempts('send:'.request()->ip(), 10) || $this->tooManyAttempts('send:'.$email, 5) || self::tooManyToday($email)) {
            $this->addError('email', __('För många försök. Vänta en stund och försök igen.'));

            return;
        }

        $mayReceiveCode = self::mayReceiveCode($email);

        // An unknown address gets no code and no account, but the same next step as everyone else.
        if ($mayReceiveCode) {
            $sendLoginCode($email, request()->ip(), InvitationBrand::forLoginCode($email));
        }

        session()->put(self::PENDING_EMAIL, $email);
        $this->email = $email;
        $this->code = '';
        $this->step = 'code';

        $autofilledCode = $mayReceiveCode ? LoginCodeAutofill::code($email) : null;

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
            ['code' => ['required', 'regex:/^\s*(\d\s*){'.config('auth.login_codes.length').'}$/']],
            ['code.regex' => __('Koden består av :count siffror.', ['count' => config('auth.login_codes.length')])],
            ['code' => __('kod')],
        );

        /*
         * Wrong codes stop a session, not the address: counted per address
         * across sessions, someone else could lock the owner out. Guessing
         * across sessions is bounded by the code itself (eight digits, five
         * tries, a few codes per hour and day).
         */
        if (RateLimiter::tooManyAttempts(self::failedKey($email), self::MAX_FAILED_PER_HOUR)) {
            $this->addError('code', __('För många felaktiga koder för den här adressen. Vänta en timme och försök igen.'));

            return;
        }

        if ($this->tooManyAttempts('verify:'.request()->ip(), 20)) {
            $this->addError('code', __('För många försök. Vänta en stund och försök igen.'));

            return;
        }

        if (! $verifyLoginCode($email, $this->code)) {
            RateLimiter::hit(self::failedKey($email), 3600);
            $this->addError('code', __('Koden stämmer inte eller har slutat gälla. Kontrollera mejlet eller skicka en ny kod.'));

            return;
        }

        session()->forget(self::PENDING_EMAIL);

        $user = User::query()->byEmail($email)->orderBy('id')->first();

        if ($user instanceof User) {
            $this->signInOrAskForPasskey($user);

            return;
        }

        if (! self::isInvited($email)) {
            $this->startOver();

            return;
        }

        $invitedName = TeamInvitation::query()->stillValid()->whereRaw('lower(email) = ?', [$email])->whereNotNull('name')->latest()->value('name');

        if (is_string($invitedName) && trim($invitedName) !== '') {
            $this->signInOrAskForPasskey($this->createUser($email, $invitedName), __('Välkommen, :name!', ['name' => $invitedName]));

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

        // Only an invitation lets a new address in; it may have been withdrawn meanwhile.
        if (! self::isInvited($email)) {
            $this->startOver();

            return;
        }

        $this->validate(
            ['name' => ['required', 'string', 'max:255']],
            attributes: ['name' => __('namn')],
        );

        $user = $this->createUser($email, $this->name);

        session()->forget(self::VERIFIED_EMAIL);

        $this->signInOrAskForPasskey($user, __('Ditt konto är skapat. Välkommen, :name!', ['name' => $user->name]));
    }

    public function startOver(): void
    {
        session()->forget([self::PENDING_EMAIL, self::VERIFIED_EMAIL, self::PASSKEY_USER]);

        $this->reset('code', 'name', 'passkeyName');
        $this->step = 'email';
    }

    public function render(): View
    {
        return view('livewire.auth.login-form', [
            'autofill' => LoginCodeAutofill::enabled(),
            // Reached from an invitation to a client of an accounting firm: the firm's brand (ADR-030).
            'firmBrand' => InvitationBrand::forLoginPage(),
        ]);
    }

    /**
     * Who gets a code: an existing user (also one approved from the waiting
     * list, ADR-038), or someone with a valid invitation to a company.
     */
    public static function mayReceiveCode(string $email): bool
    {
        return User::query()->byEmail($email)->whereNull('anonymised_at')->exists() || self::isInvited($email);
    }

    private static function isInvited(string $email): bool
    {
        return TeamInvitation::query()->stillValid()->whereRaw('lower(email) = ?', [User::normaliseEmail($email)])->exists();
    }

    private function createUser(string $email, string $name): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            ['name' => trim($name), 'email_verified_at' => now()],
        );
    }

    /**
     * The code is right. Without a passkey to ask for the user is signed in;
     * otherwise the session waits for the passkey step and the user is only
     * remembered in it, not signed in.
     */
    private function signInOrAskForPasskey(User $user, ?string $message = null): void
    {
        if (PasskeySession::skippedLocally() || ! PasskeyRequirement::mustVerify($user)) {
            $this->signIn($user, $message);

            return;
        }

        session()->put(self::PASSKEY_USER, ['id' => $user->id, 'expires_at' => time() + self::PASSKEY_STEP_MINUTES * 60]);

        $this->step = $user->hasPasskeysEnabled() ? 'passkey' : 'passkey-register';
    }

    protected function passkeyUser(): ?User
    {
        $pending = session()->get(self::PASSKEY_USER);

        if (! is_array($pending) || ! is_int($pending['id'] ?? null) || (int) ($pending['expires_at'] ?? 0) < time()) {
            return null;
        }

        return User::query()->whereNull('anonymised_at')->find($pending['id']);
    }

    protected function passkeyPassed(User $user): void
    {
        session()->forget(self::PASSKEY_USER);

        $this->signIn($user, passkey: true);
    }

    private function signIn(User $user, ?string $message = null, bool $passkey = false): void
    {
        // A user approved from the waiting list confirms the address with the first code.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::login($user);

        session()->regenerate();

        if ($passkey) {
            PasskeySession::markVerified();
        }

        Toast::flash($message ?? __('Du är inloggad. Välkommen tillbaka, :name!', ['name' => $user->name]));

        $this->redirectIntended(route('dashboard'));
    }

    public const int MAX_FAILED_PER_HOUR = 10;

    public static function failedKey(string $email): string
    {
        return 'login-code:failed:'.mb_strtolower(trim($email)).':'.session()->getId();
    }

    /** Codes per address and day, and for the whole service per hour, so the form cannot be used to flood inboxes. */
    public const int MAX_SENDS_PER_DAY = 20;

    public const int MAX_SENDS_PER_HOUR_GLOBAL = 500;

    private static function tooManyToday(string $email): bool
    {
        if (RateLimiter::tooManyAttempts('login-code:global', self::MAX_SENDS_PER_HOUR_GLOBAL)) {
            Log::warning('Login: the hourly cap on login code mails was reached.');

            return true;
        }

        if (RateLimiter::tooManyAttempts('login-code:day:'.$email, self::MAX_SENDS_PER_DAY)) {
            return true;
        }

        RateLimiter::hit('login-code:global', 3600);
        RateLimiter::hit('login-code:day:'.$email, 86400);

        return false;
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
