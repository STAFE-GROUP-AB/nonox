<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Agreements\Actions\AgreementAi;
use App\Modules\Agreements\Actions\AgreementSetup;
use App\Modules\Agreements\Agents\LocalAgreementAgent;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Livewire\AgreementAiPage;
use App\Modules\Agreements\Models\AgreementProposal;
use App\Modules\Ai\AiModule;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * LocalAgreementAgent (ADR-033 §6): a local agreement is read as masked
 * text, the AI proposes deviations as override paths with the quote and
 * page, proposals that do not fit are dropped, and an accepted deviation is
 * recorded unverified: a run uses it only after agreements:verify. The AI
 * provider is faked; the document is synthetic.
 */

const LOCAL_AGREEMENT_TEXT = "Lokalt avtal\nMellan Testkrogen AB, org.nr 556036-0793, och HRF-klubben.\nKontaktperson: Anna Kock, 19900615-1234, anna@testkrogen.example.\nOB-tillägget enligt § 13 höjs till 35 kronor per timme.\nBeräkningsperioden för arbetstid är 16 veckor.";

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-05-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    $modules = app(ModuleManager::class);
    $modules->enable(AgreementsModule::KEY, $this->company, $this->owner);
    $modules->enable(AiModule::KEY, $this->company, $this->owner);
    $modules->updateSettings(AiModule::KEY, $this->company, $this->owner, ['provider' => 'OPENAI', 'api_keys' => ['OPENAI' => Crypt::encryptString('sk-test-abcdefghijklmnopqrstuvwxyz0123456789ABCD')]]);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->ai = app(AgreementAi::class);
    $this->binding = app(AgreementSetup::class)->saveBinding($this->owner, $this->company, ['agreement_key' => 'visita-hrf-grona-riks', 'variant' => 'MEMBER', 'bound_from' => '2025-01-01']);
});

function localDeviation(string $path, array $values, string $quote): array
{
    return ['agreement_key' => 'visita-hrf-grona-riks', 'path' => $path, 'amount_kr' => '', 'percent' => '', 'divisor' => '', 'weeks' => '', 'hours' => '', 'text' => '', ...$values, 'description' => 'Avvikelse', 'quote' => $quote, 'page' => 1];
}

it('proposes the local deviations from masked text, drops what does not fit, and records an accepted one unverified', function () {
    LocalAgreementAgent::fake([[
        'deviations' => [
            localDeviation('codes.GR_OB.rate', ['amount_kr' => '35'], 'OB-tillägget enligt § 13 höjs till 35 kronor per timme.'),
            localDeviation('working_time.calculation_period_weeks', ['weeks' => '16'], 'Beräkningsperioden för arbetstid är 16 veckor.'),
            localDeviation('minimum_wages.steps', ['amount_kr' => '30000'], 'Lön 30 000 kr.'),
            localDeviation('codes.FINNS_EJ.rate', ['amount_kr' => '10'], 'Okänt tillägg.'),
        ],
        'notes' => '',
    ]]);
    $document = $this->ai->upload($this->owner, $this->company, null, 'LOCAL_AGREEMENT', 'lokalt-avtal.txt', 'text/plain', LOCAL_AGREEMENT_TEXT);

    expect($this->ai->identify($this->owner, $this->company, $document))->toBe(2);

    LocalAgreementAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'GR_OB: OB-tillägg (OB)')
        && str_contains($prompt->prompt, 'codes.*.rate')
        && ! str_contains($prompt->prompt, '2759')
        && ! str_contains($prompt->prompt, '19900615-1234')
        && ! str_contains($prompt->prompt, 'anna@testkrogen.example')
        && ! str_contains($prompt->prompt, '556036-0793'));

    $proposals = AgreementProposal::query()->orderBy('id')->get();

    expect($proposals)->toHaveCount(2)
        ->and($proposals[0]->value)->toMatchArray(['path' => 'codes.GR_OB.rate', 'value' => ['type' => 'ORE_PER_HOUR', 'ore' => 3500]])
        ->and($proposals[0]->quote_found)->toBeTrue()
        ->and($proposals[0]->agent)->toBe(AgreementProposal::LOCAL_AGREEMENT);

    $this->ai->accept($this->owner, $this->company, $proposals[0]);
    $this->ai->accept($this->owner, $this->company, $proposals[1], '18');

    $overrides = (array) $this->binding->fresh()->overrides;

    expect($overrides['codes.GR_OB.rate'])->toMatchArray(['value' => ['type' => 'ORE_PER_HOUR', 'ore' => 3500], 'origin' => 'AI', 'source' => 'LOKALT#s. 1', 'applied' => true])
        ->and($overrides['codes.GR_OB.rate'])->not->toHaveKey('verified_at')
        ->and($overrides['working_time.calculation_period_weeks'])->toMatchArray(['value' => 18, 'applied' => false])
        ->and(AuditLog::query()->where('action', 'agreements.ai_analysis')->value('payload'))->not->toHaveKey('quote');
});

it('asks for the agreement binding before reading a local agreement', function () {
    $this->binding->delete();
    $document = $this->ai->upload($this->owner, $this->company, null, 'LOCAL_AGREEMENT', 'lokalt-avtal.txt', 'text/plain', LOCAL_AGREEMENT_TEXT);

    expect(fn () => $this->ai->identify($this->owner, $this->company, $document))->toThrow(ValidationException::class, 'Lägg till avtalet');
});

it('shows a local deviation for review on the NONOX AI page', function () {
    LocalAgreementAgent::fake([['deviations' => [localDeviation('codes.GR_OB.rate', ['amount_kr' => '35'], 'OB-tillägget enligt § 13 höjs till 35 kronor per timme.')], 'notes' => '']]);
    $document = $this->ai->upload($this->owner, $this->company, null, 'LOCAL_AGREEMENT', 'lokalt-avtal.txt', 'text/plain', LOCAL_AGREEMENT_TEXT);
    $this->ai->identify($this->owner, $this->company, $document);
    $proposal = AgreementProposal::query()->sole();

    Livewire::test(AgreementAiPage::class)
        ->assertSee('Lokal avvikelse')
        ->assertSee('Sats för GR_OB: 35,00 kr per timme')
        ->assertSee('Centrala avtalet')
        ->call('edit', $proposal->id)
        ->set('editValue', '40')
        ->call('saveEdit')
        ->assertDispatched('toast', message: 'Uppgiften är ändrad och införd.');

    expect(((array) $this->binding->fresh()->overrides)['codes.GR_OB.rate']['value'])->toEqual(['type' => 'ORE_PER_HOUR', 'ore' => 4000]);
});
