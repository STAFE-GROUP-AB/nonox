<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateArchivePackage;
use App\Domain\Company\CompanyOverview;
use App\Models\FiscalYear;
use App\Modules\Payroll\Actions\AgiSubmissions;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\AgiPage;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Support\Agi\AgiDeadlines;
use App\Modules\Payroll\Support\Agi\AgiXml;
use App\Modules\Payroll\Support\Insights\PayrollKpis;
use App\Support\Archive\ArchivePackageBuilder;
use App\Support\Luhn;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    Storage::fake('local');
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['phone' => '08-123 456 78'])->save();
    Payroll::setUp($this->company, $this->owner);

    foreach (['agi.schema_version' => ['version' => '1.1'], 'agi.deadlines' => ['turnover_up_to_40_msek' => ['2026-01-19', '2026-05-12'], 'turnover_over_40_msek_declare_day' => 26]] as $key => $value) {
        PayrollRuleVersion::create(['key' => $key, 'valid_from' => '2026-01-01', 'value' => $value, 'source_url' => 'https://example.test'])
            ->forceFill(['verified_by' => 'Testperson', 'verified_at' => now()])->save();
    }

    $this->admin = Bookkeeping::member($this->company, 'admin');
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->anna = Payroll::employee($this->owner, $this->company);
    $this->runs = app(PayrollRuns::class);
    $this->run = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->calculate($this->owner, $this->run);
    $this->agi = app(AgiSubmissions::class);
});

function agiXml(AgiSubmission $submission): SimpleXMLElement
{
    $xml = simplexml_load_string((string) Storage::disk('local')->get($submission->xml_path));
    $xml->registerXPathNamespace('agd', AgiXml::COMPONENT_NS);

    return $xml;
}

it('declares the approved runs paid in the month, in Skatteverket\'s format', function () {
    $this->runs->approve($this->admin, $this->run->fresh());
    $submission = $this->agi->generate($this->owner, $this->company, '2026-04');
    $xml = agiXml($submission);
    $payslip = Payslip::query()->sole();
    $field = fn (string $path): string => (string) ($xml->xpath($path)[0] ?? '');
    $orgNumber = (string) preg_replace('/\D/', '', $this->company->org_number);

    expect($submission->sequence)->toBe(1)
        ->and($submission->status)->toBe(AgiSubmission::GENERATED)
        ->and($submission->sha256)->toBe(hash('sha256', (string) Storage::disk('local')->get($submission->xml_path)))
        ->and($field('//agd:HU/agd:ArbetsgivareHUGROUP/agd:AgRegistreradId'))->toBe('16'.$orgNumber)
        ->and($field('//agd:HU/agd:RedovisningsPeriod'))->toBe('202604')
        ->and($field('//agd:HU/agd:SummaArbAvgSlf[@faltkod="487"]'))->toBe((string) intdiv($payslip->employer_contribution_ore->ore, 100))
        ->and($field('//agd:HU/agd:SummaSkatteavdr[@faltkod="497"]'))->toBe((string) intdiv($payslip->tax_ore->ore, 100))
        ->and($field('//agd:IU//agd:BetalningsmottagarId[@faltkod="215"]'))->toBe(preg_replace('/\D/', '', $this->anna->personal_number))
        ->and($field('//agd:IU/agd:Specifikationsnummer[@faltkod="570"]'))->toBe((string) $this->anna->employee_number)
        ->and($field('//agd:IU/agd:KontantErsattningUlagAG[@faltkod="011"]'))->toBe((string) intdiv($payslip->gross_ore->ore, 100))
        ->and($field('//agd:IU/agd:AvdrPrelSkatt[@faltkod="001"]'))->toBe((string) intdiv($payslip->tax_ore->ore, 100))
        ->and($submission->individuals)->toHaveKey((string) $this->anna->id);
});

it('follows Skatteverket\'s XML schema', function () {
    $directory = storage_path('app/skatteverket-agi');

    if (! is_file("{$directory}/arbetsgivardeklaration_1.1.xsd")) {
        $this->markTestSkipped('Skatteverket\'s schema files are not installed in storage/app/skatteverket-agi.');
    }

    config(['services.skatteverket.agi_schema_dir' => $directory]);
    $this->runs->approve($this->admin, $this->run->fresh());
    $submission = $this->agi->generate($this->owner, $this->company, '2026-04');

    expect(AgiXml::validate((string) Storage::disk('local')->get($submission->xml_path), $directory))->toBe([]);
});

