<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Livewire\Invoicing\Customers;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

it('refuses a request from a page rendered for another company than the user has switched to', function () {
    [$owner, $first] = Bookkeeping::company();
    CurrentCompany::set($first);
    app(SaveCustomer::class)($owner, $first, ['name' => 'Kund i första bolaget', 'payment_terms_days' => 30]);

    $second = User::factory()->withCompany()->create()->currentCompany();
    $second->users()->attach($owner, ['role' => 'owner']);

    $this->actingAs($owner);
    $page = Livewire::test(Customers::class)->assertSee('Kund i första bolaget');

    $owner->switchCompany($second);

    $page->call('$refresh')->assertNotFound();
});

it('keeps working while the user stays in the same company', function () {
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    $this->actingAs($owner);

    Livewire::test(Customers::class)->call('$refresh')->assertOk();
});
