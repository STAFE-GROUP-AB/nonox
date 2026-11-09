<?php

declare(strict_types=1);

use App\Domain\Company\CompanyRole;
use App\Migration\Import\RegisterKind;
use App\Models\Customer;
use App\Models\RegisterImport;
use App\Support\Tenancy\CurrentCompany;
use Tests\Support\Bookkeeping;
use Tests\Support\RegisterImports;

/*
 * ADR-076: the pages of "Läs in register", the permission import:registers per
 * role, and a company only ever seeing its own imports.
 */
beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

it('uploads, maps, reviews and reads in a file with a Swedish toast at every step', function () {
    $this->get(route('import.registers.index'))->assertOk()->assertSee('Läs in register')->assertSee('Ladda upp en fil');

    $response = $this->post(route('import.registers.store'), [
        'kind' => 'customers',
        'source' => 'spiris',
        'file' => RegisterImports::fixture('kunder-spiris-lik.csv'),
    ]);

    $import = RegisterImport::query()->sole();
    $response->assertRedirect(route('import.registers.show', $import));
    expect(session('toasts')[0]['message'])->toBe('Filen är uppladdad. Koppla kolumnerna och granska filen.');

    $this->get(route('import.registers.show', $import))->assertOk()
        ->assertSee('Koppla kolumnerna')
        ->assertSee('kunder-spiris-lik.csv');

    $this->post(route('import.registers.analyse', $import), [
        'mapping' => $import->options['mapping'],
        'reader' => ['encoding' => 'auto', 'delimiter' => 'auto'],
        'preset_name' => 'Mitt spiris',
    ])->assertRedirect(route('import.registers.show', $import));
    expect(session('toasts')[0]['message'])->toBe('Filen är granskad. Läs igenom rapporten och läs sedan in den.');

    $this->get(route('import.registers.show', $import))->assertOk()
        ->assertSee('Rapport')
        ->assertSee('Exempelbolaget Norr AB')
        ->assertSee('Läggs till')
        ->assertSee('Utesluta')
        ->assertSee('Fel, hoppas över')
        ->assertSee('Läs in filen')
        ->assertSee('Ja, läs in 3 rader');

    $this->post(route('import.registers.confirm', $import))->assertRedirect(route('import.registers.show', $import));
    expect(session('toasts')[0]['message'])->toBe('Inläsningen är klar.')
        ->and(CurrentCompany::run($this->company, fn () => Customer::query()->count()))->toBe(3);

    $this->get(route('import.registers.show', $import))->assertOk()->assertSee('Resultat')->assertDontSee('Ja, läs in');
    $this->get(route('import.registers.index'))->assertSee('kunder-spiris-lik.csv')->assertSee('Inläst')->assertSee('Mitt format: Mitt spiris');
});

it('shows the previous program\'s number on the customer card as "Nummer i tidigare program"', function () {
    RegisterImports::run($this->owner, $this->company, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), 'spiris');
    $customer = CurrentCompany::run($this->company, fn () => Customer::query()->where('external_ref', '1001')->firstOrFail());

    expect(file_get_contents(base_path('resources/views/livewire/invoicing/customers.blade.php')))->toContain('Nummer i tidigare program')
        ->and(file_get_contents(base_path('resources/views/livewire/payables/suppliers.blade.php')))->toContain('Nummer i tidigare program')
        ->and(file_get_contents(base_path('resources/views/livewire/invoicing/articles.blade.php')))->toContain('Nummer i tidigare program');
    expect($customer->external_ref)->toBe('1001');
});

it('says why a file cannot be confirmed and does not read it in', function () {
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), 'spiris');
    RegisterImports::analyse($this->owner, $import, ['mapping' => ['city' => 'InvoiceCity']]);

    $this->get(route('import.registers.show', $import))->assertSee('Filen kan inte läsas in som den är')->assertSee('Välj vilken kolumn som är');
    $this->post(route('import.registers.confirm', $import))->assertRedirect();
    expect(session('toasts')[0]['type'])->toBe('error')
        ->and(CurrentCompany::run($this->company, fn () => Customer::query()->count()))->toBe(0);
});

it('asks for a kind and a file', function () {
    $this->post(route('import.registers.store'), ['kind' => 'nonsense'])->assertSessionHasErrors('kind');
    $this->post(route('import.registers.store'), ['kind' => 'customers', 'source' => 'nonox'])->assertSessionHasErrors('file');
});

it('lends out a template with the NONOX field names as the header', function () {
    $response = $this->get(route('import.registers.template', 'customers'))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('mall-customers.csv')
        ->and($response->getContent())->toContain('Namn;Privatperson;Organisationsnummer');
    $this->get(route('import.registers.template', 'attachments'))->assertNotFound();
});

it('lets only owner, administrator and accounting consultant read registers in', function (string $role, bool $allowed) {
    $member = Bookkeeping::member($this->company, $role);
    $this->actingAs($member);
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), 'spiris');

    $this->get(route('import.registers.index'))->assertStatus($allowed ? 200 : 403);
    $this->get(route('import.registers.show', $import))->assertStatus($allowed ? 200 : 403);
    $this->post(route('import.registers.store'), ['kind' => 'customers', 'source' => 'nonox', 'file' => RegisterImports::contents("Namn\nA AB\n")])->assertStatus($allowed ? 302 : 403);
    $this->post(route('import.registers.analyse', $import), [])->assertStatus($allowed ? 302 : 403);
    $this->post(route('import.registers.confirm', $import))->assertStatus($allowed ? 302 : 403);
})->with([
    'admin' => ['admin', true],
    'accountant' => ['accountant', true],
    'bookkeeper' => ['bookkeeper', false],
    'approver' => ['approver', false],
    'auditor' => ['auditor', false],
]);

it('shows the link in the menu and the search only to those who may use it', function () {
    expect(CompanyRole::PERMISSIONS)->toContain('import:registers')
        ->and($this->owner->hasTeamPermission($this->company, 'import:registers'))->toBeTrue()
        ->and(Bookkeeping::member($this->company, 'bookkeeper')->hasTeamPermission($this->company, 'import:registers'))->toBeFalse();
});
