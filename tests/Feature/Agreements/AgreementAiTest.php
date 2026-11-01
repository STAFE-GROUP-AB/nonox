<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Agreements\Actions\AgreementAi;
use App\Modules\Agreements\Actions\AgreementSetup;
use App\Modules\Agreements\Agents\AgreementIdentificationAgent;
use App\Modules\Agreements\Agents\EmploymentTermsAgent;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Livewire\AgreementAiPage;
use App\Modules\Agreements\Models\AgreementProposal;
use App\Modules\Ai\AiModule;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\CollectiveAgreement;
use App\Modules\Payroll\Models\Employment;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * NONOX AI fills in the agreement setup (ADR-033 §6): documents are read as
 * masked text, every proposal keeps its quote and page, nothing is applied
 * until a person accepts it, and the audit log has no content. The AI
 * provider is faked; documents are synthetic.
 */

const AGREEMENT_HANGAVTAL = "Hängavtal\nMellan Hotell- och restaurangfacket och Testkrogen AB, org.nr 556036-0793.\nFöretaget förbinder sig att tillämpa Gröna Riks från och med 2025-01-01.\nKontakt: info@testkrogen.example";

const AGREEMENT_CONTRACT = "Anställningsavtal\nArbetstagare: Anna Kock, 19900615-1234\nAdress: Storgatan 12, 111 22 Stockholm\nBefattning: kock med eget ansvar för köket.\nAnställningen följer Gröna Riks.\nArbetstid 40 timmar per vecka.\nLön 32 000 kr per månad.";

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    $modules = app(ModuleManager::class);
    $modules->enable(AgreementsModule::KEY, $this->company, $this->owner);
    $modules->enable(AiModule::KEY, $this->company, $this->owner);
    $modules->updateSettings(AiModule::KEY, $this->company, $this->owner, ['provider' => 'OPENAI', 'api_keys' => ['OPENAI' => Crypt::encryptString('sk-test-abcdefghijklmnopqrstuvwxyz0123456789ABCD')]]);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->anna = Payroll::employee($this->owner, $this->company);
    $this->employment = Employment::query()->where('employee_id', $this->anna->id)->sole();
    $this->ai = app(AgreementAi::class);
});

function uploadText(?object $employee, string $type, string $text)
{
    return test()->ai->upload(test()->owner, test()->company, $employee, $type, 'dokument.txt', 'text/plain', $text);
}

it('proposes the company\'s agreement from its hängavtal, sending only masked text, and binds it when accepted', function () {
    AgreementIdentificationAgent::fake([[
        'agreements' => [['agreement_key' => 'visita-hrf-grona-riks', 'variant' => 'HANGAVTAL', 'collective' => 'ARBETARE', 'bound_from' => '2025-01-01', 'quote' => 'Företaget förbinder sig att tillämpa Gröna Riks från och med 2025-01-01.', 'page' => 1]],
        'notes' => '',
    ]]);
    $document = uploadText(null, 'HANGAVTAL', AGREEMENT_HANGAVTAL);

    expect($this->ai->identify($this->owner, $this->company, $document))->toBe(1);

    AgreementIdentificationAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'visita-hrf-grona-riks | Gröna Riks (Visita – HRF)')
        && str_contains($prompt->prompt, '[Sida 1]')
        && ! str_contains($prompt->prompt, '556036-0793')
        && ! str_contains($prompt->prompt, 'info@testkrogen.example')
        && ! str_contains($prompt->prompt, 'Chart of accounts'));

    $proposal = AgreementProposal::query()->sole();

    expect($proposal)
        ->status->toBe(AgreementProposal::PENDING)
        ->quote_found->toBeTrue()
        ->page->toBe(1)
        ->and(CollectiveAgreement::query()->count())->toBe(0);

    $this->ai->accept($this->owner, $this->company, $proposal);

    expect(CollectiveAgreement::query()->sole())
        ->agreement_key->toBe('visita-hrf-grona-riks')
        ->variant->toBe('HANGAVTAL')
        ->and($proposal->fresh()->status)->toBe(AgreementProposal::ACCEPTED);
});

