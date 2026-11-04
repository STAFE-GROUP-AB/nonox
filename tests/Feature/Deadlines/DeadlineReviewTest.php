<?php

declare(strict_types=1);

use App\Console\Commands\SendDeadlineRemindersCommand;
use App\Domain\Company\LegalForm;
use App\Domain\Company\VatPeriod;
use App\Domain\Deadlines\CompanyDeadlines;
use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineType;
use App\Domain\Deadlines\Providers\PreliminaryTaxProvider;
use App\Domain\Vat\EuTrade;
use App\Livewire\Guide\MyDates;
use App\Models\FiscalYear;
use App\Models\VatReport;
use App\Notifications\DeadlineReminder as DeadlineReminderNotice;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Mina datum after the legal recheck (REGLER.md as of 2026-11-04) and the
 * team lead's review: beskattningsår by SFL 3 kap. 5 §, yearly VAT for
 * föreningar and stiftelser, one date per period, the authority to check
 * with, reminders that stop when the period is reported, and six months by
 * default.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->set = function (array $attributes): void {
        $this->company->forceFill($attributes)->save();
        $this->company->refresh();
        app()->forgetScopedInstances();
    };
    $this->upcoming = fn (string $from, int $months = 15): array => app(CompanyDeadlines::class)->upcoming($this->company->refresh(), CarbonImmutable::parse($from), $months);
    $this->find = fn (string $from, string $key): ?Deadline => collect(($this->upcoming)($from))->firstWhere('key', $key);
});

it('maps a fiscal year that ends on another day to the beskattningsår of SFL 3 kap. 5 § for preliminary tax', function () {
    FiscalYear::query()->delete();
    FiscalYear::create(['start_date' => '2025-10-01', 'end_date' => '2026-09-30']);
    ($this->set)(['legal_form' => LegalForm::Ab, 'fiscal_year_start_month' => 10, 'f_skatt' => true]);

    $topUp = ($this->find)('2026-10-01', 'preliminary_tax_top_up:2026-12-31');
    expect($topUp)->not->toBeNull()
        ->and($topUp->date->toDateString())->toBe('2027-02-12')
        ->and($topUp->period)->toBe('2026')
        ->and(($this->find)('2026-10-01', 'preliminary_tax_top_up:2026-09-30'))->toBeNull()
        ->and(($this->find)('2026-10-01', 'preliminary_tax:2027-01')?->date->toDateString())->toBe('2027-01-18')
        ->and(($this->find)('2026-10-01', 'preliminary_tax:2027-02')?->date->toDateString())->toBe('2027-02-12');
});

it('dates yearly VAT for an ideell förening by 33 § and names the 33 b § date when it files a return', function () {
    ($this->set)(['legal_form' => LegalForm::IdeellForening, 'vat_registered' => true, 'vat_period' => VatPeriod::Yearly]);

    $vat = ($this->find)('2026-11-04', 'vat:2026-12-31');
    expect($vat->date->toDateString())->toBe('2027-02-26')
        ->and($vat->legalVerify)->toBeTrue()
        ->and($vat->appliesBecause)->toContain('17 augusti 2027');

    ($this->set)(['legal_form' => LegalForm::Stiftelse]);
    expect(($this->find)('2026-11-04', 'vat:2026-12-31')->date->toDateString())->toBe('2027-02-26');
});

it('warns that EU trade moves a yearly VAT date earlier', function () {
    ($this->set)(['legal_form' => LegalForm::Ab, 'vat_registered' => true, 'vat_period' => VatPeriod::Yearly]);

    expect(($this->find)('2026-11-04', 'vat:2026-12-31')->appliesBecause)->toContain('Handlar företaget med andra EU-länder under året blir datumet tidigare.');
});

it('lists a quarter that spans two fiscal years once', function () {
    FiscalYear::query()->delete();
    FiscalYear::create(['start_date' => '2026-05-01', 'end_date' => '2027-04-30']);
    ($this->set)(['legal_form' => LegalForm::Ab, 'fiscal_year_start_month' => 5, 'vat_registered' => true, 'vat_period' => VatPeriod::Quarterly]);

    $keys = array_map(fn (Deadline $deadline): string => $deadline->key, ($this->upcoming)('2027-05-01'));

    expect(array_count_values($keys)['vat:2027-06-30'] ?? 0)->toBe(1)
        ->and(count($keys))->toBe(count(array_unique($keys)));
});

it('counts purchases of services from outside the EU (box 22) as EU trade for the yearly VAT date', function () {
    expect(EuTrade::BOXES)->toContain(22)->not->toContain(24)->not->toContain(41);
});

