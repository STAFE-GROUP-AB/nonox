<?php

declare(strict_types=1);

use App\Livewire\Accounting\VoucherEditor;
use App\Modules\Ai\AiModule;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

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

it('tells an owner that NONOX AI can read the document, with a link to switch it on', function () {
    Livewire::test(VoucherEditor::class)
        ->assertSee(INTERPRET_HINT)
        ->assertSee(INTERPRET_LINK)
        ->assertSee(modulesTabUrl());
});

it('shows the sentence without the link to a bookkeeper, who may not change the settings', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    Livewire::actingAs($bookkeeper)->test(VoucherEditor::class)
        ->assertSee(INTERPRET_HINT)
        ->assertDontSee(INTERPRET_LINK)
        ->assertDontSee(modulesTabUrl());
});

it('shows neither the sentence nor the link once NONOX AI is on', function () {
    app(ModuleManager::class)->enable(AiModule::KEY, $this->company, $this->owner);

    Livewire::test(VoucherEditor::class)
        ->assertDontSee(INTERPRET_HINT)
        ->assertDontSee(INTERPRET_LINK);
});
