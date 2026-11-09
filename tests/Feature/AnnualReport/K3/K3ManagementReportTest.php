<?php

declare(strict_types=1);

use App\Actions\AnnualReport\SaveK3ReportInputs;
use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\K3ManagementReport;
use App\Domain\AnnualReport\K3Schedule;
use App\Domain\AnnualReport\K3SizeClass;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Models\AnnualReport;
use App\Models\AuditLog;
use App\Models\CompanyAccountingPolicy;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

/*
 * S1-P9 (ADR-059 A.2.7; rule sheet S1-R8.4): the management report of a
 * mindre aktiebolag under K3. K3 3.7 adds what the books cannot tell; ÅRL 6
 * kap. 1 § "Andra stycket 3-5, tredje stycket och fjärde stycket gäller inte
 * mindre företag", so nothing from those parts is asked or printed.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
    [$owner, $company] = Bookkeeping::company();
    $company->forceFill(['legal_form' => LegalForm::Ab])->save();
    $this->actingAs($owner);
    CurrentCompany::set($company);
    $this->owner = $owner;
    $this->company = $company->refresh();
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $this->year->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    $this->save = fn (array $input, $user = null): AnnualReport => app(SaveK3ReportInputs::class)($user ?? $this->owner, $this->year->refresh(), $input);
    $this->report = fn (): K3ManagementReport => K3ManagementReport::for(
        $this->company->refresh(),
        $this->year->refresh(),
        AnnualReport::query()->where('fiscal_year_id', $this->year->id)->firstOrFail(),
        AnnualAccounts::for($this->year, K3Schedule::class),
        K3SizeClass::Mindre,
    );
});

it('S1-R8.4 stores the K3 3.7 texts and prints them in the management report', function () {
    ($this->save)([
        'k3_external_factors' => 'Räntorna har stigit.',
        'k3_special_circumstances' => 'Ingen.',
        'k3_business_changes' => 'Bolaget har börjat hyra ut lager.',
        'k3_ownership_changes' => 'Grundaren sålde 30 % till en ny delägare.',
        'k3_major_owners' => [
            ['name' => 'Fiktiva Ägarbolaget AB', 'org_number' => '556036-0793', 'votes_bp' => 5000, 'capital_bp' => 5000],
            ['name' => 'Example Holding GmbH', 'foreign_registration' => 'HRB 12345', 'country' => 'DE', 'votes_bp' => 2000, 'capital_bp' => 2000],
            ['name' => 'Anna Exempel', 'votes_bp' => 3000, 'capital_bp' => 3000],
        ],
    ]);

    $sections = collect(($this->report)()->sections())->keyBy('key');

    expect($sections['external_factors']['paragraphs'])->toBe(['Räntorna har stigit.'])
        ->and($sections['business_changes']['paragraphs'])->toBe(['Bolaget har börjat hyra ut lager.'])
        ->and($sections['ownership_changes']['paragraphs'])->toBe(['Grundaren sålde 30 % till en ny delägare.'])
        ->and($sections['major_owners']['data']['owners'])->toBe([
            ['name' => 'Fiktiva Ägarbolaget AB', 'org_number' => '556036-0793', 'foreign_registration' => null, 'country' => null, 'votes_bp' => 5000, 'capital_bp' => 5000],
            ['name' => 'Example Holding GmbH', 'org_number' => null, 'foreign_registration' => 'HRB 12345', 'country' => 'DE', 'votes_bp' => 2000, 'capital_bp' => 2000],
            ['name' => 'Anna Exempel', 'org_number' => null, 'foreign_registration' => null, 'country' => null, 'votes_bp' => 3000, 'capital_bp' => 3000],
        ])
        ->and($sections['major_owners']['rule'])->toBe('K3 3.7 e')
        ->and($sections['major_owners']['paragraphs'])->toBe([
            'Fiktiva Ägarbolaget AB: 50 % av rösterna och 50 % av kapitalet.',
            'Example Holding GmbH: 20 % av rösterna och 20 % av kapitalet.',
            'Anna Exempel: 30 % av rösterna och 30 % av kapitalet.',
        ]);
});

it('S1-R8.4 never stores a personal identity number or a birth year for an owner (GDPR, ADR-059 A.2.7)', function () {
    expect(fn () => ($this->save)(['k3_major_owners' => [['name' => 'Anna Exempel', 'org_number' => '19750101-1234', 'votes_bp' => 5000, 'capital_bp' => 5000]]]))
        ->toThrow(ValidationException::class)
        ->and(fn () => ($this->save)(['k3_major_owners' => [['name' => 'Anna Exempel', 'org_number' => '750101-1239', 'votes_bp' => 5000, 'capital_bp' => 5000]]]))
        ->toThrow(ValidationException::class)
        ->and(fn () => ($this->save)(['k3_major_owners' => [['name' => 'Anna Exempel', 'foreign_registration' => '19900101-2389', 'country' => 'NO', 'votes_bp' => 5000, 'capital_bp' => 5000]]]))
        ->toThrow(ValidationException::class)
        ->and(AnnualReport::query()->count())->toBe(0);

    ($this->save)(['k3_major_owners' => [['name' => 'Anna Exempel', 'birth_year' => 1975, 'votes_bp' => 5000, 'capital_bp' => 5000]]]);

    expect(json_encode(AnnualReport::query()->value('k3_major_owners')))->not->toContain('1975');
});

it('S1-R8.4 asks a foreign registration number for a country, and only one identifier per owner', function () {
    expect(fn () => ($this->save)(['k3_major_owners' => [['name' => 'Example GmbH', 'foreign_registration' => 'HRB 1', 'votes_bp' => 5000, 'capital_bp' => 5000]]]))
        ->toThrow(ValidationException::class)
        ->and(fn () => ($this->save)(['k3_major_owners' => [['name' => 'Example GmbH', 'foreign_registration' => 'HRB 1', 'country' => 'SE', 'votes_bp' => 5000, 'capital_bp' => 5000]]]))
        ->toThrow(ValidationException::class)
        ->and(fn () => ($this->save)(['k3_major_owners' => [['name' => 'Example AB', 'org_number' => '556036-0793', 'foreign_registration' => 'HRB 1', 'country' => 'DE', 'votes_bp' => 5000, 'capital_bp' => 5000]]]))
        ->toThrow(ValidationException::class);
});

it('S1-R8.4 takes only owners with more than ten per cent of the shares or the votes (K3 3.7 e)', function () {
    expect(fn () => ($this->save)(['k3_major_owners' => [['name' => 'Liten ägare', 'votes_bp' => 1000, 'capital_bp' => 1000]]]))
        ->toThrow(ValidationException::class);

    ($this->save)(['k3_major_owners' => [['name' => 'Röstägare', 'votes_bp' => 1001, 'capital_bp' => 500]]]);

    expect(AnnualReport::query()->value('k3_major_owners'))->toHaveCount(1);
});

it('S1-R8.4 asks about the owners until a person answers, and accepts "no owner above ten per cent"', function () {
    ($this->save)([]);

    expect(array_column(($this->report)()->questions(), 'key'))->toContain('major_owners');

    ($this->save)(['k3_major_owners' => []]);
    $report = ($this->report)();

    expect(array_column($report->questions(), 'key'))->not->toContain('major_owners')
        ->and(collect($report->sections())->firstWhere('key', 'major_owners')['paragraphs'])->toBe(['Ingen ägare har mer än tio procent av andelarna eller rösterna.']);
});

it('S1-R8.4 asks for the nature and focus of the business (K3 3.7)', function () {
    ($this->save)([]);

    expect(array_column(($this->report)()->questions(), 'key'))->toContain('business');

    AnnualReport::query()->update(['business_description' => 'Bolaget hyr ut lokaler i egen fastighet.']);

    expect(array_column(($this->report)()->questions(), 'key'))->not->toContain('business');
});

it('S1-R8.4 prints no part of ÅRL 6:1 that does not apply to a mindre företag', function () {
    ($this->save)([]);

    $keys = array_column(($this->report)()->sections(), 'key');

    foreach (K3ManagementReport::LARGER_COMPANY_ONLY as $key) {
        expect($keys)->not->toContain($key);
    }

    expect(($this->report)()->refusal())->toBeNull()
        ->and(K3ManagementReport::for($this->company, $this->year, AnnualReport::query()->firstOrFail(), AnnualAccounts::for($this->year, K3Schedule::class), K3SizeClass::Storre)->refusal())->toContain('större');
});

it('S1-R8.4 shows the multi-year overview with the framework of each year and the disposition of free equity (K3 3.7 comment, 3.14)', function () {
    $previous = FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
    $previous->forceFill(['reporting_framework' => ReportingFramework::K2])->save();
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-01', [1930 => 10_000_000, 3001 => -10_000_000]);
    ($this->save)([]);
    AnnualReport::query()->update(['dividend_ore' => 2_000_000]);

    $sections = collect(($this->report)()->sections())->keyBy('key');

    expect(array_column($sections['multi_year']['data']['rows'], 'framework'))->toBe(['K3', 'K2'])
        ->and(implode(' ', $sections['multi_year']['paragraphs']))->toContain('K2')
        ->and($sections['disposition']['data'])->toBe(['free_ore' => 10_000_000, 'dividend_ore' => 2_000_000, 'carried_ore' => 8_000_000]);
});

it('S1-R8.4 refuses a proposed dividend above the free equity and never carries a negative amount because of it (ABL 17 kap. 3 §)', function () {
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-01', [1930 => 10_000_000, 3001 => -10_000_000]);
    ($this->save)([]);
    AnnualReport::query()->update(['dividend_ore' => 12_000_000]);

    $report = ($this->report)();
    $disposition = collect($report->sections())->firstWhere('key', 'disposition')['data'];

    expect(array_column($report->questions(), 'key'))->toContain('disposition')
        ->and($disposition['carried_ore'])->toBeGreaterThanOrEqual(0);
});

it('S1-R8.4 does not print restated comparatives in a first K3 year, which NONOX does not support yet (K3 35.3A)', function () {
    $previous = FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
    $previous->forceFill(['reporting_framework' => ReportingFramework::K2])->save();
    CompanyAccountingPolicy::create(['key' => 'comparatives_restated_at_transition', 'value' => 'yes', 'applies_from' => '2026-01-01', 'set_by' => $this->owner->id]);
    ($this->save)([]);

    expect(array_column(($this->report)()->questions(), 'key'))->toContain('multi_year');
});

it('refuses to save the K3 texts for a year that does not follow K3', function () {
    $this->year->forceFill(['reporting_framework' => ReportingFramework::K2])->save();

    expect(fn () => ($this->save)(['k3_external_factors' => 'x']))->toThrow(ValidationException::class);
});

it('validates the note inputs: amounts as kronor, exceptional items with a description and an amount', function () {
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-01', [1930 => 200_000_000, 2350 => -200_000_000]);
    ($this->save)([
        'k3_long_term_liabilities' => '1 250 000,50',
        'k3_exceptional_items' => [['description' => 'Försäkringsersättning', 'amount' => '120 000']],
        'k3_off_balance_arrangements' => 'Factoring utan regress.',
        'k3_loans_to_executives' => 'Inga.',
        'k3_parent_company' => 'Fiktiva Moderbolaget AB',
        'k3_related_party_transactions' => 'Inga.',
        'k3_departures' => 'Inga.',
        'k3_goodwill_note' => 'Tio år.',
    ]);
    $report = AnnualReport::query()->firstOrFail();

    expect($report->k3_long_term_liabilities_ore)->toBe(125_000_050)
        ->and($report->k3_exceptional_items)->toEqual([['description' => 'Försäkringsersättning', 'amount_ore' => 12_000_000]]);

    expect(fn () => ($this->save)(['k3_exceptional_items' => [['description' => '', 'amount' => '1']]]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->save)(['k3_long_term_liabilities' => '-5']))->toThrow(ValidationException::class);
});

it('refuses liabilities due after five years above the long-term debt in the books (ÅRL 5 kap. 13 §)', function () {
    expect(fn () => ($this->save)(['k3_long_term_liabilities' => '1 000']))->toThrow(ValidationException::class);

    Bookkeeping::postRows($this->owner, $this->company, '2026-03-01', [1930 => 50_000_000, 2350 => -50_000_000]);

    expect(fn () => ($this->save)(['k3_long_term_liabilities' => '500 000,01']))->toThrow(ValidationException::class)
        ->and(($this->save)(['k3_long_term_liabilities' => '500 000'])->k3_long_term_liabilities_ore)->toBe(50_000_000);
});

it('stores the yes and no answers about notes, and nothing else', function () {
    ($this->save)(['k3_answers' => ['group_commitments' => false, 'material_error_corrected' => true]]);

    expect(AnnualReport::query()->firstOrFail()->k3_answers)->toEqual(['group_commitments' => false, 'material_error_corrected' => true])
        ->and(fn () => ($this->save)(['k3_answers' => ['unknown_question' => true]]))->toThrow(ValidationException::class);
});

it('keeps the K3 inputs that the call does not mention', function () {
    ($this->save)(['k3_external_factors' => 'Räntor.', 'k3_major_owners' => []]);
    ($this->save)(['k3_special_circumstances' => 'Brand i lagret.']);
    $report = AnnualReport::query()->firstOrFail();

    expect($report->k3_external_factors)->toBe('Räntor.')
        ->and($report->k3_major_owners)->toBe([])
        ->and($report->k3_special_circumstances)->toBe('Brand i lagret.');
});

it('lets the owner, an administrator and an accountant save, not a bookkeeper or an auditor', function (string $role, bool $allowed) {
    $user = Bookkeeping::member($this->company, $role);
    $call = fn () => ($this->save)(['k3_external_factors' => 'x'], $user);

    $allowed ? expect($call())->toBeInstanceOf(AnnualReport::class) : expect($call)->toThrow(AuthorizationException::class);
})->with([
    ['admin', true],
    ['accountant', true],
    ['bookkeeper', false],
    ['auditor', false],
]);

it('audits which fields changed, never their content', function () {
    ($this->save)(['k3_external_factors' => 'Hemlig faktor', 'k3_major_owners' => [['name' => 'Hemlig Ägare', 'votes_bp' => 5000, 'capital_bp' => 5000]]]);

    $log = AuditLog::query()->where('action', 'annual_report.k3_inputs_saved')->firstOrFail();

    expect($log->payload['fields'])->toBe(['k3_external_factors', 'k3_major_owners'])
        ->and(json_encode($log->payload))->not->toContain('Hemlig');
});
