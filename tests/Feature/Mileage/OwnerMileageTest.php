<?php

declare(strict_types=1);

use App\Actions\Accounting\DeleteDraftVoucher;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Mileage\BookOwnerMileage;
use App\Actions\Mileage\LogTrip;
use App\Actions\Mileage\SaveVehicle;
use App\Domain\Company\LegalForm;
use App\Domain\Mileage\TripStatus;
use App\Livewire\Mileage\MileageLogPage;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\VehicleTrip;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Models\VoucherRow;
use App\Modules\Payroll\Actions\ImportPayrollRules;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Mileage\MileageSupport;
use Tests\Support\Bookkeeping;

/*
 * ADR-072 build step 6: the owner of an enskild firma books trips in their
 * own car (IL 16 kap. 27 §, KJ-R15, fixtures F7 and F7b). Payroll is not on;
 * the own-car amount comes from the payroll rule, which the owner checks
 * on the page.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-10 10:00:00'));
    Storage::fake('local');
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['legal_form' => LegalForm::Ef])->save();
    MileageSupport::enable($this->company);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    app(ImportPayrollRules::class)(2026);

    foreach ([BookOwnerMileage::DEFAULT_COST_ACCOUNT => 'Resekostnader', BookOwnerMileage::OWNER_DEPOSITS => 'Övriga egna insättningar'] as $number => $name) {
        Account::query()->firstOrCreate(['number' => $number], ['name' => $name, 'type' => $number < 3000 ? 'LIABILITY_EQUITY' : 'EXPENSE', 'is_active' => true]);
    }

    $this->car = app(SaveVehicle::class)($this->owner, $this->company, ['kind' => 'PRIVATE_CAR', 'label' => 'Ägarens Volvo']);
});

/** Trips for 1 240 km in May 2026 (F7). */
function ownerMayTrips(): void
{
    foreach (['600', '400', '240'] as $day => $km) {
        MileageSupport::trip(test()->owner, test()->company, test()->car, '2026-05-0'.($day + 1), $km);
    }
}

it('asks the owner to check the amount per mil against its source before booking', function () {
    ownerMayTrips();

    expect(fn () => app(BookOwnerMileage::class)($this->owner, $this->company, '2026-05'))->toThrow(ValidationException::class, 'inte kontrollerat');

    Livewire::test(MileageLogPage::class, ['month' => '2026-05'])
        ->set('month', '2026-05')
        ->assertSee('Jag har jämfört beloppet med källan')
        ->call('verifyRate', BookOwnerMileage::uncheckedRate(2026)?->id)
        ->assertDispatched('toast');

    expect(BookOwnerMileage::rateFor(2026))->toBe(2500);
});

it('drafts a voucher in series A: debit the chosen account, credit 2018, no VAT, with the trip list (F7)', function () {
    app(BookOwnerMileage::class)->verifyRate($this->owner, $this->company, BookOwnerMileage::uncheckedRate(2026));
    ownerMayTrips();

    $voucher = app(BookOwnerMileage::class)($this->owner, $this->company, '2026-05');
    $rows = $voucher->load('rows.account')->rows->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all();

    // 1 240 km = 124 mil × 25 kr = 3 100 kr.
    expect($voucher->status->value)->toBe('DRAFT')
        ->and($voucher->voucher_date->toDateString())->toBe('2026-05-31')
        ->and($voucher->series->code)->toBe('A')
        ->and($rows)->toBe([5800 => 310_000, 2018 => -310_000])
        ->and(VoucherAttachment::query()->where('voucher_id', $voucher->id)->where('mime', 'application/pdf')->count())->toBe(1)
        ->and(VehicleTrip::query()->where('voucher_id', $voucher->id)->count())->toBe(3);

    app(PostVoucher::class)($this->owner, $voucher->fresh());

    expect(VehicleTrip::query()->where('status', TripStatus::Booked->value)->count())->toBe(3);
});

it('frees the trips again when the draft is deleted', function () {
    app(BookOwnerMileage::class)->verifyRate($this->owner, $this->company, BookOwnerMileage::uncheckedRate(2026));
    ownerMayTrips();
    $voucher = app(BookOwnerMileage::class)($this->owner, $this->company, '2026-05');

    app(DeleteDraftVoucher::class)($this->owner, Voucher::query()->findOrFail($voucher->id));

    expect(VehicleTrip::query()->whereNull('voucher_id')->where('status', TripStatus::Draft->value)->count())->toBe(3);
});

