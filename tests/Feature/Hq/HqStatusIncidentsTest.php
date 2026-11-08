<?php

declare(strict_types=1);

use App\Actions\Hq\Status\DeleteStatusIncident;
use App\Actions\Hq\Status\OpenStatusIncident;
use App\Actions\Hq\Status\PostStatusIncidentUpdate;
use App\Livewire\Hq\StatusIncidents;
use App\Models\AuditLog;
use App\Models\StatusIncident;
use App\Models\User;
use App\Support\Status\StatusSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Driftstatus in /hq (ADR-078): incidents and planned maintenance for the
 * public status page. HQ admins only (404 for everyone else), decisions
 * behind a passkey step, audited with ids and enum values, never the text.
 */

beforeEach(function () {
    Cache::flush();
    config(['nonox.hq_admins' => ['hq@example.test'], 'app.admin_email' => null, 'app.landing.enabled' => true]);
    $this->admin = User::factory()->withCompany()->create(['email' => 'hq@example.test']);
});

function stepUp(User $admin): void
{
    session()->put('hq.step_up', ['user_id' => $admin->id, 'at' => now()->subMinute()->getTimestamp()]);
}

it('answers 404 to everyone but an HQ admin, also on Livewire requests and in the actions', function () {
    expect(route('hq.status', [], false))->toBe('/hq/status');

    $this->get(route('hq.status'))->assertNotFound();

    $member = User::factory()->withCompany()->create();
    $this->actingAs($member)->get(route('hq.status'))->assertNotFound();
    Livewire::test(StatusIncidents::class)->assertStatus(404);

    expect(fn () => app(OpenStatusIncident::class)($member, ['title' => 'x', 'severity' => 'INFO', 'body' => 'y']))->toThrow(NotFoundHttpException::class);

    $incident = StatusIncident::query()->create(['title' => 'x', 'severity' => 'INFO', 'status' => 'INVESTIGATING', 'started_at' => now()]);

    expect(fn () => app(PostStatusIncidentUpdate::class)($member, $incident, ['status' => 'RESOLVED', 'body' => 'y']))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(DeleteStatusIncident::class)($member, $incident))->toThrow(NotFoundHttpException::class);

    $this->actingAs($this->admin)->get(route('hq.status'))->assertOk()
        ->assertSee('Ny händelse')
        ->assertSee('href="'.route('hq.status').'"', false);
});

it('refuses decisions without a fresh passkey confirmation', function () {
    $this->actingAs($this->admin);

    Livewire::test(StatusIncidents::class)
        ->set('title', 'Långsamma sidor')
        ->set('severity', 'DEGRADED')
        ->set('body', 'Vi undersöker.')
        ->call('open', null)
        ->assertHasErrors('passkey');

    expect(StatusIncident::query()->count())->toBe(0);
});

it('opens an incident that is visible on the public page at once, and audits without the text', function () {
    $this->actingAs($this->admin);
    stepUp($this->admin);

    $this->get('/status')->assertOk()->assertSee('Allt fungerar');

    Livewire::test(StatusIncidents::class)
        ->set('title', 'Långsamma sidor')
        ->set('severity', 'DEGRADED')
        ->set('body', 'Vi undersöker varför sidorna laddar långsamt.')
        ->call('open', null)
        ->assertHasNoErrors();

    $incident = StatusIncident::query()->with('updates')->sole();
    $audit = AuditLog::query()->where('action', 'hq.status_incident_opened')->sole();

    expect($incident->status->value)->toBe('INVESTIGATING')
        ->and($incident->resolved_at)->toBeNull()
        ->and($incident->created_by)->toBe($this->admin->id)
        ->and($incident->updates)->toHaveCount(1)
        ->and($audit->company_id)->toBeNull()
        ->and($audit->payload)->toEqual(['severity' => 'DEGRADED'])
        ->and(json_encode($audit->payload))->not->toContain('Långsamma');

    $this->get('/status')->assertOk()
        ->assertSee('Vi har en störning')
        ->assertSee('Långsamma sidor')
        ->assertSee('Vi undersöker varför sidorna laddar långsamt.');
});

it('validates title, severity and text', function () {
    $this->actingAs($this->admin);
    stepUp($this->admin);

    Livewire::test(StatusIncidents::class)->set('title', '')->set('body', '')->call('open', null)->assertHasErrors(['title', 'body']);
    Livewire::test(StatusIncidents::class)->set('title', 'x')->set('body', 'y')->set('severity', 'NONSENSE')->call('open', null)->assertHasErrors('severity');
    Livewire::test(StatusIncidents::class)->set('title', str_repeat('a', 161))->set('body', 'y')->call('open', null)->assertHasErrors('title');
    Livewire::test(StatusIncidents::class)->set('title', 'x')->set('body', str_repeat('a', 2001))->call('open', null)->assertHasErrors('body');

    expect(StatusIncident::query()->count())->toBe(0);
});

