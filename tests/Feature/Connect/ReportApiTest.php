<?php

declare(strict_types=1);

use App\Actions\Accounting\LockPeriod;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;

/*
 * Reports, the VAT return, SIE export, fiscal year periods and dimensions
 * over the API (ADR-032), from the same domain code as the app.
 */

beforeEach(function () {
    Mail::fake();
    Connect::setUp();
});

it('serves the trial balance, income statement, balance sheet and general ledger as JSON', function () {
    [$owner, $company] = Connect::company();
    Bookkeeping::post($owner, $company, 125000, 1930, 3001, '2026-03-01');
    Bookkeeping::post($owner, $company, 40000, 5010, 1930, '2026-04-10');
    $key = Connect::apiKey($owner, $company, ['reports:view', 'vouchers:view']);
    $year = $this->withToken($key)->getJson('/api/v1/fiscal-years')->json('data.0.id');

    $trial = $this->withToken($key)->getJson("/api/v1/reports/trial-balance?fiscal_year_id={$year}")->assertOk()
        ->assertJsonPath('data.currency', 'SEK')->assertJsonPath('data.from', '2026-01-01');
    $bank = collect($trial->json('data.rows'))->firstWhere('account', 1930);
    expect($bank['debit_ore'])->toBe(125000)->and($bank['credit_ore'])->toBe(40000)->and($bank['closing_ore'])->toBe(85000)
        ->and($trial->json('data.totals.debit_ore'))->toBe($trial->json('data.totals.credit_ore'));

    $this->withToken($key)->getJson("/api/v1/reports/trial-balance?fiscal_year_id={$year}&from=2026-04-01&to=2026-04-30")->assertOk()
        ->assertJsonPath('data.totals.debit_ore', 40000);

    $this->withToken($key)->getJson("/api/v1/reports/income-statement?fiscal_year_id={$year}")->assertOk()
        ->assertJsonPath('data.result_ore', 85000)->assertJsonPath('data.groups.0.key', 'revenue')->assertJsonPath('data.groups.0.total_ore', 125000);

    $this->withToken($key)->getJson("/api/v1/reports/balance-sheet?fiscal_year_id={$year}")->assertOk()
        ->assertJsonPath('data.assets.closing_ore', 85000)->assertJsonPath('data.unbooked_result.closing_ore', 85000);

    $ledger = $this->withToken($key)->getJson("/api/v1/reports/general-ledger?fiscal_year_id={$year}&account=1930")->assertOk()->json('data.accounts.0');
    expect($ledger['account'])->toBe(1930)->and($ledger['rows'])->toHaveCount(2)
        ->and($ledger['rows'][0]['date'])->toBe('2026-03-01')->and($ledger['rows'][1]['balance_ore'])->toBe(85000);

    $this->withToken($key)->getJson("/api/v1/reports/trial-balance?fiscal_year_id={$year}&from=2025-12-01")->assertStatus(422);
    $this->withToken($key)->getJson('/api/v1/reports/trial-balance')->assertStatus(422);
    $this->withToken($key)->getJson("/api/v1/reports/trial-balance?fiscal_year_id={$year}&cost_center=NOPE")->assertStatus(422);
});

it('needs reports:view for reports', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view']);

    $this->withToken($key)->getJson('/api/v1/reports/trial-balance?fiscal_year_id=1')->assertForbidden()->assertJsonPath('required_scopes', ['reports:view']);
});

it('calculates the VAT return of a period with the accounts behind each box', function () {
    [$owner, $company] = Connect::company();
    $voucher = app(SaveDraftVoucher::class)($owner, $company, [
        'voucher_date' => '2026-02-10', 'description' => 'Försäljning', 'series' => 'A',
        'rows' => [['account' => 1930, 'debit_ore' => 125000], ['account' => 3001, 'credit_ore' => 100000], ['account' => 2611, 'credit_ore' => 25000]],
    ]);
    app(PostVoucher::class)($owner, $voucher);
    $key = Connect::apiKey($owner, $company, ['vat:prepare', 'vouchers:view']);
    $year = $this->withToken($key)->getJson('/api/v1/fiscal-years')->json('data.0.id');

    $periods = $this->withToken($key)->getJson("/api/v1/vat-periods?fiscal_year_id={$year}")->assertOk()->json('data');
    expect($periods)->not->toBeEmpty()->and($periods[0]['settled'])->toBeFalse();

    $start = collect($periods)->first(fn (array $period): bool => $period['start'] <= '2026-02-10' && $period['end'] >= '2026-02-10')['start'];
    $return = $this->withToken($key)->getJson("/api/v1/vat-return?fiscal_year_id={$year}&period_start={$start}")->assertOk()->json('data');
    $boxes = collect($return['boxes'])->keyBy('box');

    expect($boxes['05']['amount_sek'])->toBe(1000)
        ->and($boxes['10']['amount_sek'])->toBe(250)
        ->and($boxes['10']['accounts'][0])->toBe(['account' => 2611, 'name' => $boxes['10']['accounts'][0]['name'], 'amount_ore' => 25000])
        ->and($return['payable_sek'])->toBe(250);

    $this->withToken($key)->getJson("/api/v1/vat-return?fiscal_year_id={$year}&period_start=2026-02-11")->assertStatus(422);
});