it('refuses a car that is an asset of the business (F7b)', function () {
    app(BookOwnerMileage::class)->verifyRate($this->owner, $this->company, BookOwnerMileage::uncheckedRate(2026));
    app(SaveVehicle::class)($this->owner, $this->company, ['label' => 'Ägarens Volvo', 'is_business_asset' => true], $this->car);
    ownerMayTrips();

    expect(fn () => app(BookOwnerMileage::class)($this->owner, $this->company, '2026-05'))->toThrow(ValidationException::class, '16 kap. 27 §');
});

it('takes only a cost account, and never dates the draft after today', function () {
    app(BookOwnerMileage::class)->verifyRate($this->owner, $this->company, BookOwnerMileage::uncheckedRate(2026));
    MileageSupport::trip($this->owner, $this->company, $this->car, '2026-06-02', '100');

    expect(fn () => app(BookOwnerMileage::class)($this->owner, $this->company, '2026-06', 1930))->toThrow(ValidationException::class, 'mellan 4000 och 7999');

    $voucher = app(BookOwnerMileage::class)($this->owner, $this->company, '2026-06');

    expect($voucher->voucher_date->toDateString())->toBe('2026-06-10');
});

it('books a month of corrections only the other way round', function () {
    app(BookOwnerMileage::class)->verifyRate($this->owner, $this->company, BookOwnerMileage::uncheckedRate(2026));
    ownerMayTrips();
    $may = app(BookOwnerMileage::class)($this->owner, $this->company, '2026-05');
    app(PostVoucher::class)($this->owner, $may->fresh());
    app(LogTrip::class)->correct($this->owner, $this->company, VehicleTrip::query()->where('voucher_id', $may->id)->orderBy('trip_date')->firstOrFail(), 'Resan blev inte av');

    $june = app(BookOwnerMileage::class)($this->owner, $this->company, '2026-06');
    $rows = $june->load('rows.account')->rows->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all();

    // 600 km = 60 mil × 25 kr taken back.
    expect($rows)->toBe([2018 => 150_000, 5800 => -150_000]);
});

it('warns when the posted voucher has another amount than the trips give', function () {
    app(BookOwnerMileage::class)->verifyRate($this->owner, $this->company, BookOwnerMileage::uncheckedRate(2026));
    ownerMayTrips();
    $voucher = app(BookOwnerMileage::class)($this->owner, $this->company, '2026-05');
    $voucher->load('rows');
    VoucherRow::query()->where('voucher_id', $voucher->id)->where('debit_ore', '>', 0)->update(['debit_ore' => 300_000]);
    VoucherRow::query()->where('voucher_id', $voucher->id)->where('credit_ore', '>', 0)->update(['credit_ore' => 300_000]);

    app(PostVoucher::class)($this->owner, $voucher->fresh());

    expect(AuditLog::query()->where('action', 'mileage.owner_voucher_amount_differs')->exists())->toBeTrue()
        ->and(VehicleTrip::query()->where('status', TripStatus::Booked->value)->count())->toBe(3);

    Livewire::test(MileageLogPage::class)->set('month', '2026-05')->assertSee('har ett annat belopp än körjournalen ger');
});

it('is only for the owner of an enskild firma', function () {
    $admin = Bookkeeping::member($this->company, 'admin');

    expect(fn () => app(BookOwnerMileage::class)($admin, $this->company->fresh(), '2026-05'))->toThrow(AuthorizationException::class);

    $this->company->forceFill(['legal_form' => LegalForm::Ab])->save();

    expect(fn () => app(BookOwnerMileage::class)($this->owner, $this->company->fresh(), '2026-05'))->toThrow(AuthorizationException::class);
});

it('books from the page and opens the draft', function () {
    app(BookOwnerMileage::class)->verifyRate($this->owner, $this->company, BookOwnerMileage::uncheckedRate(2026));
    ownerMayTrips();

    Livewire::test(MileageLogPage::class)
        ->set('month', '2026-05')
        ->assertSee('Bokför bilresor i egen bil')
        ->call('bookMonth')
        ->assertDispatched('toast')
        ->assertRedirect();

    expect(Voucher::query()->where('status', 'DRAFT')->count())->toBe(1);
});
