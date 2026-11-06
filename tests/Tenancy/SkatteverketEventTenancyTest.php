<?php

declare(strict_types=1);

use App\Actions\SkatteverketEvents\FetchSkatteverketEvents;
use App\Actions\SkatteverketEvents\MarkSkatteverketEvent;
use App\Domain\Company\LegalForm;
use App\Livewire\Guide\SkatteverketEvents;
use App\Livewire\Overview\OverviewPage;
use App\Models\SkatteverketEvent;
use App\Models\SkatteverketToken;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Skatteverket's customer events (ADR-054 stage 4) belong to one company:
 * another company's events are not shown, not counted and not found by id,
 * and fetching for one company never touches another's copy of an event.
 */

beforeEach(function () {
    config([
        'services.skatteverket.environment' => 'test',
        'services.skatteverket.oauth_client_id' => 'oauth-id',
        'services.skatteverket.oauth_client_secret' => 'oauth-secret',
        'services.skatteverket.api_client_id' => 'gw-id',
        'services.skatteverket.api_client_secret' => 'gw-secret',
        'services.skatteverket.pilot_users' => '',
    ]);
    Http::preventStrayRequests();

    [$this->ownerA, $this->companyA] = Bookkeeping::company();
    [$this->ownerB, $this->companyB] = Bookkeeping::company();
    $this->companyA->forceFill(['org_number' => '556334-3689', 'legal_form' => LegalForm::Ab])->save();

    $this->eventB = CurrentCompany::run($this->companyB, fn (): SkatteverketEvent => SkatteverketEvent::query()->create([
        'event_id' => 'skv-test-0001', 'type' => 'SKV.TEST.TY_TEST_V1', 'producer' => 'skatteverket', 'occurred_at' => now(),
        'title' => 'Händelse hos företag B', 'description' => 'Bara för B', 'language' => 'sv',
        'requires_action' => true, 'case_closed' => false, 'fetched_by' => $this->ownerB->id, 'fetched_at' => now(),
    ]));

    CurrentCompany::set($this->companyA);
    $this->actingAs($this->ownerA);
});

it('never shows or counts another company\'s events', function () {
    Livewire::test(SkatteverketEvents::class)->assertDontSee('Händelse hos företag B');
    Livewire::test(OverviewPage::class)->assertDontSee('väntar på företaget');

    expect(SkatteverketEvent::query()->count())->toBe(0);
});

it('answers not found for another company\'s event id', function () {
    Livewire::test(SkatteverketEvents::class)->call('markHandled', $this->eventB->id)->assertNotFound();

    expect(fn () => app(MarkSkatteverketEvent::class)($this->ownerA, $this->companyA, $this->eventB->id, true))->toThrow(ModelNotFoundException::class)
        ->and(CompanyScope::bypass(fn () => $this->eventB->fresh()->handled_at))->toBeNull();
});

it('keeps the same Skatteverket event id apart per company when fetching', function () {
    SkatteverketToken::query()->create([
        'user_id' => $this->ownerA->id, 'access_token' => 'person-token', 'refresh_token' => null,
        'scope' => 'kundhandelser', 'expires_at' => now()->addHour(), 'refresh_expires_at' => null,
    ]);
    Http::fake(['https://api.test.skatteverket.se/foretagochorganisationer/kundhandelser/v4/hamta' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/skatteverket/kundhandelser/hamtaResponse.json')), true))]);

    app(FetchSkatteverketEvents::class)($this->ownerA, $this->companyA);

    expect(SkatteverketEvent::query()->count())->toBe(3)
        ->and(CompanyScope::bypass(fn () => $this->eventB->fresh()->title))->toBe('Händelse hos företag B')
        ->and(CompanyScope::bypass(fn () => SkatteverketEvent::query()->where('event_id', 'skv-test-0001')->count()))->toBe(2);
});