it('exports a fiscal year as SIE 4 in CP437, only with export:sie', function () {
    [$owner, $company] = Connect::company();
    Bookkeeping::post($owner, $company);
    $key = Connect::apiKey($owner, $company, ['vouchers:view', 'export:sie']);
    $year = $this->withToken($key)->getJson('/api/v1/fiscal-years')->json('data.0.id');

    $response = $this->withToken($key)->get("/api/v1/fiscal-years/{$year}/sie")->assertOk()->assertHeader('Content-Type', 'text/plain; charset=IBM437');
    $content = $response->streamedContent();

    expect($content)->toStartWith('#FLAGGA 0')->toContain('#SIETYP 4')->toContain('#VER')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment; filename=');

    $readOnly = Connect::apiKey($owner, $company, ['vouchers:view']);
    $this->withToken($readOnly)->get("/api/v1/fiscal-years/{$year}/sie")->assertForbidden();
});

it('shows which months are locked', function () {
    [$owner, $company] = Connect::company();
    app(LockPeriod::class)($owner, $company, CarbonImmutable::parse('2026-03-15'));
    $key = Connect::apiKey($owner, $company, ['vouchers:view']);
    $year = $this->withToken($key)->getJson('/api/v1/fiscal-years')->json('data.0.id');

    $data = $this->withToken($key)->getJson("/api/v1/fiscal-years/{$year}/periods")->assertOk()
        ->assertJsonPath('data.locked_until_date', '2026-03-15')->assertJsonPath('data.status', 'OPEN')->json('data.periods');

    expect($data)->toHaveCount(12)
        ->and($data[1])->toMatchArray(['start' => '2026-02-01', 'end' => '2026-02-28', 'locked' => true, 'partly_locked' => false])
        ->and($data[2])->toMatchArray(['start' => '2026-03-01', 'end' => '2026-03-31', 'locked' => false, 'partly_locked' => true])
        ->and($data[3]['locked'])->toBeFalse();
});

it('reads cost centres and projects with vouchers:view and changes them with accounts:manage', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view', 'accounts:manage']);

    $id = $this->withToken($key)->withHeader('Idempotency-Key', 'cc-1')->postJson('/api/v1/cost-centers', ['code' => '10', 'name' => 'Butiken'])
        ->assertCreated()->assertJsonPath('data.code', '10')->json('data.id');
    $this->withToken($key)->patchJson("/api/v1/cost-centers/{$id}", ['name' => 'Butiken i Exempelstad', 'is_active' => false])->assertOk()
        ->assertJsonPath('data.name', 'Butiken i Exempelstad')->assertJsonPath('data.is_active', false);
    $this->withToken($key)->patchJson("/api/v1/cost-centers/{$id}", ['code' => '11'])->assertStatus(422);
    $this->withToken($key)->getJson('/api/v1/cost-centers')->assertOk()->assertJsonCount(1, 'data');

    $project = $this->withToken($key)->withHeader('Idempotency-Key', 'p-1')->postJson('/api/v1/projects', ['code' => 'P1', 'name' => 'Ombyggnad'])->assertCreated()->json('data.id');
    $this->withToken($key)->getJson("/api/v1/projects/{$project}")->assertOk()->assertJsonPath('data.code', 'P1');

    $readOnly = Connect::apiKey($owner, $company, ['vouchers:view']);
    $this->withToken($readOnly)->getJson('/api/v1/projects')->assertOk();
    $this->withToken($readOnly)->withHeader('Idempotency-Key', 'p-2')->postJson('/api/v1/projects', ['code' => 'P2', 'name' => 'X'])->assertForbidden();
});
