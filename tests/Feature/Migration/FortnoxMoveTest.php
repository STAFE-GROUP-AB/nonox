<?php

declare(strict_types=1);

use App\Actions\Accounting\ConfirmSieImport;
use App\Actions\Invoicing\SaveCustomer;
use App\Domain\Accounting\SieImport\SieImportStatus;
use App\Migration\Fortnox\FortnoxClient;
use App\Migration\Fortnox\FortnoxConnection;
use App\Migration\Fortnox\FortnoxImportedFile;
use App\Migration\Fortnox\FortnoxMove;
use App\Migration\Fortnox\FortnoxOAuth;
use App\Migration\Fortnox\Livewire\FortnoxMovePage;
use App\Models\Article;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\VoucherAttachment;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    Sleep::fake();
    config(['services.fortnox.client_id' => 'client-123', 'services.fortnox.client_secret' => 'secret-456']);
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['org_number' => '556334-3689'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->fortnox = [];
    $this->fortnoxHandler = null;
    Http::fake(fn (Request $request) => fortnoxResponse($request));
    $this->token = ['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600, 'scope' => 'bookkeeping'];
});

/** @param array<string, mixed> $responses path => response body (array for JSON, string for raw) */
function fakeFortnox(array $responses): void
{
    test()->fortnox = [...test()->fortnox, ...$responses];
}

/** The single fake for every test: a custom handler when set, otherwise the responses by path. */
function fortnoxResponse(Request $request): mixed
{
    if (test()->fortnoxHandler !== null) {
        return (test()->fortnoxHandler)($request);
    }

    if (str_starts_with($request->url(), FortnoxOAuth::TOKEN_URL)) {
        return Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600, 'scope' => 'bookkeeping']);
    }

    $path = strtok(substr($request->url(), strlen(FortnoxClient::BASE_URL)), '?');
    $responses = test()->fortnox;

    if (! array_key_exists($path, $responses)) {
        return Http::response(['ErrorInformation' => ['message' => "Not faked: {$path}"]], 404);
    }

    return is_array($responses[$path]) ? Http::response($responses[$path]) : Http::response($responses[$path], 200, ['Content-Type' => 'application/octet-stream']);
}

function fortnoxConnected(): FortnoxConnection
{
    fakeFortnox(['companyinformation' => ['CompanyInformation' => ['CompanyName' => 'Exempelbolaget i Malmö AB', 'OrganizationNumber' => '556334-3689']]]);

    return app(FortnoxMove::class)->connect(test()->owner, test()->company, test()->token);
}

it('builds the authorisation link with PKCE and the read scopes', function () {
    $url = FortnoxOAuth::authorizeUrl('state-1', str_repeat('v', 64), 'https://nonox.test/flytt/fortnox/klar');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith(FortnoxOAuth::AUTHORIZE_URL)
        ->and($query)->toMatchArray(['client_id' => 'client-123', 'response_type' => 'code', 'access_type' => 'offline', 'state' => 'state-1', 'code_challenge_method' => 'S256'])
        ->and($query['code_challenge'])->toBe(FortnoxOAuth::challenge(str_repeat('v', 64)))
        ->and(explode(' ', $query['scope']))->toContain('bookkeeping', 'connectfile', 'archive');
});

it('connects only a Fortnox account with the company\'s organisation number, and keeps the tokens encrypted', function () {
    fakeFortnox(['companyinformation' => ['CompanyInformation' => ['CompanyName' => 'Annat AB', 'OrganizationNumber' => '556123-4567']]]);

    expect(fn () => app(FortnoxMove::class)->connect($this->owner, $this->company, $this->token))->toThrow(ValidationException::class)
        ->and(FortnoxConnection::query()->count())->toBe(0);

    $connection = fortnoxConnected();

    expect($connection->isConnected())->toBeTrue()
        ->and($connection->fortnox_company_name)->toBe('Exempelbolaget i Malmö AB')
        ->and(DB::table('fortnox_connections')->value('access_token'))->not->toContain('access-1');
});

