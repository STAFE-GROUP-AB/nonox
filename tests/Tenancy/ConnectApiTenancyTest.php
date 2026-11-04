<?php

declare(strict_types=1);

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\SaveDimensionObject;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\PaySupplierInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Accounting\Dimension;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\SalesDocument;
use App\Models\User;
use App\Modules\Connect\Support\ConnectScopes;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\SaveAgreement;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\Account as CrmAccount;
use App\Modules\Inventory\Actions\ArticleImages;
use App\Modules\Inventory\InventoryModule;
use App\Modules\ModuleManager;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;
use Tests\Support\Crm;

/*
 * AGENTS.md §13.2 for the public API (ADR-032): a credential for company A
 * never sees company B. Every /api/v1 route with an id is called with B's
 * id and must answer 404; every list must hold only A's records. The last
 * test fails when a route is added without being listed here.
 */

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    Connect::setUp();
});

/**
 * @return array{key: string, a: Company, b: array<string, mixed>}
 */
function twoCompanies(): array
{
    [$ownerA, $a] = Connect::company();
    [$ownerB, $b] = Connect::company();

    $voucherB = Bookkeeping::draft($ownerB, $b);
    $postedB = Bookkeeping::post($ownerB, $b);
    $attachmentB = app(AttachToVoucher::class)($ownerB, $voucherB, UploadedFile::fake()->createWithContent('b.pdf', "%PDF-1.4\n%%EOF\n"));
    $customerB = app(SaveCustomer::class)($ownerB, $b, ['name' => 'B:s kund AB', 'payment_terms_days' => 30]);
    $supplierB = app(SaveSupplier::class)($ownerB, $b, ['name' => 'B:s leverantör AB', 'payment_terms_days' => 30]);
    $yearB = CurrentCompany::run($b, fn () => FiscalYear::query()->firstOrFail());
    $articleB = app(SaveArticle::class)($ownerB, $b, ['number' => 'B1', 'name' => 'B:s artikel', 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500]);
    // Lager on in both, so an inventory route answers 404 and not 403 for B's records.
    app(ModuleManager::class)->enable(InventoryModule::KEY, $b, $ownerB);
    app(ModuleManager::class)->enable(InventoryModule::KEY, $a, $ownerA);
    $imageB = CurrentCompany::run($b, fn () => app(ArticleImages::class)->add($ownerB, $b, $articleB, UploadedFile::fake()->image('b.jpg', 40, 40)));
    $costCenterB = app(SaveDimensionObject::class)($ownerB, $b, Dimension::CostCenter, ['code' => 'B10', 'name' => 'B:s kostnadsställe']);
    $projectB = app(SaveDimensionObject::class)($ownerB, $b, Dimension::Project, ['code' => 'BP', 'name' => 'B:s projekt']);
    $invoiceRows = [['description' => 'B:s rad', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]];
    $invoiceB = app(SaveDraftInvoice::class)($ownerB, $b, ['customer_id' => $customerB->id, 'invoice_date' => '2026-03-02', 'rows' => $invoiceRows]);
    $issuedB = app(IssueInvoice::class)($ownerB, app(SaveDraftInvoice::class)($ownerB, $b, ['customer_id' => $customerB->id, 'invoice_date' => '2026-03-02', 'rows' => $invoiceRows]));
    app(RegisterPayment::class)($ownerB, $issuedB, CarbonImmutable::parse('2026-03-05'), Money::ofOre(5000));
    // CRM on in both, so a CRM route answers 404 and not 403 for B's records.
    app(ModuleManager::class)->enable(CrmModule::KEY, $b, $ownerB);
    app(ModuleManager::class)->enable(CrmModule::KEY, $a, $ownerA);
    $crmAccountB = CurrentCompany::run($b, fn () => CrmAccount::query()->where('customer_id', $customerB->id)->firstOrFail());
    $crmPersonB = app(SavePerson::class)($ownerB, $b, ['name' => 'B:s kontakt', 'legal_basis' => 'AGREEMENT']);
    $crmActivityB = app(LogActivity::class)($ownerB, $b, ['account_id' => $crmAccountB->id, 'type' => 'NOTE', 'subject' => 'B:s anteckning']);
    $crmDealB = Crm::deal($b, $ownerB, $crmAccountB, 'B:s affär');
    $crmPipelineB = $crmDealB->pipeline_id;
    $crmAgreementB = app(SaveAgreement::class)($ownerB, $b, ['account_id' => $crmAccountB->id, 'title' => 'B:s avtal', 'kind' => 'SERVICE', 'start_date' => '2026-01-01', 'notice_months' => 0, 'value_period' => 'MONTH']);
    $crmQuoteB = app(SaveSalesDocument::class)($ownerB, $b, SalesDocument::QUOTE, ['customer_id' => $customerB->id, 'document_date' => '2026-03-02', 'rows' => $invoiceRows]);
    $supplierInvoiceB = app(SaveSupplierInvoice::class)($ownerB, $b, ['supplier_id' => $supplierB->id, 'invoice_date' => '2026-03-03', 'total' => '125', 'vat' => '25', 'rows' => [['account' => '5410', 'amount' => '100']]]);

    // A has its own records too, so the lists are not empty by accident.
    Bookkeeping::draft($ownerA, $a);
    app(SaveCustomer::class)($ownerA, $a, ['name' => 'A:s kund AB', 'payment_terms_days' => 30]);
    $supplierA = app(SaveSupplier::class)($ownerA, $a, ['name' => 'A:s leverantör AB', 'payment_terms_days' => 30]);
    $customerA = app(SaveCustomer::class)($ownerA, $a, ['name' => 'A:s andra kund AB', 'payment_terms_days' => 30]);
    app(SaveArticle::class)($ownerA, $a, ['number' => 'A1', 'name' => 'A:s artikel', 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500]);
    app(SaveDimensionObject::class)($ownerA, $a, Dimension::CostCenter, ['code' => 'A10', 'name' => 'A:s kostnadsställe']);
    app(SaveDimensionObject::class)($ownerA, $a, Dimension::Project, ['code' => 'AP', 'name' => 'A:s projekt']);
    $issuedA = app(IssueInvoice::class)($ownerA, app(SaveDraftInvoice::class)($ownerA, $a, ['customer_id' => $customerA->id, 'invoice_date' => '2026-03-02', 'rows' => [['description' => 'A:s rad', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]]));
    app(RegisterPayment::class)($ownerA, $issuedA, CarbonImmutable::parse('2026-03-05'), Money::ofOre(5000));
    $approverA = Bookkeeping::member($a, 'approver');
    $registeredA = app(RegisterSupplierInvoice::class)($ownerA, app(SaveSupplierInvoice::class)($ownerA, $a, ['supplier_id' => $supplierA->id, 'invoice_date' => '2026-03-03', 'total' => '125', 'vat' => '25', 'rows' => [['account' => '5410', 'amount' => '100']]]));
    app(PaySupplierInvoice::class)($ownerA, app(ApproveSupplierInvoice::class)($approverA, $registeredA), CarbonImmutable::parse('2026-03-20'), Money::ofOre(12500));

    return [
        'key' => Connect::apiKey($ownerA, $a, ConnectScopes::SCOPES),
        'a' => $a,
        'b' => [
            'voucher' => $voucherB->id, 'posted' => $postedB->id, 'attachment' => $attachmentB->id, 'customer' => $customerB->id,
            'supplier' => $supplierB->id, 'fiscal_year' => $yearB->id, 'article' => $articleB->id, 'cost_center' => $costCenterB->id,
            'project' => $projectB->id, 'invoice' => $invoiceB->id, 'issued' => $issuedB->id, 'supplier_invoice' => $supplierInvoiceB->id,
            'article_image' => $imageB->id, 'crm_account' => $crmAccountB->id, 'crm_person' => $crmPersonB->id, 'crm_activity' => $crmActivityB->id,
            'crm_deal' => $crmDealB->id, 'crm_pipeline' => $crmPipelineB, 'crm_agreement' => $crmAgreementB->id, 'quote' => $crmQuoteB->id,
        ],
    ];
}

/** @return list<array{0: string, 1: string, 2?: array<string, mixed>}> method, uri with B's ids, body */
function crossCompanyCalls(array $b): array
{
    return [
        ['GET', "/api/v1/fiscal-years/{$b['fiscal_year']}"],
        ['GET', "/api/v1/vouchers/{$b['voucher']}"],
        ['PATCH', "/api/v1/vouchers/{$b['voucher']}", ['description' => 'Kapad']],
        ['DELETE', "/api/v1/vouchers/{$b['voucher']}"],
        ['POST', "/api/v1/vouchers/{$b['voucher']}/post"],
        ['POST', "/api/v1/vouchers/{$b['posted']}/correct", ['reason' => 'Kapad']],
        ['GET', "/api/v1/vouchers/{$b['voucher']}/attachments"],
        ['POST', "/api/v1/vouchers/{$b['voucher']}/attachments"],
        ['GET', "/api/v1/vouchers/{$b['voucher']}/attachments/{$b['attachment']}/content"],
        ['GET', "/api/v1/customers/{$b['customer']}"],
        ['PATCH', "/api/v1/customers/{$b['customer']}", ['name' => 'Kapad']],
        ['GET', "/api/v1/suppliers/{$b['supplier']}"],
        ['PATCH', "/api/v1/suppliers/{$b['supplier']}", ['name' => 'Kapad']],
        ['GET', "/api/v1/fiscal-years/{$b['fiscal_year']}/periods"],
        ['GET', "/api/v1/fiscal-years/{$b['fiscal_year']}/sie"],
        ['GET', "/api/v1/articles/{$b['article']}"],
        ['PATCH', "/api/v1/articles/{$b['article']}", ['name' => 'Kapad']],
        ['GET', "/api/v1/inventory/articles/{$b['article']}/images/{$b['article_image']}/content"],
        ['GET', "/api/v1/cost-centers/{$b['cost_center']}"],
        ['PATCH', "/api/v1/cost-centers/{$b['cost_center']}", ['name' => 'Kapad']],
        ['GET', "/api/v1/projects/{$b['project']}"],
        ['PATCH', "/api/v1/projects/{$b['project']}", ['name' => 'Kapad']],
        ['GET', "/api/v1/invoices/{$b['invoice']}"],
        ['PATCH', "/api/v1/invoices/{$b['invoice']}", ['notes' => 'Kapad']],
        ['GET', "/api/v1/invoices/{$b['issued']}/pdf"],
        ['POST', "/api/v1/invoices/{$b['invoice']}/issue"],
        ['POST', "/api/v1/invoices/{$b['issued']}/send", ['email' => 'kapare@exempel.example']],
        ['POST', "/api/v1/invoices/{$b['issued']}/credit"],
        ['POST', "/api/v1/invoices/{$b['issued']}/payments", ['paid_on' => '2026-03-05', 'amount_ore' => 100]],
        ['GET', "/api/v1/supplier-invoices/{$b['supplier_invoice']}"],
        ['PATCH', "/api/v1/supplier-invoices/{$b['supplier_invoice']}", ['description' => 'Kapad']],
        ['DELETE', "/api/v1/supplier-invoices/{$b['supplier_invoice']}"],
        ['POST', "/api/v1/supplier-invoices/{$b['supplier_invoice']}/file"],
        ['POST', "/api/v1/supplier-invoices/{$b['supplier_invoice']}/register"],
        ['POST', "/api/v1/supplier-invoices/{$b['supplier_invoice']}/approve"],
        ['POST', "/api/v1/supplier-invoices/{$b['supplier_invoice']}/payments", ['paid_on' => '2026-03-05', 'amount_ore' => 100]],
        ['GET', "/api/v1/crm/accounts/{$b['crm_account']}"],
        ['PATCH', "/api/v1/crm/accounts/{$b['crm_account']}", ['source' => 'Kapad']],
        ['GET', "/api/v1/crm/people/{$b['crm_person']}"],
        ['PATCH', "/api/v1/crm/people/{$b['crm_person']}", ['title' => 'Kapad']],
        ['GET', "/api/v1/crm/activities/{$b['crm_activity']}"],
        ['PATCH', "/api/v1/crm/activities/{$b['crm_activity']}", ['subject' => 'Kapad']],
        ['GET', "/api/v1/crm/deals/{$b['crm_deal']}"],
        ['PATCH', "/api/v1/crm/deals/{$b['crm_deal']}", ['title' => 'Kapad']],
        ['GET', "/api/v1/crm/pipelines/{$b['crm_pipeline']}"],
        ['GET', "/api/v1/crm/agreements/{$b['crm_agreement']}"],
        ['PATCH', "/api/v1/crm/agreements/{$b['crm_agreement']}", ['title' => 'Kapad']],
        ['GET', "/api/v1/crm/agreements/{$b['crm_agreement']}/signing"],
        ['POST', "/api/v1/crm/agreements/{$b['crm_agreement']}/signing", ['email' => 'kapad@exempel.se']],
        ['GET', "/api/v1/quotes/{$b['quote']}/signing"],
        ['POST', "/api/v1/quotes/{$b['quote']}/signing", ['email' => 'kapad@exempel.se']],
        // Reports that take another company's fiscal year as a parameter.
        ['GET', "/api/v1/reports/trial-balance?fiscal_year_id={$b['fiscal_year']}"],
        ['GET', "/api/v1/reports/general-ledger?fiscal_year_id={$b['fiscal_year']}"],
        ['GET', "/api/v1/reports/balance-sheet?fiscal_year_id={$b['fiscal_year']}"],
        ['GET', "/api/v1/reports/income-statement?fiscal_year_id={$b['fiscal_year']}"],
        ['GET', "/api/v1/vat-periods?fiscal_year_id={$b['fiscal_year']}"],
        ['GET', "/api/v1/vat-return?fiscal_year_id={$b['fiscal_year']}&period_start=2026-01-01"],
    ];
}

it('answers 404 for every route given another company\'s id, and changes nothing', function () {
    $world = twoCompanies();

    foreach (crossCompanyCalls($world['b']) as $index => $call) {
        $response = $this->withToken($world['key'])
            ->withHeader('Idempotency-Key', 'tenancy-'.$index)
            ->json($call[0], $call[1], $call[2] ?? []);

        expect($response->status())->toBe(404, "{$call[0]} {$call[1]} answered {$response->status()}");
    }

    $this->withToken($world['key'])->getJson("/api/v1/vouchers/{$world['b']['posted']}")->assertNotFound();
});

it('lists only the credential\'s own company', function (string $uri, string $foreign) {
    $world = twoCompanies();

    $response = $this->withToken($world['key'])->getJson($uri)->assertOk();

    expect($response->getContent())->not->toContain($foreign)
        ->and($response->json('data'))->not->toBeEmpty();
})->with([
    ['/api/v1/vouchers', 'B:s'],
    ['/api/v1/customers', 'B:s kund'],
    ['/api/v1/suppliers', 'B:s leverantör'],
    ['/api/v1/accounts', 'B:s'],
    ['/api/v1/fiscal-years', 'B:s'],
    ['/api/v1/voucher-series', 'B:s'],
    ['/api/v1/articles', 'B:s artikel'],
    ['/api/v1/cost-centers', 'B:s kostnadsställe'],
    ['/api/v1/projects', 'B:s projekt'],
    ['/api/v1/invoices', 'B:s'],
    ['/api/v1/supplier-invoices', 'B:s'],
    ['/api/v1/payments', 'B:s'],
    ['/api/v1/supplier-payments', 'B:s'],
]);

it('shows the credential\'s own company, never the person\'s current one', function () {
    $world = twoCompanies();
    $owner = User::query()->findOrFail($world['a']->user_id);

    // The person switches to another company in the browser; the key stays with A.
    [, $other] = Bookkeeping::company();
    $other->users()->attach($owner, ['role' => 'admin']);
    $owner->switchCompany($other);

    $this->withToken($world['key'])->getJson('/api/v1/company')->assertOk()->assertJsonPath('data.id', $world['a']->id);
});

it('covers every API route that takes an id', function () {
    $listed = collect(crossCompanyCalls(array_fill_keys(['voucher', 'posted', 'attachment', 'customer', 'supplier', 'fiscal_year', 'article', 'cost_center', 'project', 'invoice', 'issued', 'supplier_invoice', 'article_image', 'crm_account', 'crm_person', 'crm_activity', 'crm_deal', 'crm_pipeline', 'crm_agreement', 'quote'], 'X')))
        ->map(fn (array $call): string => $call[0].' '.preg_replace('/X/', '{}', explode('?', $call[1])[0]))
        ->unique()->values();

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/') && str_contains($route->uri(), '{') && $route->uri() !== 'api/v1/accounts/{number}')
        ->flatMap(fn ($route): array => array_map(fn (string $method): string => $method.' /'.preg_replace('/\{[^}]+\}/', '{}', $route->uri()), array_diff($route->methods(), ['HEAD'])))
        ->unique()->values();

    expect($routes->diff($listed)->values()->all())->toBe([]);
});
