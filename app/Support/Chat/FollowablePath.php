<?php

declare(strict_types=1);

namespace App\Support\Chat;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\ViewController;
use Livewire\Component;
use Throwable;

/**
 * Whether the encrypted chat's "Följ mina sidor" may open a path in the
 * follower's browser (ADR-045). An allowlist: the path must resolve, as a
 * GET request, to a route that only renders a page: a Route::view, a
 * Livewire full-page component, or one of the controller actions and
 * closures below that have been read and render a view without changing
 * anything. Everything that downloads a file, completes a callback, exports
 * personal data or is read under audit is denied, also when it would
 * otherwise match. Resolving a path writes nothing and logs nothing.
 */
final class FollowablePath
{
    /**
     * Controller actions and closures that render a page and change nothing,
     * by route name. Checked by hand: add a route here only after reading it.
     */
    public const array SAFE_ROUTES = [
        'dashboard',
        'onboarding',
        'profile.show',
        'teams.create',
        'teams.show',
        'tasks.show',
        'accounting.vouchers.index',
        'accounting.vouchers.create',
        'accounting.vouchers.show',
        'accounting.vouchers.edit',
        'accounting.voucherSeries',
        'accounting.annualReport.show',
        'accounting.sieImports.index',
        'accounting.sieImports.show',
        'invoicing.invoices.show',
        'invoicing.invoices.edit',
        'invoicing.agreements.show',
        'invoicing.quotes.show',
        'invoicing.orders.show',
        'payables.invoices.show',
        'payables.invoices.edit',
        'payroll.runs.show',
        // Closures in app/Modules/Crm/routes/web.php that return view('crm::…').
        'crm.accounts.show',
        'crm.people.show',
        'crm.deals.show',
    ];

    /** Route names never followed, even when they render a page: audited reads, sign-in steps, callbacks. */
    private const array DENIED_NAMES = [
        '/^hq\./',
        '/^privacy\.export\./',
        '/^staff-ledger\.control-visit/',
        '/^staff-ledger\.kiosk/',
        '/^manufacturing\.terminal\./',
        '/^manufacturing\.terminal$/',
        // Opening the absence requests is a logged read of absence causes, which can be health data.
        '/^payroll\.requests$/',
        '/^(login|logout|register|passkeys\.challenge)$/',
        '/\.(callback|file|pdf|csv|download|sru|sie|archive|receipt|attachment|original|payment-file|certificate)$/',
    ];

    /** Paths never followed: files, downloads, callbacks, exports of personal data, invitations, kiosks. */
    private const array DENIED_PATHS = [
        '#\.[a-z0-9]{2,5}$#i',
        '#/(fil|pdf|csv|sru|sie-fil|lonefil|faktura|kvitto|original|foto|qr|qr-kort|logotyp|byrans-logotyp|provsida|arbetsgivarintyg|klar|data|ikon|manifest|serviceworker)$#i',
        '#/(bilagor|underlag|arkivpaket|betalfiler)/#i',
        '#^/(registerutdrag|foretagsinbjudningar|anslut|oauth|api|livewire|chatt|hq|logga-ut|logga-in|aktuellt-foretag|varumarke|sanctum|mina-uppgifter)(/|$)#i',
        '#^/(bank|flytt/fortnox)/#i',
        '#^/profil/mina-uppgifter(/|$)#i',
        '#/kiosk(/|$)#i',
        '#^/verkstad/terminal(/|$)#i',
    ];

    public function __construct(private readonly Router $router) {}

    public function allows(mixed $path): bool
    {
        if (! is_string($path) || $path === '' || strlen($path) > 1000) {
            return false;
        }

        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\\\\\s\x00-\x1f\x7f]/', $path) === 1) {
            return false;
        }

        $pathOnly = (string) parse_url($path, PHP_URL_PATH);

        if ($pathOnly === '' || $this->deniedPath(rawurldecode($pathOnly))) {
            return false;
        }

        try {
            $route = $this->router->getRoutes()->match(Request::create($path, 'GET'));
        } catch (Throwable) {
            return false;
        }

        return $this->rendersPage($route);
    }

    private function rendersPage(Route $route): bool
    {
        $name = (string) $route->getName();

        foreach (self::DENIED_NAMES as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return false;
            }
        }

        if (in_array($name, self::SAFE_ROUTES, true)) {
            return true;
        }

        $uses = $route->getAction('uses');

        if (! is_string($uses)) {
            return false;
        }

        $class = ltrim(explode('@', $uses)[0], '\\');

        return $class === ViewController::class || is_subclass_of($class, Component::class);
    }

    private function deniedPath(string $path): bool
    {
        foreach (self::DENIED_PATHS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
