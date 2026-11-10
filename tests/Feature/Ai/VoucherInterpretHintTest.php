<?php

declare(strict_types=1);

use App\Livewire\Accounting\VoucherEditor;
use App\Modules\Ai\AiModule;
use App\Modules\ModuleManager;
use App\Support\Demo\DemoMode;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Demo;

const INTERPRET_HINT = 'NONOX kan läsa underlaget och föreslå kontering när NONOX AI är påslaget.';
const INTERPRET_LINK = 'Slå på NONOX AI';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

function modulesTabUrl(): string
{
    return route('teams.show', ['company' => test()->company->id, 'flik' => 'moduler']);
}

it('tells an owner that NONOX AI can read the document, with a link that opens in a new tab', function () {
    Livewire::test(VoucherEditor::class)
        ->assertSee(INTERPRET_HINT)
        ->assertSee(INTERPRET_LINK)
        ->assertSee(modulesTabUrl())
        ->assertSee('target="_blank"', escape: false)
        ->assertSee('(öppnas i en ny flik)');
});

it('shows the sentence without the link to a bookkeeper, who may use NONOX AI but not change its settings', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    Livewire::actingAs($bookkeeper)->test(VoucherEditor::class)
        ->assertSee(INTERPRET_HINT)
        ->assertDontSee(INTERPRET_LINK)
        ->assertDontSee(modulesTabUrl());
});

it('shows nothing to a role that would not get NONOX AI, even while the module is off', function (string $role) {
    $member = Bookkeeping::member($this->company, $role);

    Livewire::actingAs($member)->test(VoucherEditor::class)
        ->assertDontSee(INTERPRET_HINT)
        ->assertDontSee(INTERPRET_LINK);
})->with(['approver', 'auditor']);

it('shows neither the sentence nor the link once NONOX AI is on', function () {
    app(ModuleManager::class)->enable(AiModule::KEY, $this->company, $this->owner);

    Livewire::test(VoucherEditor::class)
        ->assertDontSee(INTERPRET_HINT)
        ->assertDontSee(INTERPRET_LINK);
});

it('shows nothing in the demo, where NONOX AI cannot be switched on', function () {
    Demo::enable();
    [$guest, $demo] = Demo::company();

    DemoMode::onDemoConnection(function () use ($guest, $demo) {
        expect(DemoMode::moduleBlocked(AiModule::KEY))->toBeTrue();

        CurrentCompany::set($demo);
        $this->actingAs($guest);

        Livewire::test(VoucherEditor::class)
            ->assertDontSee(INTERPRET_HINT)
            ->assertDontSee(INTERPRET_LINK);
    });
});
