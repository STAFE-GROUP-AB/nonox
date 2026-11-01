<?php

declare(strict_types=1);

use App\Actions\Payables\SaveSupplier;
use App\Domain\Payables\SupplierMatcher;
use App\Models\Supplier;
use App\Support\Tenancy\CurrentCompany;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $save = app(SaveSupplier::class);
    $this->signals = $save($this->owner, $this->company, ['name' => '37signals LLC', 'payment_terms_days' => 30]);
    $this->vultr = $save($this->owner, $this->company, ['name' => 'Vultr Holdings, LLC', 'iban' => 'US12 3456 7890 1234 5678 90', 'email' => 'billing@vultr.com', 'payment_terms_days' => 30]);
    $this->grossisten = $save($this->owner, $this->company, ['name' => 'Grossisten AB', 'bankgiro' => '50501055', 'payment_terms_days' => 30]);
});

it('finds a foreign supplier by its name without legal form, case or punctuation', function () {
    expect(SupplierMatcher::find(['name' => '37signals, LLC'])?->id)->toBe($this->signals->id)
        ->and(SupplierMatcher::find(['name' => '37SIGNALS'])?->id)->toBe($this->signals->id)
        ->and(SupplierMatcher::normaliseName('Café Åkesson AB'))->toBe('cafe akesson');
});

it('prefers numbers and IBAN over the name, and the e-mail domain unless it is a free mail service', function () {
    expect(SupplierMatcher::find(['name' => 'Något helt annat', 'iban' => 'us12345678901234567890'])?->id)->toBe($this->vultr->id)
        ->and(SupplierMatcher::find(['name' => 'Okänd', 'email' => 'invoices@vultr.com'])?->id)->toBe($this->vultr->id)
        ->and(SupplierMatcher::find(['name' => 'Grossist', 'bankgiro' => '5050-1055'])?->id)->toBe($this->grossisten->id)
        ->and(SupplierMatcher::find(['name' => 'Okänd', 'email' => 'someone@gmail.com']))->toBeNull()
        ->and(SupplierMatcher::find(['name' => 'Ny leverantör AB']))->toBeNull();
});

it('never matches another company\'s supplier', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    CurrentCompany::set($other);

    expect(SupplierMatcher::find(['name' => '37signals LLC']))->toBeNull();
});

it('suggests suppliers with similar names, best first, for a person to choose', function () {
    $suppliers = Supplier::query()->get();

    expect(collect(SupplierMatcher::similar('Vultr', $suppliers))->pluck('id')->all())->toBe([$this->vultr->id])
        ->and(collect(SupplierMatcher::similar('Grosisten', $suppliers))->pluck('id')->all())->toBe([$this->grossisten->id])
        ->and(SupplierMatcher::similar('Telia', $suppliers))->toBe([]);
});
