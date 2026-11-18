<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Livewire\Accounting\Reports;
use App\Livewire\Accounting\VoucherActions;
use App\Livewire\Accounting\VoucherAttachments;
use App\Livewire\Accounting\VoucherEditor;
use App\Livewire\Accounting\VoucherList;
use App\Livewire\Auth\PasskeyChallenge;
use App\Livewire\Company\CompanyDetailsForm;
use App\Livewire\Company\CreateCompanyForm;
use App\Livewire\Company\DimensionScopes;
use App\Livewire\Company\MemberManager;
use App\Livewire\Company\PasskeyPolicy;
use App\Livewire\Payables\SupplierInvoiceActions;
use App\Livewire\Payables\SupplierInvoiceEditor;
use App\Livewire\Payables\SupplierInvoiceList;
use App\Livewire\Profile\DeleteAccount;
use App\Livewire\Profile\Passkeys;
use App\Livewire\Profile\ProfileInformationForm;
use App\Livewire\Profile\SignOutEverywhere;
use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Http\Middleware\AuthenticateConnect;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Http\Livewire\NavigationMenu;

/**
 * The scope decision of every route and Livewire component a signed-in member
 * can reach (ADR-081 gap 10). For a member limited to cost centres or
 * projects, only the routes and components named SCOPED or NEUTRAL answer;
 * everything else answers 404 (EnforceDimensionScope,
 * LivewireDimensionScopeGuard). Unlimited members are not affected.
 *
 * Scoped and neutral entries are exact names, so a new page is never opened
 * by accident. Refusals may name a whole area. DimensionScopeRegistryTest
 * fails a signed-in route that has no decision here, the way StoredFilesTest
 * fails a path column that is missing.
 */
final class DimensionScopeRegistry
{
    /** Pages that apply DimensionScope to everything they show and save. */
    public const array SCOPED_ROUTES = [
        'accounting.reports',
        'accounting.reports.csv',
        'accounting.vouchers.index',
        'accounting.vouchers.create',
        'accounting.vouchers.show',
        'accounting.vouchers.edit',
        'accounting.vouchers.attachment',
        'payables.invoices.index',
        'payables.invoices.create',
        'payables.invoices.show',
        'payables.invoices.edit',
        'payables.invoices.file',
    ];

    /** Pages that hold nothing of the company's books. */
    public const array NEUTRAL_ROUTES = [
        'profile.show',
        'logout',
        'current-team.update',
        'teams.show',
        'teams.create',
        'teams.logo',
        'teams.firm-logo',
        'team-invitations.accept',
        'team-invitations.accept-from-onboarding',
        'onboarding',
        'passkeys.challenge',
        'privacy.export.me',
        // The encrypted chat belongs to people, not to a company's books (ADR-045).
        'chat.companies', 'chat.end', 'chat.followable', 'chat.join', 'chat.poll', 'chat.send', 'chat.start',
        // Unnamed: the old address of the task list, a redirect.
        'uri:mina-uppgifter',
    ];

    /** The instance's own area (ADR-038) belongs to no company; HQ access is its own gate. */
    public const array NEUTRAL_PATTERNS = ['hq.*'];

    public const array LANDING_ROUTES = ['dashboard'];

    /** Areas a limited member never reaches, whatever new pages they get. */
    public const array REFUSED_PATTERNS = [
        'accounting.*', 'agreements.*', 'approvals.*', 'budgets.*', 'documents.*', 'projects.*', 'banking.*', 'boardroom.*', 'companies.*', 'connect.*', 'crm.*',
        'dashboard.*', 'fortnox.*', 'group.*', 'guide.*', 'import.*', 'inbox.*', 'inventory.*', 'invoicing.*',
        'kitchen.*', 'manufacturing.*', 'mileage.*', 'notifications.*', 'passport.*', 'payables.*', 'payroll.*',
        'planning.*', 'portal.*', 'pos.*', 'privacy.*', 'saas.*', 'search', 'search.*', 'share-register.*',
        'signing.*', 'skatteverket.*', 'staff-ledger.*', 'tasks.*', 'vat.*', 'workOrders.*',
    ];

