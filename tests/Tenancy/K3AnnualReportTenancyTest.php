<?php

declare(strict_types=1);

use App\Actions\AnnualReport\SaveK3ReportInputs;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Livewire\Accounting\AnnualReportPage;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for the K3 annual report (ADR-059 A.5, S1-P10): the preview
 * and the PDF of another company's K3 year answer 404, never 403, and the
 * Årsredovisning page never shows another company's texts.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));

    foreach (['a' => 'Text A', 'b' => 'Hemlig text B'] as $side => $text) {
        [$owner, $company] = Bookkeeping::company();
        $company->forceFill(['legal_form' => LegalForm::Ab, 'reporting_framework' => ReportingFramework::K3])->save();
        $this->actingAs($owner);
        CurrentCompany::set($company);
        $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
        $year->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
        app(SaveK3ReportInputs::class)($owner, $year->refresh(), ['k3_external_factors' => $text]);
        $this->{$side} = ['owner' => $owner, 'company' => $company, 'year' => $year];
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('answers 404 for the preview and the PDF of B\'s K3 year, not 403', function () {
    $this->get(route('accounting.annualReport.show', $this->b['year']->id))->assertNotFound();
    $this->get(route('accounting.annualReport.pdf', $this->b['year']->id))->assertNotFound();
    $this->get(route('accounting.annualReport.ixbrl', $this->b['year']->id))->assertNotFound();
});

it('never opens B\'s year or texts on the Årsredovisning page', function () {
    Livewire::test(AnnualReportPage::class, ['year' => $this->b['year']->id])
        ->assertSet('year', $this->a['year']->id)
        ->assertSet('k3Form.k3_external_factors', 'Text A')
        ->assertDontSee('Hemlig text B');
});
