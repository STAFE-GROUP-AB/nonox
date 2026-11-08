<?php

declare(strict_types=1);

namespace App\Modules\Portal\Http\Controllers;

use App\Models\Company;
use App\Modules\Portal\Actions\PortalSelfService;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalSettings;
use App\Modules\Portal\Support\PortalAccess;
use App\Modules\Portal\Support\PortalSession;
use App\Support\Tenancy\CompanyScope;
use App\Support\Toast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The signed-in person's own pages (ADR-075 §9, §12): choosing a portal
 * when there are several, "Mina uppgifter" (name, the portals, a JSON
 * extract, "Ta bort min inloggning"), and the company's privacy notice for
 * its portal, which the invitation links to before anyone signs in.
 */
final class AccountController
{
    public function __construct(private readonly PortalAccess $access) {}

    public function choose(Request $request): View|RedirectResponse
    {
        $portals = $this->portals();
        $only = $portals->count() === 1 ? $portals->first() : null;

        if ($only !== null) {
            $request->session()->put(PortalSession::CONTACT, $only['contact']->id);

            return redirect()->route('portal.home', ['portalKey' => $only['key']]);
        }

        return view('portal::portal.choose', ['portals' => $portals]);
    }

    public function enter(Request $request): RedirectResponse
    {
        $portal = $this->portals()->first(fn (array $portal): bool => $portal['contact']->id === (int) $request->input('contact'));
        abort_unless($portal !== null, 404);

        $request->session()->put(PortalSession::CONTACT, $portal['contact']->id);

        return redirect()->route('portal.home', ['portalKey' => $portal['key']]);
    }

    public function mine(): View
    {
        return view('portal::portal.mine', ['identity' => $this->identity(), 'portals' => $this->portals()]);
    }

    public function rename(Request $request, PortalSelfService $self): RedirectResponse
    {
        $self->rename($this->identity(), (string) $request->input('name'));
        Toast::flash(__('Ditt namn är sparat.'));

        return redirect()->route('portal.mine');
    }

    public function export(PortalSelfService $self): JsonResponse
    {
        return response()->json($self->export($this->identity()), 200, [
            'Content-Disposition' => 'attachment; filename="mina-uppgifter-kundportal.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function remove(Request $request, PortalSelfService $self): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => __('Bekräfta att du vill ta bort din inloggning.')]);
        $only = $request->filled('contact') ? (int) $request->input('contact') : null;
        $identity = $this->identity();

        abort_if($only !== null && ! $this->portals()->contains(fn (array $portal): bool => $portal['contact']->id === $only), 404);

        $self->removeLogin($identity, $only);

        if (! PortalIdentity::query()->whereKey($identity->id)->exists() || $this->access->contactsFor($identity)->isEmpty()) {
            PortalSession::end($request);

            return redirect()->route('portal.login')->with('status', __('Din inloggning är borttagen.'));
        }

        Toast::flash(__('Din inloggning till kundportalen är borttagen.'));

        return redirect()->route('portal.mine');
    }

    /** The company's privacy notice for its portal; reachable without signing in. */
    public function privacy(string $portalKey): View
    {
        $settings = $this->access->settingsFor($portalKey);
        abort_unless($settings instanceof PortalSettings, 404);
        $company = Company::query()->findOrFail($settings->company_id);

        return view('portal::portal.privacy', ['settings' => $settings, 'company' => $company]);
    }

    /** @return Collection<int, array{contact: PortalContact, key: string, company: Company}> */
    private function portals(): Collection
    {
        $contacts = $this->access->contactsFor($this->identity());
        // The keys of the identity's own companies only.
        $keys = CompanyScope::bypass(fn (): Collection => PortalSettings::query()->whereIn('company_id', $contacts->pluck('company_id'))->pluck('portal_key', 'company_id'));

        $portals = [];

        foreach ($contacts as $contact) {
            if (isset($keys[$contact->company_id]) && $contact->company instanceof Company) {
                $portals[] = ['contact' => $contact, 'key' => (string) $keys[$contact->company_id], 'company' => $contact->company];
            }
        }

        return collect($portals);
    }

    private function identity(): PortalIdentity
    {
        $identity = Auth::guard('portal')->user();
        abort_unless($identity instanceof PortalIdentity, 403);

        return $identity;
    }
}
