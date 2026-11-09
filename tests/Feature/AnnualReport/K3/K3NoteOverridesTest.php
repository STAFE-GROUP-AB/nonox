<?php

declare(strict_types=1);

use App\Actions\AnnualReport\K3NoteOverrides;
use App\Actions\AnnualReport\SetAccountingPolicy;
use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\AnnualReport\K3Notes;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Domain\AnnualReport\K3SizeClass;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Models\AnnualReport;
use App\Models\AnnualReportNoteOverride;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

/*
 * S1-P9 (ADR-059 A.2.7; spec S1 Behaviour 6): a person may write a note in
 * their own words. The override is stored, printed instead of the generated
 * text and marked; the structured data never changes.
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

    foreach (['financial_instruments' => 'chapter_11', 'intangibles' => 'expense', 'leasing' => 'operational_in_legal_entity', 'borrowing_costs' => 'expense', 'fixed_price_contracts' => 'none', 'cash_flow_statement' => 'none', 'components' => 'buildings_always_others_by_choice'] as $key => $value) {
        app(SetAccountingPolicy::class)($owner, $this->company, $this->year->id, K3PolicyKey::from($key), $value);
    }

    $this->notes = fn (): K3Notes => K3Notes::for($this->company->refresh(), $this->year->refresh(), AnnualReport::query()->firstOrNew(['fiscal_year_id' => $this->year->id]), K3SizeClass::Mindre);
    $this->overrides = app(K3NoteOverrides::class);
});

it('stores an override, prints it instead of the generated text and marks the note', function () {
    $override = $this->overrides->set($this->owner, $this->year, 'average_employees', "Medelantalet anställda har varit två.\n\nSamtliga är kvinnor.", 'Egen formulering');

    $note = ($this->notes)()->note('average_employees');

    expect($override)->toBeInstanceOf(AnnualReportNoteOverride::class)
        ->and($note->overridden())->toBeTrue()
        ->and($note->overrideReason)->toBe('Egen formulering')
        ->and($note->text())->toBe(['Medelantalet anställda har varit två.', 'Samtliga är kvinnor.'])
        ->and($note->generatedText)->not->toBe($note->text())
        ->and($note->stale)->toBeFalse();
});

it('changes the wording only: the data and the generated text stay as they were', function () {
    $before = ($this->notes)()->note('accounting_principles');
    $this->overrides->set($this->owner, $this->year, 'accounting_principles', 'Egen text om principerna.', 'Revisorns önskemål');
    $after = ($this->notes)()->note('accounting_principles');

    expect($after->data)->toBe($before->data)
        ->and($after->generatedText)->toBe($before->generatedText)
        ->and($after->number)->toBe($before->number)
        ->and($after->text())->toBe(['Egen text om principerna.']);
});

it('marks an override as out of date when the generated text changes after it was written', function () {
    $this->overrides->set($this->owner, $this->year, 'average_employees', 'Två anställda.', 'Egen formulering');
    AnnualReport::query()->where('fiscal_year_id', $this->year->id)->update(['average_employees' => 5]);

    $note = ($this->notes)()->note('average_employees');

    expect($note->stale)->toBeTrue()
        ->and($note->data['average_employees'])->toBe(5)
        ->and($note->text())->toBe(['Två anställda.']);
});

it('never prints an override of a note whose trigger does not hold', function () {
    $this->overrides->set($this->owner, $this->year, 'events_after', 'Inget har hänt.', 'Test');

    expect(($this->notes)()->note('events_after'))->toBeNull();
});

it('resolves the questions of a note once a person has written it in their own words', function () {
    AnnualReport::query()->where('fiscal_year_id', $this->year->id)->firstOr(fn () => AnnualReport::create(['fiscal_year_id' => $this->year->id, 'signatories' => [], 'updated_by' => $this->owner->id]))
        ->forceFill(['contingent_liabilities_text' => 'Borgen'])->save();

    expect(array_column(($this->notes)()->questions(), 'key'))->toContain('contingent_liabilities');

    $this->overrides->set($this->owner, $this->year, 'contingent_liabilities', 'Borgen för leverantörens lån. Den finansiella effekten kan inte uppskattas.', 'Effekten går inte att beräkna');

    expect(array_column(($this->notes)()->questions(), 'key'))->not->toContain('contingent_liabilities');
});

it('replaces an earlier override and removes it on request', function () {
    $this->overrides->set($this->owner, $this->year, 'average_employees', 'Första.', 'Ett');
    $this->overrides->set($this->owner, $this->year, 'average_employees', 'Andra.', 'Två');

    expect(AnnualReportNoteOverride::query()->count())->toBe(1)
        ->and(($this->notes)()->note('average_employees')->text())->toBe(['Andra.']);

    $this->overrides->remove($this->owner, $this->year, 'average_employees');

    expect(AnnualReportNoteOverride::query()->count())->toBe(0)
        ->and(($this->notes)()->note('average_employees')->overridden())->toBeFalse();
});

it('refuses an unknown note key, an empty text and a missing reason', function () {
    expect(fn () => $this->overrides->set($this->owner, $this->year, 'no_such_note', 'Text', 'Skäl'))->toThrow(ValidationException::class)
        ->and(fn () => $this->overrides->set($this->owner, $this->year, 'average_employees', '  ', 'Skäl'))->toThrow(ValidationException::class)
        ->and(fn () => $this->overrides->set($this->owner, $this->year, 'average_employees', 'Text', ''))->toThrow(ValidationException::class)
        ->and(AnnualReportNoteOverride::query()->count())->toBe(0);
});

it('accepts the key of a fixed asset note for every K3 fixed asset line', function () {
    $this->overrides->set($this->owner, $this->year, 'fixed_assets.buildings', 'Byggnader.', 'Test');

    expect(AnnualReportNoteOverride::query()->value('note_key'))->toBe('fixed_assets.buildings');
});

it('refuses a fiscal year that does not follow K3', function () {
    $this->year->forceFill(['reporting_framework' => ReportingFramework::K2])->save();

    expect(fn () => $this->overrides->set($this->owner, $this->year->refresh(), 'average_employees', 'Text', 'Skäl'))->toThrow(ValidationException::class);
});

it('refuses once the annual report is adopted in a closed year', function () {
    $this->overrides->set($this->owner, $this->year, 'average_employees', 'Text', 'Skäl');
    AnnualReport::query()->where('fiscal_year_id', $this->year->id)->update(['adoption_date' => '2027-01-20']);
    $this->year->forceFill(['status' => FiscalYearStatus::Closed, 'closed_at' => now()])->save();

    expect(fn () => $this->overrides->set($this->owner, $this->year->refresh(), 'average_employees', 'Ny text', 'Skäl'))->toThrow(ValidationException::class)
        ->and(fn () => $this->overrides->remove($this->owner, $this->year->refresh(), 'average_employees'))->toThrow(ValidationException::class);
});

it('lets the owner, an administrator and an accountant write an override, not a bookkeeper or an auditor', function (string $role, bool $allowed) {
    $user = Bookkeeping::member($this->company, $role);
    $call = fn () => $this->overrides->set($user, $this->year, 'average_employees', 'Text', 'Skäl');

    $allowed ? expect($call())->toBeInstanceOf(AnnualReportNoteOverride::class) : expect($call)->toThrow(AuthorizationException::class);
})->with([
    ['admin', true],
    ['accountant', true],
    ['bookkeeper', false],
    ['approver', false],
    ['auditor', false],
]);

it('writes the audit log with ids and the note key, never the text or the reason', function () {
    $this->overrides->set($this->owner, $this->year, 'average_employees', 'Hemlig formulering', 'Hemligt skäl');
    $this->overrides->remove($this->owner, $this->year, 'average_employees');

    $logs = AuditLog::query()->whereIn('action', ['annual_report.note_override_set', 'annual_report.note_override_removed'])->orderBy('id')->get();

    expect($logs->pluck('action')->all())->toBe(['annual_report.note_override_set', 'annual_report.note_override_removed'])
        ->and($logs->first()->payload['note_key'])->toBe('average_employees')
        ->and(json_encode($logs->pluck('payload')->all()))->not->toContain('Hemlig');
});
