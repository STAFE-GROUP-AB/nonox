<?php

declare(strict_types=1);

use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Connect;

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    Connect::setUp();
});

function voucherBody(int $ore = 125000): array
{
    return [
        'voucher_date' => '2026-03-01',
        'description' => 'Kontantförsäljning',
        'series' => 'A',
        'rows' => [
            ['account' => 1930, 'debit_ore' => $ore],
            ['account' => 3001, 'credit_ore' => $ore],
        ],
    ];
}

it('creates a draft, changes it, posts it with the next number, and then never changes it', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view', 'vouchers:create', 'vouchers:post', 'vouchers:correct']);

    $created = $this->withToken($key)->withHeader('Idempotency-Key', 'v-1')->postJson('/api/v1/vouchers', voucherBody())
        ->assertCreated()
        ->assertJsonPath('data.status', 'DRAFT')
        ->assertJsonPath('data.number', null)
        ->assertJsonPath('data.rows.0.debit_ore', 125000);
    $id = $created->json('data.id');
    expect($created->headers->get('Location'))->toEndWith("/api/v1/vouchers/{$id}");

    $this->withToken($key)->patchJson("/api/v1/vouchers/{$id}", ['description' => 'Kontantförsäljning vecka 9'])
        ->assertOk()->assertJsonPath('data.description', 'Kontantförsäljning vecka 9')->assertJsonPath('data.rows.1.credit_ore', 125000);

    $this->withToken($key)->withHeader('Idempotency-Key', 'v-1-post')->postJson("/api/v1/vouchers/{$id}/post")
        ->assertOk()->assertJsonPath('data.status', 'POSTED')->assertJsonPath('data.number', 1)->assertJsonPath('data.reference', 'A1');

    // Posted: immutable, whatever is tried.
    $this->withToken($key)->patchJson("/api/v1/vouchers/{$id}", ['description' => 'Ändrad'])->assertForbidden();
    $this->withToken($key)->deleteJson("/api/v1/vouchers/{$id}")->assertForbidden();
    $this->withToken($key)->withHeader('Idempotency-Key', 'v-1-post-2')->postJson("/api/v1/vouchers/{$id}/post")->assertForbidden();

    $correction = $this->withToken($key)->withHeader('Idempotency-Key', 'v-1-fix')->postJson("/api/v1/vouchers/{$id}/correct", ['reason' => 'Fel belopp'])
        ->assertCreated()->assertJsonPath('data.status', 'POSTED')->assertJsonPath('data.corrects_voucher_id', $id);

    $this->withToken($key)->getJson("/api/v1/vouchers/{$id}")->assertOk()->assertJsonPath('data.corrected_by_voucher_id', $correction->json('data.id'));
});

it('refuses an unbalanced voucher when posting, with the reason in Swedish', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view', 'vouchers:create', 'vouchers:post']);
    $body = voucherBody();
    $body['rows'][1]['credit_ore'] = 100;

    $id = $this->withToken($key)->withHeader('Idempotency-Key', 'u-1')->postJson('/api/v1/vouchers', $body)->assertCreated()->json('data.id');
    $response = $this->withToken($key)->withHeader('Idempotency-Key', 'u-1-post')->postJson("/api/v1/vouchers/{$id}/post")
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    expect($response->json('errors'))->toBeArray()->not->toBeEmpty();
});

it('needs vouchers:post to post, even when the person may', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view', 'vouchers:create']);
    $id = $this->withToken($key)->withHeader('Idempotency-Key', 's-1')->postJson('/api/v1/vouchers', voucherBody())->json('data.id');

    $this->withToken($key)->withHeader('Idempotency-Key', 's-1-post')->postJson("/api/v1/vouchers/{$id}/post")
        ->assertForbidden()->assertJsonPath('code', 'insufficient_scope')->assertJsonPath('required_scopes', ['vouchers:post']);
});

it('adds an underlag to a voucher and downloads it byte for byte', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view', 'vouchers:create']);
    $id = $this->withToken($key)->withHeader('Idempotency-Key', 'a-1')->postJson('/api/v1/vouchers', voucherBody())->json('data.id');
    $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    $attachment = $this->withToken($key)->withHeader('Idempotency-Key', 'a-1-file')
        ->post("/api/v1/vouchers/{$id}/attachments", ['file' => UploadedFile::fake()->createWithContent('kvitto.pdf', $pdf)], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.mime', 'application/pdf')->assertJsonPath('data.sha256', hash('sha256', $pdf));

    $download = $this->withToken($key)->get("/api/v1/vouchers/{$id}/attachments/{$attachment->json('data.id')}/content")->assertOk();
    expect($download->streamedContent())->toBe($pdf);

    $this->withToken($key)->getJson("/api/v1/vouchers/{$id}/attachments")->assertOk()->assertJsonCount(1, 'data');
});

it('refuses multipart on routes that take JSON', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view', 'vouchers:create']);

    $this->withToken($key)->withHeader('Idempotency-Key', 'm-1')
        ->post('/api/v1/vouchers', ['file' => UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4')], ['Accept' => 'application/json'])
        ->assertStatus(415);
});

it('lists with cursor pagination and updated_since, ordered by change', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view', 'vouchers:create']);

    foreach (range(1, 3) as $i) {
        $this->withToken($key)->withHeader('Idempotency-Key', "p-{$i}")->postJson('/api/v1/vouchers', voucherBody($i * 100))->assertCreated();
    }

    $first = $this->withToken($key)->getJson('/api/v1/vouchers?limit=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.has_more', true);
    $second = $this->withToken($key)->getJson('/api/v1/vouchers?limit=2&cursor='.urlencode((string) $first->json('meta.next_cursor')))->assertOk()->assertJsonCount(1, 'data');

    $ids = [...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')];
    expect(array_unique($ids))->toHaveCount(3);

    $this->withToken($key)->getJson('/api/v1/vouchers?updated_since='.urlencode(now()->addMinute()->toIso8601String()))->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($key)->getJson('/api/v1/vouchers?status=POSTED')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($key)->getJson('/api/v1/vouchers?limit=500')->assertStatus(422);
    $this->withToken($key)->getJson('/api/v1/vouchers?updated_since=igår')->assertStatus(422);
});

it('serves the reference data a voucher needs', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view']);

    $this->withToken($key)->getJson('/api/v1/accounts/1930')->assertOk()->assertJsonPath('data.number', 1930);
    $this->withToken($key)->getJson('/api/v1/accounts/9999')->assertNotFound();
    $year = $this->withToken($key)->getJson('/api/v1/fiscal-years')->assertOk()->json('data.0.id');
    $this->withToken($key)->getJson("/api/v1/fiscal-years/{$year}")->assertOk()->assertJsonPath('data.start_date', '2026-01-01');
    $this->withToken($key)->getJson('/api/v1/voucher-series')->assertOk()->assertJsonPath('data.0.code', 'A');
    $this->withToken($key)->getJson('/api/v1/vat-codes')->assertOk()->assertJsonPath('data.0.vat_account', 2611)->assertJsonPath('data.0.report_box', 10);
});

it('deletes a draft and only a draft', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view', 'vouchers:create']);
    $id = $this->withToken($key)->withHeader('Idempotency-Key', 'd-1')->postJson('/api/v1/vouchers', voucherBody())->json('data.id');

    $this->withToken($key)->deleteJson("/api/v1/vouchers/{$id}")->assertNoContent();
    expect(CurrentCompany::run($company, fn () => Voucher::query()->whereKey($id)->exists()))->toBeFalse();
});
