<?php

declare(strict_types=1);

namespace App\Modules\Portal\Http\Middleware;

use App\Models\Company;
use App\Models\Customer;
use App\Modules\Portal\Actions\RecordPortalEvent;
use App\Modules\Portal\Domain\EventType;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalSettings;
use App\Modules\Portal\Support\PortalAccess;
use App\Modules\Portal\Support\PortalContext;
use App\Modules\Portal\Support\PortalSession;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the portal context for /kundportal/{portalnyckel}/… (ADR-075 §8):
 * the company comes from the portal key, the customer from the contact the
 * session chose, and both must belong to the signed-in identity. The
 * contact must be ACTIVE, its customer active, the company not archived and
 * the module on; otherwise the session ends and the page answers as for an
 * unknown portal (404). An identity with several contacts in the company
 * chooses one first. The key leaves the route parameters, so no controller
 * ever sees a company or a customer id from the request.
 */
final class ResolvePortal
{
    public function __construct(
        private readonly PortalAccess $access,
        private readonly PortalContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $identity = Auth::guard('portal')->user();
        $settings = $this->access->settingsFor((string) $request->route('portalKey'));

        if (! $identity instanceof PortalIdentity || ! $settings instanceof PortalSettings) {
            return $this->unknown($request, $identity);
        }

        $contacts = $this->access->contactsFor($identity)->where('company_id', $settings->company_id)->values();
        $chosen = $request->session()->get(PortalSession::CONTACT);
        $contact = $contacts->firstWhere('id', $chosen) ?? ($contacts->count() === 1 ? $contacts->first() : null);

        if ($contacts->isEmpty()) {
            return $this->unknown($request, $identity);
        }

        if (! $contact instanceof PortalContact) {
            return redirect()->route('portal.choose');
        }

        $company = $contact->company;
        $customer = $contact->customer;

        if (! $company instanceof Company || ! $customer instanceof Customer) {
            return $this->unknown($request, $identity);
        }

        $request->session()->put(PortalSession::CONTACT, $contact->id);
        CurrentCompany::set($company);
        $this->context->set($identity, $contact, $company, $customer, $settings);
        $request->route()?->forgetParameter('portalKey');

        // The first entry into this portal in this session is the sign-in event, the only one with IP address and browser.
        $entered = 'portal.entered.'.$contact->id;

        if (! $request->session()->get($entered)) {
            $request->session()->put($entered, true);
            app(RecordPortalEvent::class)(EventType::SignedIn, request: $request);
        }

        if ($contact->last_seen_at === null || $contact->last_seen_at->lessThan(now()->subMinutes(5))) {
            PortalContact::query()->whereKey($contact->id)->update(['last_seen_at' => now(), 'inactivity_notified_at' => null]);
        }

        return $next($request);
    }

    private function unknown(Request $request, ?PortalIdentity $identity): Response
    {
        if ($identity instanceof PortalIdentity && $this->access->contactsFor($identity)->isEmpty()) {
            PortalSession::end($request);
        }

        abort(404);
    }
}