it('needs the variant and start date from a person when the document does not give them', function () {
    AgreementIdentificationAgent::fake([['agreements' => [['agreement_key' => 'visita-hrf-grona-riks', 'variant' => 'UNKNOWN', 'collective' => 'ARBETARE', 'bound_from' => '', 'quote' => 'Gröna Riks', 'page' => 1]], 'notes' => '']]);
    $this->ai->identify($this->owner, $this->company, uploadText(null, 'MEMBERSHIP', AGREEMENT_HANGAVTAL));
    $proposal = AgreementProposal::query()->sole();

    expect(fn () => $this->ai->accept($this->owner, $this->company, $proposal))->toThrow(ValidationException::class, 'medlem eller genom hängavtal');

    $this->ai->accept($this->owner, $this->company, $proposal, ['agreement_key' => 'visita-hrf-grona-riks', 'variant' => 'MEMBER', 'bound_from' => '2024-05-01']);

    expect(CollectiveAgreement::query()->sole()->bound_from->toDateString())->toBe('2024-05-01');
});

it('analyses all staff and fills in what a person approves: Godkänn, Ändra, Avvisa, and all rows that agree with the document', function () {
    app(AgreementSetup::class)->saveBinding($this->owner, $this->company, ['agreement_key' => 'visita-hrf-grona-riks', 'variant' => 'MEMBER', 'bound_from' => '2025-01-01']);
    EmploymentTermsAgent::fake([[
        'facts' => [
            ['field' => 'agreement_key', 'value' => 'visita-hrf-grona-riks', 'quote' => 'Anställningen följer Gröna Riks.', 'page' => 1],
            ['field' => 'agreement_group', 'value' => '1', 'quote' => 'Befattning: kock med eget ansvar för köket.', 'page' => 1],
            ['field' => 'weekly_hours', 'value' => '40', 'quote' => 'Arbetstid 40 timmar per vecka.', 'page' => 1],
            ['field' => 'position_title', 'value' => 'Kock', 'quote' => 'Befattning: kock med eget ansvar för köket.', 'page' => 1],
            ['field' => 'monthly_salary', 'value' => '32000', 'quote' => 'Lön 32 000 kr per månad.', 'page' => 1],
            ['field' => 'industry_experience_from', 'value' => '2015-01-01', 'quote' => 'Branschvana sedan 2015.', 'page' => 1],
        ],
        'notes' => '',
    ]]);
    uploadText($this->anna, 'EMPLOYMENT_CONTRACT', AGREEMENT_CONTRACT);

    expect($this->ai->analyzeAll($this->owner, $this->company))->toBe(['analysed' => 1, 'proposals' => 6, 'failed' => []]);

    EmploymentTermsAgent::assertPrompted(fn (AgentPrompt $prompt): bool => ! str_contains($prompt->prompt, '19900615-1234')
        && ! str_contains($prompt->prompt, 'Storgatan')
        && str_contains($prompt->prompt, '[personnummer]')
        && str_contains($prompt->prompt, '1: Lönegrupp 1 (Kvalificerat yrkesarbete)'));

    $byField = AgreementProposal::query()->get()->keyBy('field');

    // The quote about experience is not in the document: it can only be approved one by one.
    expect($byField['industry_experience_from']->quote_found)->toBeFalse()
        ->and($this->employment->fresh()->collective_agreement_id)->toBeNull();

    $this->ai->reject($this->owner, $this->company, $byField['position_title']);
    $result = $this->ai->acceptMatching($this->owner, $this->company, $this->anna->id);

    expect($result)->toBe(['accepted' => 4, 'left' => 0])
        ->and($this->employment->fresh())
        ->collective_agreement_id->toBe(CollectiveAgreement::query()->value('id'))
        ->agreement_group->toBe('1')
        ->position_title->not->toBe('Kock')
        ->and($byField['industry_experience_from']->fresh()->status)->toBe(AgreementProposal::PENDING);

    $this->ai->accept($this->owner, $this->company, $byField['industry_experience_from'], '2016-03-01');

    expect($this->employment->fresh()->industry_experience_from->toDateString())->toBe('2016-03-01')
        ->and($byField['industry_experience_from']->fresh()->decided_value)->toBe('2016-03-01');
});