it('adds notes, resolves and reopens an incident, keeping the history', function () {
    $this->actingAs($this->admin);
    stepUp($this->admin);
    $incident = app(OpenStatusIncident::class)($this->admin, ['title' => 'Utskick försenade', 'severity' => 'DEGRADED', 'body' => 'Utreds.']);

    Livewire::test(StatusIncidents::class)
        ->call('startNote', $incident->id)
        ->set('noteStatus', 'IDENTIFIED')
        ->set('noteSeverity', 'OUTAGE')
        ->set('noteBody', 'Orsaken är hittad.')
        ->call('postNote', null)
        ->assertHasNoErrors();

    $incident->refresh();
    expect($incident->status->value)->toBe('IDENTIFIED')->and($incident->severity->value)->toBe('OUTAGE')->and($incident->isOpen())->toBeTrue();

    Livewire::test(StatusIncidents::class)
        ->call('startNote', $incident->id)
        ->set('noteStatus', 'RESOLVED')
        ->set('noteBody', 'Allt är som vanligt igen.')
        ->call('postNote', null)
        ->assertHasNoErrors();

    $incident->refresh();
    expect($incident->isOpen())->toBeFalse()->and($incident->severity->value)->toBe('OUTAGE');

    $this->get('/status')->assertOk()->assertSee('Allt fungerar')->assertSee('Tidigare händelser')->assertSee('Allt är som vanligt igen.');

    Livewire::test(StatusIncidents::class)
        ->call('startNote', $incident->id)
        ->set('noteStatus', 'MONITORING')
        ->set('noteBody', 'Det hände igen.')
        ->call('postNote', null);

    expect($incident->refresh()->isOpen())->toBeTrue()
        ->and($incident->updates()->count())->toBe(4);

    $audit = AuditLog::query()->where('action', 'hq.status_incident_updated')->orderBy('id')->first();
    expect($audit->payload['before'])->toEqual(['status' => 'INVESTIGATING', 'severity' => 'DEGRADED'])
        ->and(json_encode(AuditLog::query()->where('action', 'like', 'hq.status_incident%')->pluck('payload')))->not->toContain('Orsaken');
});

it('does not accept an unknown status for a note', function () {
    $incident = app(OpenStatusIncident::class)($this->admin, ['title' => 'x', 'severity' => 'INFO', 'body' => 'y']);

    expect(fn () => app(PostStatusIncidentUpdate::class)($this->admin, $incident, ['status' => 'DONE', 'body' => 'z']))->toThrow(ValidationException::class)
        ->and(fn () => app(PostStatusIncidentUpdate::class)($this->admin, $incident, ['status' => 'RESOLVED', 'body' => '']))->toThrow(ValidationException::class);
});

it('deletes an incident with its notes, audited with counts only, and clears the public page', function () {
    $this->actingAs($this->admin);
    stepUp($this->admin);
    $incident = app(OpenStatusIncident::class)($this->admin, ['title' => 'Publicerad av misstag', 'severity' => 'OUTAGE', 'body' => 'Hemlig text.']);

    $this->get('/status')->assertSee('Publicerad av misstag');

    Livewire::test(StatusIncidents::class)->call('delete', null, $incident->id)->assertHasNoErrors();

    $audit = AuditLog::query()->where('action', 'hq.status_incident_deleted')->sole();

    expect(StatusIncident::query()->count())->toBe(0)
        ->and($audit->payload)->toEqual(['severity' => 'OUTAGE', 'updates' => 1])
        ->and(DB::table('status_incident_updates')->count())->toBe(0);

    $this->get('/status')->assertOk()->assertDontSee('Publicerad av misstag')->assertSee('Allt fungerar');
    expect(StatusSnapshot::get()['incidents'])->toBe([]);
});

it('lists the incidents in HQ with a fixed number of queries', function () {
    $this->actingAs($this->admin);
    stepUp($this->admin);

    $count = function (): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        Livewire::test(StatusIncidents::class);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    app(OpenStatusIncident::class)($this->admin, ['title' => 'Ett', 'severity' => 'INFO', 'body' => 'a']);
    $few = $count();

    foreach (range(1, 10) as $i) {
        app(OpenStatusIncident::class)($this->admin, ['title' => "Händelse {$i}", 'severity' => 'INFO', 'body' => 'a']);
    }

    expect($count())->toBe($few);
});
