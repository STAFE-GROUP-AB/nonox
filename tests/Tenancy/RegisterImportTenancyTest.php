<?php

declare(strict_types=1);

use App\Migration\Import\Actions\AnalyseRegisterImport;
use App\Migration\Import\RegisterKind;
use App\Models\Customer;
use App\Models\RegisterImport;
use App\Models\RegisterImportPreset;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\RegisterImports;

/*
 * AGENTS.md §13.2 for register_imports and register_import_presets (ADR-076):
 * B's imports, presets and imported records are invisible to A, and B's import
 * id answers 404 from A, for reading and for writing.
 */
beforeEach(function () {
    Storage::fake('local');
    [$this->ownerA, $this->a] = Bookkeeping::company();
    [$this->ownerB, $this->b] = Bookkeeping::company();

    $this->importB = RegisterImports::run($this->ownerB, $this->b, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), 'spiris', ['preset_name' => 'Bs format']);
});

it('keeps another company\'s imports, presets and customers invisible', function () {
    expect(CurrentCompany::run($this->a, fn () => RegisterImport::query()->count()))->toBe(0)
        ->and(CurrentCompany::run($this->b, fn () => RegisterImport::query()->count()))->toBe(1)
        ->and(CurrentCompany::run($this->a, fn () => RegisterImportPreset::query()->count()))->toBe(0)
        ->and(CurrentCompany::run($this->b, fn () => RegisterImportPreset::query()->count()))->toBe(1)
        ->and(CurrentCompany::run($this->a, fn () => Customer::query()->count()))->toBe(0)
        ->and(CurrentCompany::run($this->b, fn () => Customer::query()->count()))->toBe(3);
});

it('answers 404 for another company\'s import, on every page', function () {
    $this->actingAs($this->ownerA);

    $this->get(route('import.registers.show', $this->importB))->assertNotFound();
    $this->post(route('import.registers.analyse', $this->importB))->assertNotFound();
    $this->post(route('import.registers.confirm', $this->importB))->assertNotFound();
});

it('refuses analysing a finished import again, and refuses a user outside the company', function () {
    expect(fn () => app(AnalyseRegisterImport::class)($this->ownerB, $this->importB, []))->toThrow(RuntimeException::class, 'cannot be analysed again');

    // A finished import cannot be analysed again, and A's owner, who is no member of B, is refused before anything is read.
    expect(fn () => app(AnalyseRegisterImport::class)($this->ownerA, $this->importB, []))->toThrow(AuthorizationException::class);
});

it('does not offer another company\'s saved mapping as a source', function () {
    $preset = CurrentCompany::run($this->b, fn () => RegisterImportPreset::query()->sole());

    $import = RegisterImports::upload($this->ownerA, $this->a, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), "company:{$preset->id}");

    expect($import->options['mapping'])->toBe([]);
});

it('matches existing records only inside the company: the same organisation number in A is created, not skipped', function () {
    $import = RegisterImports::run($this->ownerA, $this->a, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), 'spiris');

    expect($import->result)->toMatchArray(['created' => 3, 'skipped' => 0])
        ->and(CurrentCompany::run($this->a, fn () => Customer::query()->count()))->toBe(3)
        ->and(CurrentCompany::run($this->b, fn () => Customer::query()->count()))->toBe(3);
});

it('lets the model of another company\'s import be found only through its scope', function () {
    expect(fn () => CurrentCompany::run($this->a, fn () => RegisterImport::query()->findOrFail($this->importB->id)))->toThrow(ModelNotFoundException::class);
});
