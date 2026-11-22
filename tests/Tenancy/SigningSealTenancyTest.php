<?php

declare(strict_types=1);

use App\Actions\Signing\ManageSealCertificates;
use App\Domain\Signing\Seal\SealCertificates;
use App\Livewire\Company\SigningSealSettings;
use App\Models\Company;
use App\Models\SigningSealCertificate;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for the seal certificates (ADR-046 amendment, gap 5, S10):
 * company A never sees B's certificate, never seals with it, and finds none
 * by id (404, not 403).
 */

/** @return array{0: User, 1: Company, 2: SigningSealCertificate} */
function s10TenancyCompany(string $name): array
{
    [$owner, $company] = Bookkeeping::company();
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => "{$name} försegling"], $key, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
    openssl_pkcs12_export($certificate, $pkcs12, $key, 'lösen');

    $row = app(ManageSealCertificates::class)->upload($owner, $company, (string) $pkcs12, 'lösen');

    return [$owner, $company, $row];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::now()->addMinutes(10));
    config(['nonox.signing.seal.instance_password' => '']);
    SealCertificates::forget();
    [$this->ownerA, $this->companyA, $this->certificateA] = s10TenancyCompany('Alfa');
    [$this->ownerB, $this->companyB, $this->certificateB] = s10TenancyCompany('Beta');
    CurrentCompany::set($this->companyA);
    $this->actingAs($this->ownerA);
});

it('never shows or uses another company\'s certificate', function () {
    expect(SigningSealCertificate::query()->pluck('id')->all())->toBe([$this->certificateA->id])
        ->and(SealCertificates::forCompany($this->companyA->id)?->certificateId)->toBe($this->certificateA->id)
        ->and(CompanyScope::bypass(fn () => SigningSealCertificate::query()->count()))->toBe(2);

    Livewire::test(SigningSealSettings::class, ['company' => $this->companyA])
        ->assertSee('Alfa försegling')
        ->assertDontSee('Beta försegling');
});

it('answers 404 for another company\'s certificate id, and leaves it untouched', function () {
    expect(fn () => app(ManageSealCertificates::class)->retire($this->ownerA, $this->companyA, $this->certificateB->id))->toThrow(ModelNotFoundException::class);

    Livewire::test(SigningSealSettings::class, ['company' => $this->companyA])
        ->call('retire', $this->certificateB->id)
        ->assertNotFound();

    expect(CompanyScope::bypass(fn () => SigningSealCertificate::query()->find($this->certificateB->id)?->retired_at))->toBeNull();
});

it('refuses a member of A on B\'s settings', function () {
    Livewire::test(SigningSealSettings::class, ['company' => $this->companyB])->assertForbidden();
});
