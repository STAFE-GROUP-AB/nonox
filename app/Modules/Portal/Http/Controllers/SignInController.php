<?php

declare(strict_types=1);

namespace App\Modules\Portal\Http\Controllers;

use App\Actions\Auth\VerifyLoginCode;
use App\Domain\Signing\SigningToken;
use App\Models\Company;
use App\Models\LoginCode;
use App\Modules\ModuleManager;
use App\Modules\Portal\Domain\ContactStatus;
use App\Modules\Portal\Jobs\SendPortalLoginCode;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Models\PortalSettings;
use App\Modules\Portal\PortalModule;
use App\Modules\Portal\Support\PortalAccess;
use App\Modules\Portal\Support\PortalSession;
use App\Support\Audit\Audit;
use App\Support\Auth\ClientAddressBucket;
use App\Support\Branding\Brand;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing in to the customer portal (ADR-075 §2, §3, §8), on its own guard
 * and cookie, without Livewire (the pages allow no inline script).
 *
 * /kundportal asks for an address and always answers the same, at once:
 * "Om adressen har tillgång till en kundportal har vi skickat en kod." The
 * job that decides whether a code goes out runs in the queue, so neither the
 * answer nor its timing tells anything. There is no self-registration.
 *
 * /kundportal/inbjudan/{token}: the invitation link alone signs nobody in;
 * a code goes to the invited address, and only the code accepts it.
 */
final class SignInController
{
    public function __construct(private readonly PortalAccess $access) {}

    public function show(Request $request): View|RedirectResponse
    {
        if (Auth::guard('portal')->user() instanceof PortalIdentity) {
            return redirect()->route('portal.choose');
        }

        return view('portal::portal.sign-in', ['brand' => $this->brand($request), 'step' => 'email']);
    }

    public function send(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']], [], ['email' => __('e-postadress')]);
        $email = PortalIdentity::normalise((string) $request->input('email'));

        // Only the requesting address is limited here; the caps per e-mail address live in SendLoginCode and never refuse.
        if ($this->tooMany('send:'.ClientAddressBucket::for($request->ip()), 10)) {
            return back()->withErrors(['email' => __('För många försök. Vänta en stund och försök igen.')]);
        }

        $brand = $this->brand($request);
        SendPortalLoginCode::dispatch($email, $request->ip(), $brand?->company_id);

        $request->session()->put(PortalSession::PENDING_EMAIL, $email);

        return redirect()->route('portal.code')->with('status', __('Om adressen har tillgång till en kundportal har vi skickat en kod.'));
    }

    public function code(Request $request): View|RedirectResponse
    {
        if (! is_string($request->session()->get(PortalSession::PENDING_EMAIL))) {
            return redirect()->route('portal.login');
        }

        return view('portal::portal.sign-in', ['brand' => $this->brand($request), 'step' => 'code', 'email' => $request->session()->get(PortalSession::PENDING_EMAIL)]);
    }

    public function verify(Request $request, VerifyLoginCode $verify): RedirectResponse
    {
        $email = $request->session()->get(PortalSession::PENDING_EMAIL);

        if (! is_string($email)) {
            return redirect()->route('portal.login');
        }

        $this->validateCode($request);

        if ($this->tooMany('verify:'.ClientAddressBucket::for($request->ip()), 20) || ! $verify($email, (string) $request->input('code'), LoginCode::PORTAL)) {
            return back()->withErrors(['code' => __('Koden stämmer inte eller har slutat gälla. Kontrollera mejlet eller be om en ny kod.')]);
        }

        $identity = PortalIdentity::query()->where('email', $email)->first();

        if (! $identity instanceof PortalIdentity || $this->access->contactsFor($identity)->isEmpty()) {
            $request->session()->forget(PortalSession::PENDING_EMAIL);

            return redirect()->route('portal.login')->withErrors(['email' => __('Koden stämmer inte eller har slutat gälla. Kontrollera mejlet eller be om en ny kod.')]);
        }

        PortalSession::start($request, $identity);

        return redirect()->route('portal.choose');
    }

    public function invitation(Request $request, string $token): Response
    {
        $invitation = $this->findInvitation($token);

        if (! $invitation instanceof PortalInvitation) {
            return response()->view('portal::portal.closed', ['message' => __('Inbjudan gäller inte längre. Be företaget om en ny.')], 404);
        }

        $request->session()->put(PortalSession::INVITATION, $invitation->token_sha256);
        $company = Company::query()->findOrFail($invitation->company_id);

        return response()->view('portal::portal.invitation', [
            'brand' => CurrentCompany::run($company, fn (): PortalSettings => PortalSettings::current()),
            'company' => $company,
            'token' => $token,
            'email' => $this->maskedEmail($invitation),
            'sent' => (bool) $request->session()->get('portal.invitation_sent'),
        ]);
    }

