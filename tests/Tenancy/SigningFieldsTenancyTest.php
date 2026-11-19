<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\SendForSigning;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Mail\SigningRequestMail;
use App\Models\SalesDocument;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\SigningConsentFixture;

/*
 * AGENTS.md §13.2 for fields in the PDF (ADR-046 amendment, gap 2, S6):
 * company A never sees B's fields or values; a link from B's envelope shows
 * B's fields and pages only, found by its token alone; a field of B cannot
 * be placed on A's envelope.
 */

function s6TenancyLink(string $email): string
{
    $url = null;
    Mail::assertQueued(SigningRequestMail::class, function (SigningRequestMail $mail) use ($email, &$url): bool {
        if ($mail->hasTo($email)) {
            $url = $mail->url;
        }

        return true;
    });

    return (string) parse_url((string) $url, PHP_URL_PATH);
}

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    FakePdfEngine::install();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Bookkeeping::company();
        CurrentCompany::set($company);
        $this->actingAs($owner);
        $customer = Crm::customer($company, $owner, "{$name} Kund AB");
        $quote = app(SaveSalesDocument::class)($owner, $company, SalesDocument::QUOTE, ['customer_id' => $customer->id, 'document_date' => '2026-06-15', 'rows' => [['description' => "{$name}tjänst", 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]]);
        $envelope = app(SendForSigning::class)->envelope($owner, $company, $quote, [
            'signers' => [['email' => "first@{$side}.test", 'step' => 1], ['email' => "second@{$side}.test", 'step' => 1]],
            'fields' => [
                ['signer' => 0, 'type' => 'TEXT', 'page' => 1, 'x' => 5000, 'y' => 5000, 'width' => 20000, 'height' => 2000, 'label' => "{$name}fält"],
                ['signer' => 1, 'type' => 'TEXT', 'page' => 1, 'x' => 5000, 'y' => 9000, 'width' => 20000, 'height' => 2000, 'label' => "{$name}fält två"],
            ],
        ]);
        $this->{$side} = compact('owner', 'company', 'quote', 'envelope');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('never shows B\'s fields or values through Eloquent', function () {
    expect(SigningField::query()->pluck('label')->sort()->values()->all())->toBe(['Alfafält', 'Alfafält två'])
        ->and(SigningField::query()->whereIn('envelope_id', [$this->b['envelope']->id])->count())->toBe(0)
        ->and(SigningFieldValue::query()->count())->toBe(0);
});

it('opens a link from B\'s envelope with B\'s fields and pages only, whoever is signed in', function () {
    $path = s6TenancyLink('first@b.test');

    SigningConsentFixture::token(basename($path));
    $this->get($path)->assertOk()->assertSee('Betafält')->assertDontSee('Alfafält');
    $this->get("{$path}/sida/1")->assertOk();
});

it('refuses to place B\'s signer on A\'s envelope', function () {
    $bSigner = CurrentCompany::run($this->b['company'], fn () => $this->b['envelope']->signers()->first());

    expect(fn () => SigningField::create([
        'envelope_id' => $this->a['envelope']->id, 'signing_request_id' => $bSigner->id, 'type' => 'TEXT', 'page' => 1,
        'x' => 0, 'y' => 0, 'width' => 1000, 'height' => 1000, 'page_width' => 59528, 'page_height' => 84189, 'label' => 'Fel', 'position' => 9,
    ]))->toThrow(QueryException::class);
});