it('waits until every run paid in the month is approved', function () {
    expect(fn () => $this->agi->generate($this->owner, $this->company, '2026-04'))->toThrow(ValidationException::class, 'inte attesterad')
        ->and(fn () => $this->agi->generate($this->owner, $this->company, '2026-03'))->toThrow(ValidationException::class, 'Inga löner är utbetalda');
});

it('needs the company\'s phone number and the declaration permission', function () {
    $this->runs->approve($this->admin, $this->run->fresh());
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(fn () => $this->agi->generate($bookkeeper, $this->company, '2026-04'))->toThrow(AuthorizationException::class);

    $this->company->forceFill(['phone' => null])->save();

    expect(fn () => $this->agi->generate($this->owner, $this->company->fresh(), '2026-04'))->toThrow(ValidationException::class, 'telefonnummer');
});

it('replaces an unsubmitted file and corrects a submitted one with the same specification numbers', function () {
    $this->runs->approve($this->admin, $this->run->fresh());
    $first = $this->agi->generate($this->owner, $this->company, '2026-04');
    $again = $this->agi->generate($this->owner, $this->company, '2026-04');

    expect($again->id)->toBe($first->id)->and(AgiSubmission::query()->count())->toBe(1);

    $tenDigits = '850101123';
    $bertil = Payroll::employee($this->owner, $this->company, ['first_name' => 'Bertil', 'last_name' => 'Disk', 'personal_number' => '19'.$tenDigits.Luhn::checkDigit($tenDigits)]);
    // Bertil was in the file Skatteverket has, but no run in the month pays him any longer.
    $again->forceFill(['individuals' => $again->individuals + [(string) $bertil->id => ['specification' => 7]]])->save();

    $submitted = $this->agi->submitted($this->owner, $again->fresh(), 'KV-123');
    $correction = $this->agi->generate($this->owner, $this->company, '2026-04');
    $xml = agiXml($correction);
    $removal = $xml->xpath('//agd:IU[agd:Borttag]')[0];
    $removal->registerXPathNamespace('agd', AgiXml::COMPONENT_NS);

    expect($submitted->receipt_reference)->toBe('KV-123')
        ->and(PayrollRun::query()->sole()->agi_submission_id)->toBe($submitted->id)
        ->and($correction->sequence)->toBe(2)
        ->and($correction->removals)->toBe(1)
        ->and((string) $removal->xpath('agd:Specifikationsnummer')[0])->toBe('7')
        ->and((string) $removal->xpath('.//agd:BetalningsmottagarId')[0])->toBe('19'.$tenDigits.Luhn::checkDigit($tenDigits))
        ->and((string) $xml->xpath('//agd:IU[not(agd:Borttag)]/agd:Specifikationsnummer')[0])->toBe((string) $this->anna->employee_number);

    $this->agi->submitted($this->owner, $correction, null);
    $this->agi->decided($this->owner, $correction->fresh(), true);

    expect($submitted->fresh()->status)->toBe(AgiSubmission::CORRECTED)
        ->and($correction->fresh()->status)->toBe(AgiSubmission::ACCEPTED)
        ->and(fn () => $this->agi->decided($this->owner, $correction->fresh(), false))->toThrow(ValidationException::class);
});

it('knows the declaration day from the rules', function () {
    expect(AgiDeadlines::for('2026-04', AgiDeadlines::SMALL)?->toDateString())->toBe('2026-05-12')
        ->and(AgiDeadlines::for('2025-12', AgiDeadlines::SMALL)?->toDateString())->toBe('2026-01-19')
        ->and(AgiDeadlines::for('2026-04', AgiDeadlines::LARGE)?->toDateString())->toBe('2026-05-26')
        ->and(AgiDeadlines::for('2026-06', AgiDeadlines::SMALL))->toBeNull();
});