it('runs the OAuth callback only for the flow this browser started', function () {
    fakeFortnox(['companyinformation' => ['CompanyInformation' => ['CompanyName' => 'Exempelbolaget i Malmö AB', 'OrganizationNumber' => '5563343689']]]);

    $redirect = $this->get(route('fortnox.connect'))->assertRedirect();
    parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);

    $this->get(route('fortnox.callback', ['code' => 'abc', 'state' => 'forged']))->assertRedirect(route('fortnox.move'));
    expect(FortnoxConnection::query()->count())->toBe(0);

    $this->get(route('fortnox.connect'));
    $state = session('fortnox.oauth.state');
    $this->get(route('fortnox.callback', ['code' => 'abc', 'state' => $state]))->assertRedirect(route('fortnox.move'));

    expect(FortnoxConnection::query()->first()?->isConnected())->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->url() === FortnoxOAuth::TOKEN_URL && $request['grant_type'] === 'authorization_code' && $request->hasHeader('Authorization', 'Basic '.base64_encode('client-123:secret-456')));
});

it('fetches a year as SIE4 into the ordinary review, then brings the files over to the same vouchers', function () {
    $connection = fortnoxConnected();
    $sie = (string) file_get_contents(__DIR__.'/../../Fixtures/sie/import-2026.se');

    fakeFortnox([
        'sie/4' => $sie,
        'voucherfileconnections' => ['VoucherFileConnections' => [
            ['FileId' => 'f-1', 'Name' => 'faktura-1.pdf', 'VoucherSeries' => 'A', 'VoucherNumber' => 1, 'VoucherYear' => 7],
            ['FileId' => 'f-2', 'Name' => 'saknas.pdf', 'VoucherSeries' => 'A', 'VoucherNumber' => 999, 'VoucherYear' => 7],
        ]],
        'archive/f-1' => "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n",
    ]);

    $import = app(FortnoxMove::class)->fetchSieYear($this->owner, $this->company, $connection, 7, '2026-2026');

    expect($import->status)->toBe(SieImportStatus::Analysed)
        ->and($import->original_name)->toBe('fortnox-2026-2026.se')
        ->and($connection->fresh()->progress['years'])->toBe(['7' => $import->id]);

    app(ConfirmSieImport::class)($this->owner, $import);
    expect($import->fresh()->status)->toBe(SieImportStatus::Completed);

    expect(app(FortnoxMove::class)->queueFiles($this->company, $connection->fresh(), 7))->toBe(2)
        ->and(app(FortnoxMove::class)->importFiles($this->owner, $this->company, $connection->fresh()))->toBe(0);

    expect(FortnoxImportedFile::query()->where('file_id', 'f-1')->value('status'))->toBe('DONE')
        ->and(FortnoxImportedFile::query()->where('file_id', 'f-2')->value('status'))->toBe('FAILED')
        ->and(VoucherAttachment::query()->count())->toBe(1);

    // Queuing again never fetches a file twice.
    app(FortnoxMove::class)->queueFiles($this->company, $connection->fresh(), 7);
    expect(FortnoxImportedFile::query()->count())->toBe(2);
});

it('adds customers, suppliers and articles that do not exist, and lists what it cannot read', function () {
    $connection = fortnoxConnected();
    app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Redan Här AB', 'payment_terms_days' => 30]);

    fakeFortnox([
        'customers' => ['Customers' => [
            ['CustomerNumber' => '1', 'Name' => 'Redan Här AB'],
            ['CustomerNumber' => '2', 'Name' => 'Ny Kund AB', 'OrganisationNumber' => '556334-3689', 'Email' => 'faktura@exempel.test', 'TermsOfPayment' => '20', 'CountryCode' => 'SE'],
            ['CustomerNumber' => '3', 'Name' => 'Fel Nummer AB', 'OrganisationNumber' => '123'],
        ], 'MetaInformation' => ['@TotalPages' => 1]],
        'suppliers' => ['Suppliers' => [['SupplierNumber' => '1', 'Name' => 'Leverantören AB', 'BG' => '5050-1055', 'TermsOfPayment' => '30']], 'MetaInformation' => ['@TotalPages' => 1]],
        'articles' => ['Articles' => [['ArticleNumber' => 'K1', 'Description' => 'Konsulttimme', 'Unit' => 'h', 'SalesPrice' => 950.5, 'VAT' => 25, 'SalesAccount' => 3001]], 'MetaInformation' => ['@TotalPages' => 1]],
    ]);

    $result = app(FortnoxMove::class)->importRegisters($this->owner, $this->company, $connection);

    expect($result['customers'])->toMatchArray(['created' => 2, 'existing' => 1])
        ->and(Customer::query()->where('name', 'Ny Kund AB')->first()?->payment_terms_days)->toBe(20)
        ->and(Customer::query()->where('name', 'Fel Nummer AB')->first()?->org_number)->toBeNull()
        ->and(Supplier::query()->where('name', 'Leverantören AB')->exists())->toBeTrue()
        ->and(Article::query()->where('number', 'K1')->first()?->price_ore->ore)->toBe(95050);
});