it('logs each analysis and decision without any content', function () {
    EmploymentTermsAgent::fake([['facts' => [['field' => 'weekly_hours', 'value' => '40', 'quote' => 'Arbetstid 40 timmar per vecka.', 'page' => 1]], 'notes' => '']]);
    $document = uploadText($this->anna, 'EMPLOYMENT_CONTRACT', AGREEMENT_CONTRACT);
    $this->ai->analyzeEmployee($this->owner, $this->company, $document);
    $this->ai->accept($this->owner, $this->company, AgreementProposal::query()->sole());

    $logged = json_encode(AuditLog::query()->whereIn('action', ['agreements.document_uploaded', 'agreements.ai_analysis', 'ai.document_analyzed', 'agreements.proposal_accepted'])->pluck('payload')->all(), JSON_UNESCAPED_UNICODE);

    expect(AuditLog::query()->where('action', 'ai.document_analyzed')->sole()->payload)->toMatchArray(['kind' => 'employment_terms', 'provider' => 'OPENAI'])
        ->and($logged)->not->toContain('Arbetstid')
        ->not->toContain('Anna')
        ->not->toContain('19900615');
});

it('is there only when NONOX AI and Avtal are on and the user may use both', function () {
    $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');

    expect(AgreementAi::availableFor($this->owner, $this->company))->toBeTrue()
        ->and(AgreementAi::availableFor($payrollAdmin, $this->company))->toBeFalse()
        ->and(fn () => $this->ai->upload($payrollAdmin, $this->company, null, 'HANGAVTAL', 'a.txt', 'text/plain', AGREEMENT_HANGAVTAL))->toThrow(AuthorizationException::class);

    $this->get('/lon/avtal/nonox-ai')->assertOk()->assertSee('Analysera all personal');

    app(ModuleManager::class)->disable(AiModule::KEY, $this->company, $this->owner, confirmed: true);

    expect(AgreementAi::availableFor($this->owner, $this->company))->toBeFalse();
    $this->get('/lon/avtal/nonox-ai')->assertNotFound();
});

it('refuses a document without text and keeps one file per document', function () {
    expect(fn () => $this->ai->upload($this->owner, $this->company, null, 'HANGAVTAL', 'scan.pdf', 'application/pdf', "%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n%%EOF"))->toThrow(ValidationException::class, 'ingen läsbar text');

    uploadText(null, 'HANGAVTAL', AGREEMENT_HANGAVTAL);

    expect(fn () => uploadText(null, 'HANGAVTAL', AGREEMENT_HANGAVTAL))->toThrow(ValidationException::class, 'redan uppladdat');
});

it('reviews proposals in the browser with toasts', function () {
    app(AgreementSetup::class)->saveBinding($this->owner, $this->company, ['agreement_key' => 'visita-hrf-grona-riks', 'variant' => 'MEMBER', 'bound_from' => '2025-01-01']);
    EmploymentTermsAgent::fake([['facts' => [
        ['field' => 'agreement_key', 'value' => 'visita-hrf-grona-riks', 'quote' => 'Anställningen följer Gröna Riks.', 'page' => 1],
        ['field' => 'weekly_hours', 'value' => '38', 'quote' => 'Arbetstid 40 timmar per vecka.', 'page' => 1],
    ], 'notes' => '']]);

    $page = Livewire::test(AgreementAiPage::class)
        ->set('employeeId', (string) $this->anna->id)
        ->set('document', UploadedFile::fake()->createWithContent('anna.txt', AGREEMENT_CONTRACT))
        ->call('upload')
        ->assertDispatched('toast', message: 'Dokumentet för Anna Kock är uppladdat.')
        ->call('analyzeAll')
        ->assertDispatched('toast', message: 'NONOX AI har läst 1 dokument och föreslår 2 uppgifter. Granska dem nedan.')
        ->assertSee('Anställningen följer Gröna Riks.')
        ->assertSee('Sida 1');

    $hours = AgreementProposal::query()->where('field', 'weekly_hours')->sole();
    $agreement = AgreementProposal::query()->where('field', 'agreement_key')->sole();

    $page->call('accept', $agreement->id)
        ->assertDispatched('toast', message: 'Förslaget är godkänt och infört.')
        ->call('edit', $hours->id)
        ->set('editValue', '40')
        ->call('saveEdit')
        ->assertDispatched('toast', message: 'Uppgiften är ändrad och införd.')
        ->assertSee('Inga förslag väntar.');

    expect((string) $this->employment->fresh()->weekly_hours)->toBe('40.00');
});
