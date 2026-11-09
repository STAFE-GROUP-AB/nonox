<?php

declare(strict_types=1);

use App\Actions\AnnualReport\K3NoteOverrides;
use App\Actions\AnnualReport\SaveK3ReportInputs;
use App\Domain\AnnualReport\K3Notes;
use App\Domain\AnnualReport\K3SizeClass;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Models\AnnualReport;
use App\Models\AnnualReportNoteOverride;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for the K3 note overrides and the K3 inputs on the annual
 * report (ADR-059, A.2.7, A.5): company A never sees B's overrides or texts,
 * through Eloquent, the notes library or the actions, and an override can
 * never point at another company's annual report, not even in the database.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));

    foreach (['a' => 'Text A', 'b' => 'Hemlig text B'] as $side => $text) {
        [$owner, $company] = Bookkeeping::company();
        $company->forceFill(['legal_form' => LegalForm::Ab])->save();
        $this->actingAs($owner);
        CurrentCompany::set($company);
        $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
        $year->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
        $override = app(K3NoteOverrides::class)->set($owner, $year->refresh(), 'average_employees', $text, 'Skäl');
        app(SaveK3ReportInputs::class)($owner, $year, ['k3_external_factors' => $text]);
        $this->{$side} = ['owner' => $owner, 'company' => $company, 'year' => $year, 'override' => $override, 'report' => AnnualReport::query()->firstOrFail()];
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('never shows B\'s overrides through Eloquent or the notes library', function () {
    expect(AnnualReportNoteOverride::query()->pluck('id')->all())->toBe([$this->a['override']->id]);

    $notes = K3Notes::for($this->a['company'], $this->a['year'], $this->a['report'], K3SizeClass::Mindre);

    expect($notes->note('average_employees')->text())->toBe(['Text A']);
});

it('answers 404 for B\'s fiscal year in both actions, not 403', function () {
    expect(fn () => app(K3NoteOverrides::class)->set($this->a['owner'], $this->b['year'], 'average_employees', 'x', 'y'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(K3NoteOverrides::class)->remove($this->a['owner'], $this->b['year'], 'average_employees'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SaveK3ReportInputs::class)($this->a['owner'], $this->b['year'], ['k3_external_factors' => 'x']))->toThrow(ModelNotFoundException::class);

    CurrentCompany::run($this->b['company'], fn () => expect(AnnualReport::query()->value('k3_external_factors'))->toBe('Hemlig text B'));
});

it('refuses an override that points at another company\'s annual report in the database', function () {
    expect(fn () => DB::table('annual_report_note_overrides')->insert([
        'company_id' => $this->a['company']->id,
        'annual_report_id' => $this->b['report']->id,
        'note_key' => 'events_after',
        'text' => 'x',
        'reason' => 'y',
        'generated_hash' => str_repeat('0', 64),
        'set_by' => $this->a['owner']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