it('says which authority to check with', function () {
    ($this->set)(['legal_form' => LegalForm::Ab]);

    expect(($this->find)('2027-01-01', 'annual_report:2026-12-31')->checkWith())->toBe('Bolagsverket')
        ->and(($this->find)('2027-01-01', 'preliminary_tax:2027-01')->checkWith())->toBe('Skatteverket');
});

it('asks a handelsbolag whether a legal person is a partner, with the six-month date (ÅRL 8 kap. 3 §)', function () {
    ($this->set)(['legal_form' => LegalForm::Hb]);

    $entry = ($this->find)('2027-01-01', 'annual_report:2026-12-31');
    expect($entry->title)->toBe('Kontrollera om handelsbolaget ska skicka in årsredovisningen')
        ->and($entry->date->toDateString())->toBe('2027-06-30')
        ->and($entry->isRecommendation)->toBeTrue()
        ->and($entry->legalVerify)->toBeTrue()
        ->and($entry->appliesBecause)->toContain('årsredovisningslagen 8 kap. 3 §');
});

it('moves a recommendation that falls on a weekend to the working day before', function () {
    ($this->set)(['legal_form' => LegalForm::Ef]);

    // One month before the return (3 May 2027) is Saturday 3 April → Friday 2 April.
    expect(($this->find)('2027-01-01', 'closing:2026-12-31')->date->toDateString())->toBe('2027-04-02');
});

it('times the top-up advice by SFL 65 kap. 18 §: Monday after a weekend, the day before a weekday holiday', function () {
    expect(PreliminaryTaxProvider::topUpDay(CarbonImmutable::parse('2027-06-12'))->toDateString())->toBe('2027-06-14')
        // 12 June 2026 is a Friday: unchanged.
        ->and(PreliminaryTaxProvider::topUpDay(CarbonImmutable::parse('2026-06-12'))->toDateString())->toBe('2026-06-12')
        // A weekday holiday on the 12th (annandag påsk 2047): the working day before, as interest still starts on the 13th.
        ->and(PreliminaryTaxProvider::topUpDay(CarbonImmutable::parse('2047-04-12'))->toDateString())->toBe('2047-04-11')
        // A Saturday 12th with annandag påsk on the Monday (2031): the working day before the 12th, långfredagen skipped.
        ->and(PreliminaryTaxProvider::topUpDay(CarbonImmutable::parse('2031-04-12'))->toDateString())->toBe('2031-04-10');
});

it('ends every reminder with where the date comes from', function () {
    $deadline = new Deadline('annual_report:x', DeadlineType::AnnualReport, 'Årsredovisningen', CarbonImmutable::parse('2027-07-31'), '2026', '', legalVerify: true);
    $plain = new Deadline('closing:x', DeadlineType::IncomeTaxReturn, 'Inkomstdeklaration', CarbonImmutable::parse('2027-08-02'), '2026', '');

    expect(DeadlineReminderNotice::for($this->company, $deadline, 7)->title)->toEndWith('Kontrollera datumet hos Bolagsverket.')
        ->and(DeadlineReminderNotice::for($this->company, $plain, 7)->title)->toEndWith('Beräknat från företagets inställningar.');
});

it('stops reminding of a VAT period once it is reported', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 06:50:00'));
    ($this->set)(['legal_form' => LegalForm::Ab, 'vat_registered' => true, 'vat_period' => VatPeriod::Quarterly]);
    $voucher = Bookkeeping::post($this->owner, $this->company);
    CurrentCompany::set($this->company);
    VatReport::create([
        'period_start' => '2026-07-01',
        'period_end' => '2026-09-30',
        'boxes' => [49 => 0],
        'voucher_id' => $voucher->id,
        'created_by' => $this->owner->id,
    ]);

    $this->artisan(SendDeadlineRemindersCommand::class)->assertSuccessful();

    expect($this->owner->fresh()->notifications()->pluck('data')->filter(fn ($data): bool => str_starts_with($data['deadline_key'], 'vat:'))->all())->toBe([]);

    $this->actingAs($this->owner);
    Livewire::test(MyDates::class)->assertSee('Redovisad');
});

it('shows six months by default, and all 15 with ?alla=1, without scripts', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    ($this->set)(['legal_form' => LegalForm::Ab, 'vat_registered' => true, 'vat_period' => VatPeriod::Monthly]);

    $short = (string) $this->actingAs($this->owner)->get(route('guide.dates'))->assertOk()->getContent();
    expect($short)->toContain('Visa alla datum (15 månader)')
        ->toContain(route('guide.dates', ['alla' => 1]))
        ->not->toContain('data-dates-month="2027-06"');

    $long = (string) $this->actingAs($this->owner)->get(route('guide.dates', ['alla' => 1]))->assertOk()->getContent();
    expect($long)->toContain('data-dates-month="2027-06"')->toContain('Visa de närmaste sex månaderna');
});