it('lets the declarer download the file and keeps it from other companies', function () {
    $this->runs->approve($this->admin, $this->run->fresh());
    $submission = $this->agi->generate($this->owner, $this->company, '2026-04');

    $this->get(route('payroll.agi.file', $submission))->assertOk()->assertDownload('Arbetsgivardeklaration-2026-04.xml');

    [$stranger] = Bookkeeping::company();
    $this->actingAs($stranger)->get(route('payroll.agi.file', $submission))->assertNotFound();
});

it('shows the months with their declaration day and creates the file from the page', function () {
    $this->runs->approve($this->admin, $this->run->fresh());

    Livewire::test(AgiPage::class)
        ->assertSee('2026-05-12')
        ->assertSee('Skapa arbetsgivardeklaration')
        ->call('generate', '2026-04')
        ->assertDispatched('toast')
        ->assertSee('Markera som inlämnad');

    $this->get(route('payroll.agi'))->assertOk();
});

it('puts the year\'s payslips and employer declarations in the archive package', function () {
    $this->runs->approve($this->admin, $this->run->fresh());
    $submission = $this->agi->generate($this->owner, $this->company, '2026-04');
    $year = FiscalYear::query()->orderBy('start_date')->firstOrFail();

    $package = app(CreateArchivePackage::class)($this->owner, $year);
    $zip = new ZipArchive;
    $path = tempnam(sys_get_temp_dir(), 'arkiv');
    file_put_contents($path, Storage::disk('local')->get((string) $package->file_path));
    $zip->open($path);
    $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $i): string => (string) $zip->getNameIndex($i))->map(fn (string $name): string => substr($name, strpos($name, '/') + 1));
    $agi = (string) $zip->getFromName(ArchivePackageBuilder::name($this->company, $year).'/lon/arbetsgivardeklarationer/'.$submission->filename());
    $zip->close();
    unlink($path);

    expect($package->status)->toBe('DONE')
        ->and($names)->toContain('lon/lonebesked/Lonebesked-2026-04-'.$this->anna->id.'.pdf')
        ->and($agi)->toBe((string) Storage::disk('local')->get($submission->xml_path));
});

it('shows the employer declaration due on Mina företag with the day from the rules', function () {
    $this->runs->approve($this->admin, $this->run->fresh());

    $task = collect(app(CompanyOverview::class)->for($this->owner->fresh())[0]['tasks'])->firstWhere('destination', 'agi');

    expect($task['label'])->toBe('AGI att lämna, äldsta 2026-04')
        ->and($task['due'])->toBe('2026-05-12')
        ->and($task['overdue'])->toBeFalse()
        ->and(app(CompanyOverview::class)->months($this->owner->fresh())['rows'][0]['cells']['2026-04'])
        ->toMatchArray(['agi' => 'DUE', 'agi_due' => '2026-05-12']);

    $this->agi->submitted($this->owner, $this->agi->generate($this->owner, $this->company, '2026-04'), 'KV-1');

    expect(collect(app(CompanyOverview::class)->for($this->owner->fresh())[0]['tasks'])->firstWhere('destination', 'agi'))->toBeNull();
});

it('shows the payroll key figures on the payroll pages', function () {
    $this->runs->approve($this->admin, $this->run->fresh());
    $payslip = Payslip::query()->sole();
    $kpis = collect(app(PayrollKpis::class)->for($this->owner->fresh(), $this->company, CarbonImmutable::parse($this->run->payment_date)))->keyBy('label');
    $paymentMonth = $this->run->payment_date->translatedFormat('F');

    expect($kpis["Lönekostnad {$paymentMonth}"]['value']->ore)->toBe($payslip->gross_ore->ore + $payslip->employer_contribution_ore->ore)
        ->and($kpis['Skatt och avgifter att betala']['value']->ore)->toBe($payslip->tax_ore->ore + $payslip->employer_contribution_ore->ore)
        ->and($kpis['Arbetsgivardeklarationer att lämna']['value'])->toBe('1')
        ->and($kpis['Arbetsgivardeklarationer att lämna']['warn'])->toBeTrue()
        ->and($kpis['Anställda']['value'])->toBe('1');

    $this->get(route('payroll.runs.index'))->assertOk()->assertSee('Skatt och avgifter att betala')->assertSee('Anställda');

    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    expect(app(PayrollKpis::class)->for($bookkeeper, $this->company))->toBe([]);
});
