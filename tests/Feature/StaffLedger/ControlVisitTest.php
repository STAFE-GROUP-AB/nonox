<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\StaffLedger\Actions\AppendEntry;
use App\Modules\StaffLedger\Actions\CorrectEntry;
use App\Modules\StaffLedger\Domain\CheckInMethod;
use App\Modules\StaffLedger\Domain\CorrectionKind;
use App\Modules\StaffLedger\Domain\EntryType;
use App\Modules\StaffLedger\Livewire\ControlVisitPage;
use App\Modules\StaffLedger\Support\Export\ControlVisitReport;
use App\Modules\StaffLedger\Support\Export\LedgerXmlFormat;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\StaffLedger;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    StaffLedger::enable($this->company, $this->owner);
    $this->location = StaffLedger::location($this->owner, $this->company);
    $this->anna = StaffLedger::person($this->owner, $this->company);
    $this->hired = StaffLedger::person($this->owner, $this->company, '19800101234', null, ['hired' => true, 'employer_name' => 'Bemanning Test AB', 'employer_org_number' => '5560160680']);

    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00'));
    $append = fn ($person, EntryType $type, string $at) => app(AppendEntry::class)($this->location, $person, ['type' => $type, 'method' => CheckInMethod::KioskPin, 'occurred_at' => CarbonImmutable::parse($at)]);
    $append($this->anna, EntryType::CheckIn, '2026-11-02 08:00');
    $out = $append($this->anna, EntryType::CheckOut, '2026-11-02 10:00');
    $append($this->hired, EntryType::CheckIn, '2026-11-02 09:15');
    app(CorrectEntry::class)($this->owner, $this->company, $out, CorrectionKind::Time, CarbonImmutable::parse('2026-11-02 10:30'), 'Rätt tid');

    // A company in the same building keeps its own ledger.
    [$neighbourOwner, $this->neighbour] = Bookkeeping::company();
    CurrentCompany::run($this->neighbour, function () use ($neighbourOwner) {
        StaffLedger::enable($this->neighbour, $neighbourOwner);
        $location = StaffLedger::location($neighbourOwner, $this->neighbour, ['name' => 'Grannen']);
        $person = StaffLedger::person($neighbourOwner, $this->neighbour, '19750505123', null, ['first_name' => 'Grannens', 'last_name' => 'Anställd']);
        app(AppendEntry::class)($location, $person, ['type' => EntryType::CheckIn, 'method' => CheckInMethod::KioskPin, 'occurred_at' => CarbonImmutable::parse('2026-11-02 08:00')]);
    });

    CurrentCompany::forget();
    $this->actingAs($this->owner);
});

it('is one tap from the navigation and shows who is here now with full numbers', function () {
    $this->get(route('staff-ledger.overview'))->assertOk()->assertSee(route('staff-ledger.control-visit'), escape: false);

    $this->get(route('staff-ledger.control-visit'))->assertOk()
        ->assertSee('Incheckade just nu (1)')
        ->assertSee('Testa Person234')
        ->assertSee(ControlVisitReport::formatNumber(StaffLedger::personalNumber('19800101234')))
        ->assertSee('Bemanning Test AB (556016-0680)')
        ->assertDontSee('Grannens');

    expect(AuditLog::forCompany($this->company)->where('action', 'staff_ledger.control_visit_opened')->sole()->payload)->toBe(['where' => 'app', 'export' => 'view']);
});

it('searches by name or personal number', function () {
    Livewire::test(ControlVisitPage::class)
        ->set('search', '19900101')
        ->assertSee('Testa Person123')
        ->assertDontSee('Testa Person234')
        ->set('search', 'person234')
        ->assertSee('Testa Person234');
});

it('is refused to roles without the permission', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    $this->actingAs($bookkeeper)->get(route('staff-ledger.control-visit'))->assertForbidden();
    $this->actingAs($bookkeeper)->get(route('staff-ledger.control-visit.export', ['format' => 'csv']))->assertForbidden();
});

it('exports CSV with every entry and its correction', function () {
    $response = $this->get(route('staff-ledger.control-visit.export', ['format' => 'csv', 'fran' => '2026-11-02', 'till' => '2026-11-02']))->assertOk();
    $csv = (string) $response->getContent();

    expect($response->headers->get('Content-Type'))->toStartWith('text/csv')
        ->and($csv)->toStartWith("\u{FEFF}")
        ->and(substr_count($csv, "\n"))->toBe(5)
        ->and($csv)->toContain(ControlVisitReport::formatNumber(StaffLedger::personalNumber('19900101123')))
        ->and($csv)->toContain('Rättelse')
        ->and($csv)->toContain('Rätt tid')
        ->and($csv)->not->toContain('Grannens');

    expect(AuditLog::forCompany($this->company)->where('action', 'staff_ledger.control_visit_opened')->sole()->payload['export'])->toBe('csv');
});

it('exports a PDF', function () {
    $response = $this->get(route('staff-ledger.control-visit.export', ['format' => 'pdf', 'fran' => '2026-11-02', 'till' => '2026-11-02']))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-');
});

it('exports XML that validates against its schema', function () {
    $response = $this->get(route('staff-ledger.control-visit.export', ['format' => 'xml', 'fran' => '2026-11-02', 'till' => '2026-11-02']))->assertOk();

    $document = new DOMDocument;
    $document->loadXML((string) $response->getContent());
    libxml_use_internal_errors(true);
    $valid = $document->schemaValidate(app(LedgerXmlFormat::class)->schemaPath());
    $errors = array_map(fn ($error) => trim($error->message), libxml_get_errors());
    libxml_clear_errors();

    expect($errors)->toBe([])
        ->and($valid)->toBeTrue()
        ->and($document->getElementsByTagName('Registrering')->length)->toBe(4)
        ->and($document->getElementsByTagName('Rattelse')->length)->toBe(1)
        ->and($document->getElementsByTagName('Identitet')->item(0)?->textContent)->toMatch('/^\d{12}$/');
});

it('says honestly that the electronic transfer is not available yet', function () {
    Livewire::test(ControlVisitPage::class)
        ->assertSee('Skatteverket tar ännu inte emot liggaren elektroniskt.')
        ->call('transfer')
        ->assertDispatched('toast', message: 'Skatteverket tar ännu inte emot personalliggare elektroniskt. Ladda ner XML-filen och lämna den till handläggaren.', type: 'error');

    expect(AuditLog::forCompany($this->company)->where('action', 'staff_ledger.transfer_attempted')->sole()->payload['ok'])->toBeFalse();
});
