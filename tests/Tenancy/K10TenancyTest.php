<?php

declare(strict_types=1);

use App\Actions\IncomeTax\K10Worksheets;
use App\Domain\Company\LegalForm;
use App\Livewire\Accounting\K10Page;
use App\Models\K10Owner;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for the K10 underlag (ADR-062): company A never sees B's
 * owners or their worksheets, through Eloquent, the page, the worksheet, the
 * PDF, the SRU file or the register extract; B's ids answer 404.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Bookkeeping::company();
        $company->forceFill(['legal_form' => LegalForm::Ab])->save();
        $this->actingAs($owner);
        CurrentCompany::set($company);
        $worksheets = app(K10Worksheets::class);
        $worksheets->saveYear($owner, $company, 2026, ['shares_total' => '100', 'qualified_confirmed' => true]);
        $k10Owner = $worksheets->saveOwner($owner, $company, 2026, ['name' => "{$name} Ägaresson", 'shares_owned' => '100', 'identity' => '191212121212']);
        $this->{$side} = compact('owner', 'company', 'k10Owner');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('never shows B\'s K10 owners through Eloquent or the page', function () {
    expect(K10Owner::query()->pluck('name')->all())->toBe(['Alfa Ägaresson']);

    Livewire::test(K10Page::class)->assertSee('Alfa Ägaresson')->assertDontSee('Beta Ägaresson');
});

it('answers 404 for B\'s owner on every route and in the page', function () {
    $foreign = $this->b['k10Owner'];

    foreach (['accounting.k10.show', 'accounting.k10.pdf', 'accounting.k10.sru', 'privacy.export.k10Owner'] as $route) {
        $this->get(route($route, $foreign->id))->assertNotFound();
    }

    expect(K10Owner::query()->whereKey($foreign->id)->exists())->toBeFalse();

    Livewire::test(K10Page::class)->call('editOwner', $foreign->id)->assertNotFound();
    Livewire::test(K10Page::class)->call('deleteOwner', $foreign->id)->assertNotFound();

    expect(fn () => app(K10Worksheets::class)->saveOwner($this->a['owner'], $this->a['company'], 2026, ['name' => 'X', 'shares_owned' => '1'], $foreign->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(K10Worksheets::class)->saveOwner($this->a['owner'], $this->a['company'], 2026, ['name' => 'X', 'shares_owned' => '1', 'spouse_owner_id' => (string) $foreign->id]))->toThrow(ValidationException::class);
});