    /** Livewire components that apply DimensionScope, or hold nothing of the books. */
    public const array COMPONENTS = [
        Reports::class => ScopeDecision::Scoped,
        VoucherList::class => ScopeDecision::Scoped,
        VoucherEditor::class => ScopeDecision::Scoped,
        VoucherActions::class => ScopeDecision::Scoped,
        VoucherAttachments::class => ScopeDecision::Scoped,
        SupplierInvoiceList::class => ScopeDecision::Scoped,
        SupplierInvoiceEditor::class => ScopeDecision::Scoped,
        SupplierInvoiceActions::class => ScopeDecision::Scoped,
        NavigationMenu::class => ScopeDecision::Neutral,
        // The passkey step of signing in (ADR-029): a limited member must be able to pass it too.
        PasskeyChallenge::class => ScopeDecision::Neutral,
        CompanyDetailsForm::class => ScopeDecision::Neutral,
        PasskeyPolicy::class => ScopeDecision::Neutral,
        MemberManager::class => ScopeDecision::Neutral,
        DimensionScopes::class => ScopeDecision::Neutral,
        Passkeys::class => ScopeDecision::Neutral,
        SignOutEverywhere::class => ScopeDecision::Neutral,
        DeleteAccount::class => ScopeDecision::Neutral,
        ProfileInformationForm::class => ScopeDecision::Neutral,
        CreateCompanyForm::class => ScopeDecision::Neutral,
    ];

    /**
     * Whether the route is for a signed-in member: the web session or an API
     * credential. Customer portal, kiosk, workshop and wall-screen devices have
     * their own sign-in and act for no member.
     */
    public static function requiresMember(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if ($middleware === 'auth' || str_starts_with($middleware, 'auth:') || str_starts_with($middleware, Authenticate::class) || $middleware === AuthenticateConnect::class) {
                return true;
            }
        }

        return false;
    }

    /** The registry key of a route: its name, or "uri:<path>" when it has none. */
    public static function key(Route $route): string
    {
        return $route->getName() ?? 'uri:'.$route->uri();
    }

    /** The decision for a route name ("uri:<path>" for an unnamed route); null when none is registered. */
    public static function route(string $name): ?ScopeDecision
    {
        return match (true) {
            in_array($name, self::SCOPED_ROUTES, true) => ScopeDecision::Scoped,
            in_array($name, self::NEUTRAL_ROUTES, true) => ScopeDecision::Neutral,
            in_array($name, self::LANDING_ROUTES, true) => ScopeDecision::Landing,
            Str::is(self::NEUTRAL_PATTERNS, $name) => ScopeDecision::Neutral,
            Str::is(self::REFUSED_PATTERNS, $name) => ScopeDecision::Refused,
            default => null,
        };
    }

    /** Whether a limited member may open the route at all. */
    public static function opens(string $name): bool
    {
        return in_array(self::route($name), [ScopeDecision::Scoped, ScopeDecision::Neutral, ScopeDecision::Landing], true);
    }

    public static function component(object|string $component): ScopeDecision
    {
        return self::COMPONENTS[is_string($component) ? $component : $component::class] ?? ScopeDecision::Refused;
    }

    /**
     * Where the start page sends a limited member: the first scoped page their
     * permissions open, else their profile.
     */
    public static function landing(User $user, Company $company): string
    {
        foreach ([
            'reports:view' => 'accounting.reports',
            'vouchers:view' => 'accounting.vouchers.index',
            'supplier-invoices:approve' => 'payables.invoices.index',
            'supplier-invoices:create' => 'payables.invoices.index',
        ] as $permission => $route) {
            if ($user->hasTeamPermission($company, $permission)) {
                return route($route);
            }
        }

        return route('profile.show');
    }
}
