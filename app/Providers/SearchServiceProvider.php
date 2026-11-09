<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Search\Providers\AccountsProvider;
use App\Support\Search\Providers\ActionsProvider;
use App\Support\Search\Providers\ArticlesProvider;
use App\Support\Search\Providers\CompaniesProvider;
use App\Support\Search\Providers\CustomersProvider;
use App\Support\Search\Providers\InvoicesProvider;
use App\Support\Search\Providers\NamedRecordsProvider;
use App\Support\Search\Providers\PagesProvider;
use App\Support\Search\Providers\SalesDocumentsProvider;
use App\Support\Search\Providers\SupplierInvoicesProvider;
use App\Support\Search\Providers\SuppliersProvider;
use App\Support\Search\Providers\VouchersProvider;
use App\Support\Search\SearchAction;
use App\Support\Search\SearchActions;
use App\Support\Search\SearchRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The spotlight search (ADR-026): the registry of providers and create
 * actions, and the core providers. Modules register their own providers
 * from their service providers with callAfterResolving(SearchRegistry).
 */
class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SearchRegistry::class);
        $this->app->singleton(SearchActions::class);

        $this->callAfterResolving(SearchRegistry::class, function (SearchRegistry $registry, Application $app): void {
            $registry->register($app->make(PagesProvider::class));
            $registry->register($app->make(ActionsProvider::class));
            $registry->register(new CompaniesProvider);
            $registry->register(new VouchersProvider);
            $registry->register(new InvoicesProvider);
            $registry->register(new SupplierInvoicesProvider);
            $registry->register(SalesDocumentsProvider::quotes());
            $registry->register(SalesDocumentsProvider::orders());
            $registry->register(new AccountsProvider);
            $registry->register(new CustomersProvider);
            $registry->register(new SuppliersProvider);
            $registry->register(new ArticlesProvider);
            $registry->register(NamedRecordsProvider::fixedAssets());
            $registry->register(NamedRecordsProvider::accruals());
            $registry->register(NamedRecordsProvider::projects());
            $registry->register(NamedRecordsProvider::costCenters());
        });

        $this->callAfterResolving(SearchActions::class, function (SearchActions $actions): void {
            $actions->register(...self::coreActions());
        });
    }

    public function boot(): void
    {
        // A keystroke is a request; generous, but bounded per user.
        RateLimiter::for('search', fn (Request $request): Limit => Limit::perMinute(300)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /** @return list<SearchAction> */
    private static function coreActions(): array
    {
        return [
            new SearchAction('voucher', 'Ny verifikation', 'accounting.vouchers.create', 'vouchers:create', keywords: ['bokför', 'verifikat', 'kontering', 'bokföra'], priority: 1),
            new SearchAction('invoice', 'Ny faktura', 'invoicing.invoices.create', 'invoices:create', keywords: ['kundfaktura', 'fakturera'], priority: 2),
            new SearchAction('supplier-invoice', 'Ny leverantörsfaktura', 'payables.invoices.create', 'supplier-invoices:create', keywords: ['registrera faktura', 'inköp'], priority: 3),
            new SearchAction('customer', 'Lägg till kund', 'invoicing.customers', 'invoices:create', query: ['ny' => 1], keywords: ['ny kund', 'kundregister'], priority: 4),
            new SearchAction('quote', 'Ny offert', 'invoicing.quotes.create', 'invoices:create', keywords: ['anbud'], priority: 6),
            new SearchAction('order', 'Ny order', 'invoicing.orders.create', 'invoices:create', keywords: ['beställning']),
            new SearchAction('agreement', 'Nytt avtal', 'invoicing.agreements.create', 'invoices:create', keywords: ['avtalsfakturering', 'återkommande faktura', 'abonnemang']),
            new SearchAction('supplier', 'Lägg till leverantör', 'payables.suppliers', 'supplier-invoices:create', query: ['ny' => 1], keywords: ['ny leverantör'], priority: 5),
            new SearchAction('article', 'Ny artikel', 'invoicing.articles', 'invoices:create', query: ['ny' => 1], keywords: ['lägg till artikel', 'produkt', 'tjänst']),
            new SearchAction('sie-import', 'Läs in SIE-fil', 'accounting.sieImports.index', 'import:sie', keywords: ['importera', 'sie4', 'flytta bokföring'], icon: 'upload'),
            new SearchAction('register-import', 'Läs in register', 'import.registers.index', 'import:registers', keywords: ['importera', 'kunder', 'leverantörer', 'artiklar', 'obetalda fakturor', 'underlag', 'flytta register'], icon: 'upload'),
            new SearchAction('company', 'Lägg till ett företag', 'teams.create', keywords: ['nytt företag', 'skapa företag'], icon: 'building'),
            new SearchAction('member', 'Bjud in en användare', 'teams.show', 'members:manage', parameters: ['company' => '{company}'], keywords: ['användare', 'redovisningskonsult', 'revisor', 'behörighet'], icon: 'person'),
        ];
    }
}