    public function sendInvitationCode(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->findInvitation($token);

        if (! $invitation instanceof PortalInvitation) {
            return redirect()->route('portal.invitation', ['token' => $token]);
        }

        $identity = $this->identityOf($invitation);
        SendPortalLoginCode::dispatch($identity->email, $request->ip(), null, $invitation->company_id);
        $request->session()->flash('portal.invitation_sent', true);

        return redirect()->route('portal.invitation', ['token' => $token])->with('status', __('Vi har skickat en kod till din e-postadress.'));
    }

    public function acceptInvitation(Request $request, string $token, VerifyLoginCode $verify): RedirectResponse
    {
        $invitation = $this->findInvitation($token);

        if (! $invitation instanceof PortalInvitation || ! hash_equals((string) $request->session()->get(PortalSession::INVITATION), $invitation->token_sha256)) {
            return redirect()->route('portal.invitation', ['token' => $token]);
        }

        $this->validateCode($request);
        $identity = $this->identityOf($invitation);

        if ($this->tooMany('verify:'.ClientAddressBucket::for($request->ip()), 20) || ! $verify($identity->email, (string) $request->input('code'), LoginCode::PORTAL)) {
            $request->session()->flash('portal.invitation_sent', true);

            return back()->withErrors(['code' => __('Koden stämmer inte eller har slutat gälla. Kontrollera mejlet eller be om en ny kod.')]);
        }

        $contactId = CurrentCompany::run($invitation->company_id, fn (): int => DB::transaction(function () use ($invitation): int {
            $locked = PortalInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            $contact = PortalContact::query()->whereKey($locked->contact_id)->lockForUpdate()->firstOrFail();

            abort_unless($locked->isUsable() && $contact->status === ContactStatus::Invited, 410);

            $locked->forceFill(['used_at' => now()])->save();
            $contact->forceFill(['status' => ContactStatus::Active, 'accepted_at' => now(), 'last_seen_at' => now()])->save();

            Audit::record('customer_portal.contact_accepted', $contact, ['contact_id' => $contact->id, 'customer_id' => $contact->customer_id], $contact->company_id);

            return $contact->id;
        }));

        PortalSession::start($request, $identity, $contactId);

        return redirect()->route('portal.home', ['portalKey' => CurrentCompany::run($invitation->company_id, fn (): string => PortalSettings::current()->portal_key)]);
    }

    public function logout(Request $request): RedirectResponse
    {
        PortalSession::end($request);

        return redirect()->route('portal.login')->with('status', __('Du är utloggad.'));
    }

    /** The usable invitation behind a token, in any company, or null. */
    private function findInvitation(string $token): ?PortalInvitation
    {
        if (! SigningToken::isWellFormed($token)) {
            return null;
        }

        $hash = SigningToken::hash($token);
        // The token is the only key on this page: looked up across companies by its hash alone.
        $invitation = CompanyScope::bypass(fn (): ?PortalInvitation => PortalInvitation::query()->where('token_sha256', $hash)->first());

        if (! $invitation instanceof PortalInvitation || ! hash_equals($invitation->token_sha256, $hash) || ! $invitation->isUsable()) {
            return null;
        }

        $company = Company::query()->find($invitation->company_id);
        $contact = CompanyScope::bypass(fn (): ?PortalContact => PortalContact::query()->find($invitation->contact_id));

        if (! $company instanceof Company || $company->isArchived() || ! app(ModuleManager::class)->isEnabled(PortalModule::KEY, $company)
            || ! $contact instanceof PortalContact || $contact->status !== ContactStatus::Invited) {
            return null;
        }

        return $invitation;
    }

    private function identityOf(PortalInvitation $invitation): PortalIdentity
    {
        $contact = CompanyScope::bypass(fn (): PortalContact => PortalContact::query()->findOrFail($invitation->contact_id));

        return PortalIdentity::query()->findOrFail($contact->portal_identity_id);
    }

    /** "an***@kontorshuset.test": enough to recognise one's own address, not to learn it. */
    private function maskedEmail(PortalInvitation $invitation): string
    {
        $email = $this->identityOf($invitation)->email;
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).'***@'.$domain;
    }

    /** The company whose portal address the visitor came from, for its brand; null for the neutral page. */
    private function brand(Request $request): ?PortalSettings
    {
        $key = $request->session()->get(PortalSession::BRAND_KEY);

        return is_string($key) ? $this->access->openSettingsFor($key) : null;
    }

    private function validateCode(Request $request): void
    {
        $length = (int) config('auth.login_codes.length');
        $request->validate(['code' => ['required', 'regex:/^\s*(\d\s*){'.$length.'}$/']], ['code.regex' => __('Koden består av :count siffror.', ['count' => $length])], ['code' => __('kod')]);
    }

    private function tooMany(string $key, int $max): bool
    {
        $key = 'portal-code:'.$key;

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return true;
        }

        RateLimiter::hit($key, 600);

        return false;
    }
}
