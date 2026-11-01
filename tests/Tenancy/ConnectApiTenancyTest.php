<?php

declare(strict_types=1);

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Payables\SaveSupplier;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;

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

const CONNECT_ALL_SCOPES = ['vouchers:view', 'vouchers:create', 'vouchers:post', 'vouchers:correct', 'invoices:view', 'invoices:create', 'supplier-invoices:create', 'supplier-invoices:approve'];

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

    // A has its own records too, so the lists are not empty by accident.
    Bookkeeping::draft($ownerA, $a);
    app(SaveCustomer::class)($ownerA, $a, ['name' => 'A:s kund AB', 'payment_terms_days' => 30]);
    app(SaveSupplier::class)($ownerA, $a, ['name' => 'A:s leverantör AB', 'payment_terms_days' => 30]);

    return [
        'key' => Connect::apiKey($ownerA, $a, CONNECT_ALL_SCOPES),
        'a' => $a,
        'b' => ['voucher' => $voucherB->id, 'posted' => $postedB->id, 'attachment' => $attachmentB->id, 'customer' => $customerB->id, 'supplier' => $supplierB->id, 'fiscal_year' => $yearB->id],
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
    $listed = collect(crossCompanyCalls(array_fill_keys(['voucher', 'posted', 'attachment', 'customer', 'supplier', 'fiscal_year'], 'X')))
        ->map(fn (array $call): string => $call[0].' '.preg_replace('/X/', '{}', $call[1]))
        ->unique()->values();

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/') && str_contains($route->uri(), '{') && $route->uri() !== 'api/v1/accounts/{number}')
        ->flatMap(fn ($route): array => array_map(fn (string $method): string => $method.' /'.preg_replace('/\{[^}]+\}/', '{}', $route->uri()), array_diff($route->methods(), ['HEAD'])))
        ->unique()->values();

    expect($routes->diff($listed)->values()->all())->toBe([]);
});
