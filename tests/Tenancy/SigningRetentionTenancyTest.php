<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\EraseSigner;
use App\Actions\Signing\ManageSigningRetention;
use App\Actions\Signing\PruneSigningLinks;
use App\Actions\Signing\SendForSigning;
use App\Domain\Documents\LegalHoldReason;
use App\Domain\Signing\Retention\SignerRecords;
use App\Livewire\Company\SigningPrivacy;
use App\Livewire\Company\SigningRetentionSettings;
use App\Models\SalesDocument;
use App\Models\SigningEnvelope;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for retention, legal hold, the signer's register extract
 * and erasure (ADR-046 amendment, gap 11, S16): with the same signer address
 * in two companies, A's search, extract, erasure, hold and prune never see
 * or touch B's envelopes; B's company in A's requests answers 404.
 */

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Bookkeeping::company();
        CurrentCompany::set($company);
        $this->actingAs($owner);
        $customer = app(SaveCustomer::class)($owner, $company, ['name' => "{$name} Kund AB", 'email' => "inkop@{$side}.test", 'payment_terms_days' => 30]);
        $quote = app(SaveSalesDocument::class)($owner, $company, SalesDocument::QUOTE, ['customer_id' => $customer->id, 'document_date' => '2026-06-15', 'rows' => [['description' => "{$name}tjänst", 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]]);
        app(SendForSigning::class)($owner, $company, $quote, ['email' => 'samma@example.test']);
        $envelope = SigningEnvelope::query()->sole();
        $this->{$side} = compact('owner', 'company', 'quote', 'envelope');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('finds and erases only A\'s envelopes for an address both companies used', function () {
    expect(collect(SignerRecords::find('samma@example.test'))->map(fn (array $record): string => $record['envelope']->id)->all())->toBe([$this->a['envelope']->id]);

    $result = app(EraseSigner::class)($this->a['owner'], $this->a['company'], 'samma@example.test');

    expect($result['deleted'])->toBe(1)
        ->and(CompanyScope::bypass(fn (): bool => SigningEnvelope::query()->whereKey($this->b['envelope']->id)->exists()))->toBeTrue();
});

it('extracts only A\'s envelopes, and answers 404 for B\'s company', function () {
    $response = $this->post(route('privacy.export.signer'), ['e_post' => 'samma@example.test'])->assertOk();
    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());
    $json = json_decode((string) $zip->getFromName('registerutdrag.json'), true);

    expect(collect($json['signeringar'])->pluck('signering')->all())->toBe([$this->a['envelope']->id]);

    $this->post(route('privacy.export.signer'), ['e_post' => 'samma@example.test', 'foretag' => $this->b['company']->id])->assertNotFound();
});

it('answers 404 when A holds B\'s envelope, and A\'s prune never touches B', function () {
    expect(fn () => app(ManageSigningRetention::class)->hold($this->a['owner'], $this->a['company'], $this->b['envelope'], true, LegalHoldReason::Dispute))->toThrow(ModelNotFoundException::class);

    $this->travelTo(CarbonImmutable::parse('2028-01-01 10:00:00'));
    app(PruneSigningLinks::class)->run(CarbonImmutable::now());

    expect(CompanyScope::bypass(fn (): bool => SigningEnvelope::query()->whereKey($this->b['envelope']->id)->exists()))->toBeTrue();
});

it('refuses A\'s owner the settings and privacy components of B', function () {
    Livewire::test(SigningRetentionSettings::class, ['company' => $this->b['company']])->assertForbidden();
    Livewire::test(SigningPrivacy::class, ['company' => $this->b['company']])->assertForbidden();
});
