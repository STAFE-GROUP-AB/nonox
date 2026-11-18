<?php

declare(strict_types=1);

use App\Modules\Inventory\Livewire\ForecastPage;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;

/*
 * The forecast page's number fields (ADR-081 gap 5) when a person empties them
 * or types something else.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
});

it('survives an emptied number of periods and an emptied history', function () {
    Livewire::test(ForecastPage::class)
        ->set('periods', '')
        ->assertOk()
        ->set('months', '')
        ->assertOk()
        ->assertSet('periods', '1')
        ->assertSet('months', '1');
});
