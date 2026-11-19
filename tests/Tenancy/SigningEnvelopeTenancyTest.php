<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\SendForSigning;
use App\Domain\Signing\SigningToken;
use App\Livewire\Signing\SigningPanel;
use App\Mail\SigningRequestMail;
use App\Models\SalesDocument;
use App\Models\SigningCopyRecipient;
use App\Models\SigningDownloadLink;
use App\Models\SigningEnvelope;
use App\Models\SigningLinkToken;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * AGENTS.md §13.2 for envelopes (ADR-046 amendment, gap 1, S1): company A
 * never sees B's envelopes, link tokens or copy recipients; withdrawing B's
 * envelope from A's panel answers 404; a link from B's envelope opens B's
 * document only, found by its token alone.
 */

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Bookkeeping::company();
        CurrentCompany::set($company);
        $this->actingAs($owner);
        Crm::enable($company, $owner);
        $customer = Crm::customer($company, $owner, "{$name} Kund AB");
        $quote = app(SaveSalesDocument::class)($owner, $company, SalesDocument::QUOTE, ['customer_id' => $customer->id, 'document_date' => '2026-06-15', 'rows' => [['description' => "{$name}tjänst", 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]]);
        $envelope = app(SendForSigning::class)->envelope($owner, $company, $quote, [
            'signers' => [['email' => "first@{$side}.test", 'step' => 1], ['email' => "second@{$side}.test", 'step' => 2]],
            'copies' => [['email' => "copy@{$side}.test"]],
        ]);
        $this->{$side} = compact('owner', 'company', 'quote', 'envelope');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('never shows B\'s envelopes, link tokens or copy recipients through Eloquent', function () {
    expect(SigningEnvelope::query()->pluck('id')->all())->toBe([$this->a['envelope']->id])
        ->and(SigningCopyRecipient::query()->pluck('email')->all())->toBe(['copy@a.test'])
        ->and(SigningLinkToken::query()->count())->toBe(1)
        ->and(SigningEnvelope::query()->find($this->b['envelope']->id))->toBeNull();
});

it('answers 404 when A withdraws B\'s envelope from its own panel', function () {
    Livewire::test(SigningPanel::class, ['quoteId' => $this->a['quote']->id])->call('voidEnvelope', $this->b['envelope']->id)->assertNotFound();

    CurrentCompany::set($this->b['company']);
    expect(SigningEnvelope::query()->findOrFail($this->b['envelope']->id)->status->value)->toBe('OUT');
});

it('opens a link from B\'s envelope with B\'s document only, whoever is signed in', function () {
    $url = null;
    Mail::assertQueued(SigningRequestMail::class, function (SigningRequestMail $mail) use (&$url): bool {
        if ($mail->hasTo('first@b.test')) {
            $url = $mail->url;
        }

        return true;
    });

    $this->get((string) parse_url((string) $url, PHP_URL_PATH))->assertOk()->assertSee('Betatjänst')->assertDontSee('Alfa');
});

it('never shows B\'s download links (S13) through Eloquent, and a link opens its own company\'s files only, found by its token alone', function () {
    $tokens = [];

    foreach (['a', 'b'] as $side) {
        $company = $this->{$side}['company'];
        $envelope = $this->{$side}['envelope'];
        CurrentCompany::set($company);
        $token = SigningToken::generate();
        $tokens[$side] = $token['token'];
        SigningDownloadLink::create(['company_id' => $company->id, 'envelope_id' => $envelope->id, 'kind' => SigningDownloadLink::COPY, 'email' => "copy@{$side}.test", 'language' => 'sv', 'token_hash' => $token['hash'], 'expires_at' => now()->addDays(30)]);
    }

    CurrentCompany::set($this->a['company']);
    expect(SigningDownloadLink::query()->pluck('email')->all())->toBe(['copy@a.test']);

    // Signed in as A, B's token still opens B's files and nobody else's.
    foreach (['a' => 'b', 'b' => 'a'] as $own => $other) {
        $page = $this->get(route('signing.copy', ['token' => $tokens[$own]]))->assertOk();
        $page->assertSee(e($this->{$own}['envelope']->title), false)->assertSee($this->{$own}['envelope']->document_name);

        if ($this->{$own}['envelope']->title !== $this->{$other}['envelope']->title) {
            $page->assertDontSee(e($this->{$other}['envelope']->title), false);
        }
    }
});