it('pages through long lists and keeps within the rate limit', function () {
    $connection = fortnoxConnected();
    $page = fn (int $number) => ['FinancialYears' => [['Id' => $number, 'FromDate' => "202{$number}-01-01", 'ToDate' => "202{$number}-12-31"]], 'MetaInformation' => ['@TotalPages' => 2, '@CurrentPage' => $number]];

    $this->fortnoxHandler = fn (Request $request) => str_contains($request->url(), 'page=2') ? Http::response($page(2)) : Http::response($page(1));

    expect(array_column(app(FortnoxMove::class)->financialYears($connection), 'id'))->toBe([1, 2]);
});

it('retries after 429 and refreshes an expired token', function () {
    $connection = fortnoxConnected();
    $connection->forceFill(['expires_at' => CarbonImmutable::now()->subMinute()])->save();
    $calls = 0;

    $this->fortnoxHandler = function (Request $request) use (&$calls) {
        if (str_starts_with($request->url(), FortnoxOAuth::TOKEN_URL)) {
            return Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600]);
        }

        return ++$calls === 1 ? Http::response([], 429) : Http::response(['FinancialYears' => []]);
    };

    app(FortnoxMove::class)->financialYears($connection);

    expect($connection->fresh()->refresh_token)->toBe('refresh-2');
    Sleep::assertSleptTimes(1);
});

it('compares unpaid invoices with the ledger and deletes the tokens when the move is complete', function () {
    $connection = fortnoxConnected();
    fakeFortnox([
        'invoices' => ['Invoices' => [['DocumentNumber' => '1', 'Balance' => 1250.00]], 'MetaInformation' => ['@TotalPages' => 1]],
        'supplierinvoices' => ['SupplierInvoices' => [], 'MetaInformation' => ['@TotalPages' => 1]],
    ]);

    $items = app(FortnoxMove::class)->openItems($this->company, $connection);

    expect($items['customers'])->toMatchArray(['count' => 1, 'fortnox' => 125000])
        ->and($items['suppliers']['fortnox'])->toBe(0);

    app(FortnoxMove::class)->complete($this->owner, $this->company, $connection);

    expect($connection->fresh()->access_token)->toBeNull()
        ->and($connection->fresh()->refresh_token)->toBeNull()
        ->and($connection->fresh()->isConnected())->toBeFalse();
});

it('shows the steps, and only to those allowed to import', function () {
    $this->get(route('fortnox.move'))->assertOk()->assertSee('Anslut till Fortnox');

    fortnoxConnected();
    Livewire::test(FortnoxMovePage::class)->assertSee('Ansluten till Exempelbolaget i Malmö AB')->assertSee('Bokföringen, år för år');

    $this->actingAs(Bookkeeping::member($this->company, 'approver'))->get(route('fortnox.move'))->assertForbidden();
});

it('says when the installation has no Fortnox credentials', function () {
    config(['services.fortnox.client_id' => null]);

    $this->get(route('fortnox.move'))->assertOk()->assertSee('FORTNOX_CLIENT_ID');
    $this->get(route('fortnox.connect'))->assertRedirect(route('fortnox.move'));
});
