<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\PruneSigningLinks;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Console\Commands\VerifySigningChainsCommand;
use App\Domain\Signing\Chain\CanonicalJson;
use App\Domain\Signing\Chain\ChainVerifier;
use App\Domain\Signing\Chain\EventHash;
use App\Domain\Signing\Chain\SigningChain;
use App\Domain\Signing\Chain\SigningChainBackfill;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningPdfs;
use App\Livewire\Signing\SigningPanel;
use App\Mail\SigningRequestMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\SigningDocumentVersion;
use App\Models\SigningEnvelope;
use App\Models\SigningEvent;
use App\Models\SigningRequest;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\SigningConsentFixture;

/*
 * The hash-chained audit trail of a signing envelope (ADR-046 amendment
 * 2026-11-18, gap 4, package S2): every event of an envelope is chained from
 * the document's SHA-256, the head of a finished envelope is kept on it and
 * in the audit log, signing:verify recomputes everything, and envelopes from
 * before the chain are started additively by the backfill.
 */

function s2Token(string $url): string
{
    return basename((string) parse_url($url, PHP_URL_PATH));
}

function s2SignAt(string $token, string $name = 'Anna Andersson'): SigningRequest
{
    $links = app(SignAtLink::class);
    $request = $links->find($token);
    expect($request)->toBeInstanceOf(SigningRequest::class);

    SigningConsentFixture::give($request);

    return $links->sign($request, ['name' => $name, 'accept' => '1', 'method' => 'TYPED', 'typed_signature' => $name, 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)], '203.0.113.7', 'Pest');
}

function s2LinkFor(string $email): ?string
{
    $url = null;

    Mail::assertQueued(SigningRequestMail::class, function (SigningRequestMail $mail) use ($email, &$url): bool {
        if ($mail->hasTo($email)) {
            $url = $mail->url;
        }

        return true;
    });

    return $url === null ? null : s2Token($url);
}

/** @return list<SigningEvent> */
function s2Chain(SigningEnvelope $envelope): array
{
    return array_values(SigningEvent::query()->where('envelope_id', $envelope->id)->orderBy('sequence')->get()->all());
}

function s2Verify(Company $company): array
{
    return app(ChainVerifier::class)->verify($company);
}

/** @return list<string> */
function s2Codes(Company $company): array
{
    return array_values(array_unique(array_map(fn (array $problem): string => $problem['code'], s2Verify($company)['problems'])));
}

/** Runs SQL with one trigger off, as only someone with the table owner's rights could. Rolled back with the test. */
function s2WithoutTrigger(string $table, string $trigger, string $sql): void
{
    DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
    DB::statement($sql);
    DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
}

/**
 * An envelope as S1 left it, before the chain: a request with its envelope
 * and plain events without envelope_id (written through the query builder,
 * as the S1 code did through the model).
 */
function s2LegacyEnvelope(object $test, string $status = 'COMPLETED', int $signers = 1): SigningEnvelope
{
    $bytes = '%PDF-1.4 legacy '.Str::random(8);
    $path = "crm-signing/{$test->company->id}/".Str::uuid()->toString().'.pdf';
    Storage::disk('local')->put($path, $bytes);
    $sha = hash('sha256', $bytes);
    $id = (string) Str::uuid();
    $now = CarbonImmutable::parse('2026-05-01 09:00:00');

    DB::table('signing_envelopes')->insert([
        'id' => $id, 'company_id' => $test->company->id, 'document_type' => 'QUOTE', 'sales_document_id' => $test->quote->id,
        'status' => $status, 'current_step' => 1, 'document_path' => $path, 'document_name' => 'Offert.pdf', 'document_sha256' => $sha,
        'sent_at' => $now, 'completed_at' => $status === 'COMPLETED' ? $now->addHour() : null, 'created_at' => $now, 'updated_at' => $now,
    ]);

    foreach (range(1, $signers) as $index) {
        $requestId = DB::table('crm_signing_requests')->insertGetId([
            'company_id' => $test->company->id, 'envelope_id' => $id, 'step' => 1, 'document_type' => 'QUOTE', 'sales_document_id' => $test->quote->id,
            'token_hash' => hash('sha256', Str::random(32)), 'email' => "signer{$index}@kontorshuset.test", 'expires_at' => $now->addDays(30),
            'document_path' => $path, 'document_name' => 'Offert.pdf', 'document_sha256' => $sha, 'status' => $status === 'COMPLETED' ? 'SIGNED' : 'PENDING',
            'signer_name' => $status === 'COMPLETED' ? 'Anna Andersson' : null, 'signed_at' => $status === 'COMPLETED' ? $now->addHour() : null,
            'channel' => 'LINK', 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('crm_signing_events')->insert(['company_id' => $test->company->id, 'signing_request_id' => $requestId, 'event' => 'SENT', 'user_id' => $test->owner->id, 'created_at' => $now]);
    }

    if ($status === 'COMPLETED') {
        DB::table('crm_signing_events')->insert(['company_id' => $test->company->id, 'signing_request_id' => $requestId, 'event' => 'SIGNED', 'ip' => '203.0.113.7', 'user_agent' => 'Pest', 'created_at' => $now->addHour()]);
    }

    return SigningEnvelope::query()->findOrFail($id);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00.250000'));
    Storage::fake('local');
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB', ['email' => 'inkop@kontorshuset.test']);
    $this->quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-06-15',
        'until_date' => '2026-07-15',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]],
    ]);
});

it('writes canonical JSON: sorted keys at every level, lists in order, UTC times with microseconds, no floats', function () {
    expect(CanonicalJson::encode(['b' => 1, 'a' => ['d' => null, 'c' => 'Åsa/Örebro'], 'l' => [3, 1]]))->toBe('{"a":{"c":"Åsa/Örebro","d":null},"b":1,"l":[3,1]}')
        ->and(CanonicalJson::time('2026-06-15 10:00:00.123456+02:00'))->toBe('2026-06-15T08:00:00.123456Z')
        ->and(CanonicalJson::time(CarbonImmutable::parse('2026-01-15 10:00:00', 'Europe/Stockholm')))->toBe('2026-01-15T09:00:00.000000Z')
        ->and(CanonicalJson::seconds('2026-06-15 10:00:00.9+02'))->toBe('2026-06-15T08:00:00Z')
        ->and(CanonicalJson::encode(['text' => "a\u{2028}b\u{2029}c"]))->toBe('{"text":"a\\u2028b\\u2029c"}')
        ->and(CanonicalJson::encode(['text' => "e\u{0301}"]))->not->toBe(CanonicalJson::encode(['text' => 'é']))
        ->and(fn () => CanonicalJson::encode(['amount' => 1.5]))->toThrow(InvalidArgumentException::class);
});

it('hashes an event as sha256(previous hash || canonical JSON), golden', function () {
    $fields = EventHash::fields(
        companyId: 7,
        envelopeId: '00000000-0000-4000-8000-000000000001',
        sequence: 2,
        requestId: 11,
        event: SigningEventType::Signed,
        userId: null,
        ip: '203.0.113.7',
        userAgent: 'Pest',
        createdAt: '2026-06-15 10:00:00.250000+02:00',
        payload: ['evidence' => str_repeat('a', 64)],
    );
    $json = '{"company_id":7,"created_at":"2026-06-15T08:00:00.250000Z","envelope_id":"00000000-0000-4000-8000-000000000001","event":"SIGNED","ip":"203.0.113.7","payload":{"evidence":"'.str_repeat('a', 64).'"},"sequence":2,"signing_request_id":11,"user_agent":"Pest","user_id":null}';

    expect(CanonicalJson::encode($fields))->toBe($json)
        ->and(EventHash::of($fields, str_repeat('0', 64)))->toBe(hash('sha256', str_repeat('0', 64).$json))
        ->and(EventHash::of($fields, str_repeat('0', 64)))->toBe('2151c15b8e0b1ddf8d05468b431182cecadc57ad1e624264580c44dc7099b77e');
});

it('chains one signer\'s events from the document hash and keeps the head on the envelope and in the audit log', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);
    $links = app(SignAtLink::class);
    SigningConsentFixture::token(s2Token($sent['url']));
    $links->open($links->find(s2Token($sent['url'])));
    $signed = s2SignAt(s2Token($sent['url']));
    $envelope = SigningEnvelope::query()->sole();
    $chain = s2Chain($envelope);

    expect(array_map(fn (SigningEvent $event): string => $event->event->value, $chain))->toBe(['SENT', 'CONSENTED', 'OPENED', 'SIGNED', 'COMPLETED'])
        ->and(array_map(fn (SigningEvent $event): int => $event->sequence, $chain))->toBe([1, 2, 3, 4, 5])
        ->and($chain[0]->previous_hash)->toBe($envelope->document_sha256)
        ->and($chain[1]->previous_hash)->toBe($chain[0]->hash)
        ->and($chain[4]->previous_hash)->toBe($chain[3]->hash)
        ->and(array_map(fn (SigningEvent $event): bool => EventHash::ofEvent($event) === $event->hash, $chain))->toBe([true, true, true, true, true])
        ->and($chain[3]->payload)->toBe(['evidence' => EventHash::evidenceOf($signed->refresh())])
        ->and($chain[3]->ip)->toBe('203.0.113.7')
        ->and($chain[4]->payload)->toBe(['signers' => 1])
        ->and($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($envelope->chain_head)->toBe($chain[4]->hash)
        ->and($envelope->chain_sequence)->toBe(5)
        ->and(AuditLog::forCompany($this->company)->where('action', 'signing.completed')->sole()->payload)->toEqual([
            'envelope_id' => $envelope->id,
            'status' => 'COMPLETED',
            'chain_sequence' => 5,
            'chain_head' => $chain[4]->hash,
        ])
        ->and(SigningDocumentVersion::query()->sole()->only(['envelope_id', 'kind', 'path', 'sha256']))->toBe(['envelope_id' => $envelope->id, 'kind' => 'ORIGINAL', 'path' => $envelope->document_path, 'sha256' => $envelope->document_sha256])
        ->and(SigningDocumentVersion::query()->sole()->bytes)->toBe(strlen((string) Storage::disk('local')->get($envelope->document_path)))
        ->and(s2Verify($this->company))->toMatchArray(['ok' => true, 'envelopes' => 1, 'events' => 5, 'files' => 2, 'problems' => []]);
});

it('chains every signer of an envelope in one sequence and closes it on a decline', function () {
    app(SendForSigning::class)->envelope($this->owner, $this->company, $this->quote, ['signers' => [['email' => 'a@kontorshuset.test', 'step' => 1], ['email' => 'b@kontorshuset.test', 'step' => 1], ['email' => 'c@kontorshuset.test', 'step' => 2]]]);
    s2SignAt((string) s2LinkFor('a@kontorshuset.test'));
    $links = app(SignAtLink::class);
    $links->decline($links->find((string) s2LinkFor('b@kontorshuset.test')), 'Fel pris');
    $envelope = SigningEnvelope::query()->sole();
    $chain = s2Chain($envelope);

    // The third signer's step never opened; the decline revokes that row too.
    expect(array_map(fn (SigningEvent $event): string => $event->event->value, $chain))->toBe(['SENT', 'SENT', 'CONSENTED', 'SIGNED', 'DECLINED', 'REVOKED'])
        ->and(array_map(fn (SigningEvent $event): int => $event->sequence, $chain))->toBe([1, 2, 3, 4, 5, 6])
        ->and($chain[4]->payload)->toHaveKey('evidence')
        ->and(json_encode($chain[4]->payload))->not->toContain('Fel pris')
        ->and($envelope->status)->toBe(EnvelopeStatus::Declined)
        ->and($envelope->chain_head)->toBe($chain[5]->hash)
        ->and(AuditLog::forCompany($this->company)->where('action', 'signing.declined')->sole()->payload['chain_head'])->toBe($chain[5]->hash)
        ->and(s2Verify($this->company)['ok'])->toBeTrue();
});

it('ends a withdrawn envelope with VOIDED and its head', function () {
    $envelope = app(SendForSigning::class)->envelope($this->owner, $this->company, $this->quote, ['signers' => [['email' => 'a@kontorshuset.test', 'step' => 1], ['email' => 'b@kontorshuset.test', 'step' => 2]]]);
    app(SendForSigning::class)->void($this->owner, $this->company, $envelope);
    $envelope->refresh();
    $chain = s2Chain($envelope);

    expect(array_map(fn (SigningEvent $event): string => $event->event->value, $chain))->toBe(['SENT', 'REVOKED', 'REVOKED', 'VOIDED'])
        ->and($chain[3]->payload)->toBe(['reason' => 'ENVELOPE_VOIDED'])
        ->and($chain[3]->user_id)->toBe($this->owner->id)
        ->and($envelope->chain_head)->toBe($chain[3]->hash)
        ->and(AuditLog::forCompany($this->company)->where('action', 'signing.voided')->sole()->payload['chain_sequence'])->toBe(4)
        ->and(s2Verify($this->company)['ok'])->toBeTrue();
});

it('refuses an event out of sequence, one that does not link, and an unchained one once the chain has started', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);
    $request = $sent['request'];
    $first = SigningEvent::query()->where('envelope_id', $request->envelope_id)->sole();
    $row = ['company_id' => $this->company->id, 'signing_request_id' => $request->id, 'event' => 'OPENED', 'envelope_id' => $request->envelope_id, 'created_at' => now()];

    expect(fn () => DB::transaction(fn () => DB::table('crm_signing_events')->insert([...$row, 'sequence' => 3, 'previous_hash' => $first->hash, 'hash' => str_repeat('b', 64)])))
        ->toThrow(QueryException::class, 'out of sequence')
        ->and(fn () => DB::transaction(fn () => DB::table('crm_signing_events')->insert([...$row, 'sequence' => 2, 'previous_hash' => str_repeat('c', 64), 'hash' => str_repeat('b', 64)])))
        ->toThrow(QueryException::class, 'does not link')
        ->and(fn () => DB::transaction(fn () => DB::table('crm_signing_events')->insert(collect($row)->except('envelope_id')->all())))
        ->toThrow(QueryException::class, 'unchained event is refused')
        ->and(fn () => DB::transaction(fn () => DB::table('crm_signing_events')->insert([...$row, 'envelope_id' => SigningEnvelope::create(['document_type' => 'QUOTE', 'sales_document_id' => $this->quote->id, 'document_path' => 'x.pdf', 'document_name' => 'x.pdf', 'document_sha256' => str_repeat('d', 64)])->id, 'sequence' => 1, 'previous_hash' => str_repeat('d', 64), 'hash' => str_repeat('b', 64)])))
        ->toThrow(QueryException::class, 'envelope of its signing request')
        ->and(fn () => DB::transaction(fn () => DB::table('crm_signing_events')->where('id', $first->id)->update(['hash' => str_repeat('e', 64)])))
        ->toThrow(QueryException::class, 'append-only');
});

it('never changes a finished envelope\'s head, nor a document version', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);
    s2SignAt(s2Token($sent['url']));
    $envelope = SigningEnvelope::query()->sole();
    $version = SigningDocumentVersion::query()->sole();

    expect(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['chain_head' => str_repeat('f', 64)])))
        ->toThrow(QueryException::class, 'chain head is set once')
        ->and(fn () => DB::transaction(fn () => DB::table('signing_document_versions')->where('id', $version->id)->update(['sha256' => str_repeat('f', 64)])))
        ->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('signing_document_versions')->where('id', $version->id)->delete()))
        ->toThrow(QueryException::class, 'only with their envelope')
        ->and(fn () => $version->update(['sha256' => str_repeat('f', 64)]))
        ->toThrow(LogicException::class);
});

it('reports a changed event, a removed event, changed evidence and a changed file, and writes the result to the audit log', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);
    $links = app(SignAtLink::class);
    $links->open($links->find(s2Token($sent['url'])));
    $signed = s2SignAt(s2Token($sent['url']));
    $envelope = SigningEnvelope::query()->sole();
    $chain = s2Chain($envelope);

    $this->artisan('signing:verify', ['company' => (string) $this->company->id])->assertSuccessful();

    s2WithoutTrigger('crm_signing_events', 'crm_signing_events_append_only', "UPDATE crm_signing_events SET user_agent = 'Ändrad' WHERE id = {$chain[2]->id}");
    expect(s2Codes($this->company))->toBe(['HASH']);

    s2WithoutTrigger('crm_signing_events', 'crm_signing_events_delete_with_request', "DELETE FROM crm_signing_events WHERE id = {$chain[1]->id}");
    expect(s2Codes($this->company))->toBe(['GAP', 'HASH']);

    s2WithoutTrigger('crm_signing_requests', 'crm_signing_requests_protect', "UPDATE crm_signing_requests SET signer_name = 'Någon annan' WHERE id = {$signed->id}");
    expect(s2Codes($this->company))->toContain('EVIDENCE');

    Storage::disk('local')->put($envelope->document_path, '%PDF-1.4 ändrad');
    expect(s2Codes($this->company))->toContain('FILE_CHANGED');

    Storage::disk('local')->delete((string) $signed->refresh()->signed_document_path);
    expect(s2Codes($this->company))->toContain('FILE_MISSING');

    $this->artisan('signing:verify', ['company' => (string) $this->company->id])->assertFailed();

    $results = AuditLog::forCompany($this->company)->where('action', VerifySigningChainsCommand::ACTION)->orderBy('id')->pluck('payload');
    expect($results->map(fn (array $payload): bool => $payload['ok'])->all())->toBe([true, false])
        ->and(json_encode($results->last()))->not->toContain('Någon annan')
        ->and(json_encode($results->last()))->not->toContain('anna@kontorshuset.test');
});

it('reports a finished envelope whose head is gone from the audit log or does not match', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);
    s2SignAt(s2Token($sent['url']));
    $envelope = SigningEnvelope::query()->sole();

    s2WithoutTrigger('audit_logs', 'audit_logs_no_update_or_delete', "DELETE FROM audit_logs WHERE action = 'signing.completed'");
    expect(s2Codes($this->company))->toBe(['AUDIT']);

    s2WithoutTrigger('signing_envelopes', 'signing_envelopes_protect_chain', "UPDATE signing_envelopes SET chain_sequence = 1 WHERE id = '{$envelope->id}'");
    expect(s2Codes($this->company))->toBe(['HEAD']);
});

it('starts the chain of envelopes from before it, additively, and verifies them', function () {
    $completed = s2LegacyEnvelope($this);
    $out = s2LegacyEnvelope($this, 'OUT');
    $eventsBefore = DB::table('crm_signing_events')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    $requestsBefore = DB::table('crm_signing_requests')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

    $result = app(SigningChainBackfill::class)->run();
    $again = app(SigningChainBackfill::class)->run();

    $start = SigningEvent::query()->where('envelope_id', $completed->id)->sole();
    $completed->refresh();

    expect($result)->toBe(['envelopes' => 2, 'versions' => 2])
        ->and($again)->toBe(['envelopes' => 0, 'versions' => 0])
        ->and($start->event)->toBe(SigningEventType::ChainStarted)
        ->and($start->sequence)->toBe(1)
        ->and($start->previous_hash)->toBe($completed->document_sha256)
        ->and($start->hash)->toBe(EventHash::ofEvent($start))
        ->and($start->payload['events'])->toBe(2)
        ->and($start->payload['signers'][0]['events'])->toBe(2)
        ->and($completed->chain_head)->toBe($start->hash)
        ->and($completed->chain_sequence)->toBe(1)
        ->and(AuditLog::forCompany($this->company)->where('action', SigningChain::BACKFILLED)->sole()->payload)->toMatchArray(['envelope_id' => $completed->id, 'status' => 'COMPLETED', 'chain_head' => $start->hash, 'prior_events' => 2])
        ->and($out->refresh()->chain_head)->toBeNull()
        ->and(SigningDocumentVersion::query()->where('kind', 'ORIGINAL')->count())->toBe(2)
        // Nothing that existed changed.
        ->and(DB::table('crm_signing_events')->whereNull('envelope_id')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($eventsBefore)
        ->and(DB::table('crm_signing_requests')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($requestsBefore)
        ->and(s2Verify($this->company))->toMatchArray(['ok' => true, 'envelopes' => 2, 'events' => 2]);

    // The open envelope's chain goes on from CHAIN_STARTED.
    $request = SigningRequest::query()->where('envelope_id', $out->id)->sole();
    DB::transaction(fn () => app(SigningChain::class)->record($request, SigningEventType::Opened));
    $chain = s2Chain($out);

    expect(array_map(fn (SigningEvent $event): string => $event->event->value, $chain))->toBe(['CHAIN_STARTED', 'OPENED'])
        ->and($chain[1]->previous_hash)->toBe($chain[0]->hash)
        ->and(s2Verify($this->company)['ok'])->toBeTrue();

    // A changed earlier event is found through CHAIN_STARTED's digest.
    $legacyId = (int) DB::table('crm_signing_events')->whereNull('envelope_id')->where('event', 'SIGNED')->value('id');
    s2WithoutTrigger('crm_signing_events', 'crm_signing_events_append_only', "UPDATE crm_signing_events SET ip = '198.51.100.1' WHERE id = {$legacyId}");
    expect(s2Codes($this->company))->toBe(['PRIOR']);
});

it('starts the chain itself when an envelope from before it gets its next event', function () {
    $out = s2LegacyEnvelope($this, 'OUT');
    $request = SigningRequest::query()->where('envelope_id', $out->id)->sole();

    DB::transaction(fn () => app(SigningChain::class)->record($request, SigningEventType::Opened));
    $chain = s2Chain($out);

    expect(array_map(fn (SigningEvent $event): string => $event->event->value, $chain))->toBe(['CHAIN_STARTED', 'OPENED'])
        ->and($chain[0]->payload['events'])->toBe(1)
        ->and(s2Verify($this->company)['ok'])->toBeTrue();
});

it('prunes a declined envelope where one signer had signed whole after twelve months, keeping its head in the audit log', function () {
    // The database's own clock decides the prune period (trigger): the decline is made more than a year before it.
    // Since ADR-046 S16 the whole envelope goes (no agreement came about), with the signed row, instead of only the
    // declined signer; the chain's head stays in the audit log with the deletion.
    $this->travelTo(CarbonImmutable::now()->subMonths(13));
    app(SendForSigning::class)->envelope($this->owner, $this->company, $this->quote, ['signers' => [['email' => 'a@kontorshuset.test', 'step' => 1], ['email' => 'b@kontorshuset.test', 'step' => 1]]]);
    $signed = s2SignAt((string) s2LinkFor('a@kontorshuset.test'));
    $links = app(SignAtLink::class);
    $declined = $links->decline($links->find((string) s2LinkFor('b@kontorshuset.test')), null);
    $envelope = SigningEnvelope::query()->sole();
    $head = $envelope->chain_head;

    $this->travelBack();
    $result = app(PruneSigningLinks::class)->run(CarbonImmutable::now());

    expect($result['envelopes'])->toBe(1)
        ->and(SigningRequest::query()->whereKey([$declined->id, $signed->id])->exists())->toBeFalse()
        ->and(SigningEnvelope::query()->whereKey($envelope->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($envelope->document_path))->toBeFalse()
        ->and($head)->not->toBeNull()
        ->and(AuditLog::forCompany($this->company)->where('action', 'signing.envelope_deleted')->sole()->payload['chain_head'])->toBe($head)
        ->and(s2Verify($this->company)['ok'])->toBeTrue();
});

it('shows the latest check in the signing panel, in a fixed number of queries', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);

    Livewire::test(SigningPanel::class, ['quoteId' => $this->quote->id])->assertSee('Händelsekedjan har inte kontrollerats än.');

    $this->artisan('signing:verify')->assertSuccessful();
    Livewire::test(SigningPanel::class, ['quoteId' => $this->quote->id])->assertSee('Händelsekedjan och dokumenten var oförändrade vid kontrollen 2026-06-15 10:00.');

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(SigningPanel::class, ['quoteId' => $this->quote->id]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count();
    $short = $count();

    foreach (range(1, 6) as $i) {
        $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => "s{$i}@kontorshuset.test"]);
    }

    $this->artisan('signing:verify')->assertSuccessful();
    expect($count())->toBe($short);

    s2WithoutTrigger('crm_signing_events', 'crm_signing_events_append_only', "UPDATE crm_signing_events SET user_id = NULL WHERE signing_request_id = {$sent['request']->id}");
    $this->artisan('signing:verify')->assertFailed();

    Livewire::test(SigningPanel::class, ['quoteId' => $this->quote->id])->assertSee('hittade en avvikelse i händelsekedjan eller dokumenten');
});

it('verifies only its own company\'s envelopes', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);
    s2SignAt(s2Token($sent['url']));

    expect(CompanyScope::bypass(fn (): int => SigningEnvelope::query()->count()))->toBe(1)
        ->and(s2Verify($other))->toMatchArray(['ok' => true, 'envelopes' => 0, 'events' => 0, 'files' => 0])
        ->and(s2Verify($this->company)['envelopes'])->toBe(1);
});

it('stores the instant it hashed in the repeated autumn hour', function () {
    // 2026-10-25 00:30 UTC is 02:30 summer time (+02), the first of the two 02:30 in Stockholm. Built from the
    // timestamp: Carbon's test clock resolves a frozen time from the wall clock and would give the second 02:30.
    $instant = CarbonImmutable::createFromTimestamp(CarbonImmutable::parse('2026-10-25T00:30:00Z')->getTimestamp(), 'Europe/Stockholm');
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);
    $request = $sent['request'];

    $request->forceFill(['signed_at' => $instant, 'declined_at' => $instant])->save();
    $digest = EventHash::evidenceOf($request);
    $written = (new SigningEvent)->fromDateTime($instant);

    expect($instant->format('P'))->toBe('+02:00')
        ->and($request->refresh()->signed_at->utc()->toIso8601String())->toBe('2026-10-25T00:30:00+00:00')
        ->and($request->declined_at->utc()->toIso8601String())->toBe('2026-10-25T00:30:00+00:00')
        ->and(EventHash::evidenceOf($request))->toBe($digest)
        ->and($written)->toBe('2026-10-25 02:30:00+02:00')
        ->and(DB::selectOne("select to_char(?::timestamptz at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as at", [$written])->at)->toBe('2026-10-25 00:30');
});

it('refuses an event once the chain is closed, and reports one added beyond the head', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);
    $signed = s2SignAt(s2Token($sent['url']));
    $envelope = SigningEnvelope::query()->sole();
    $row = ['company_id' => $this->company->id, 'signing_request_id' => $signed->id, 'event' => 'OPENED', 'envelope_id' => $envelope->id, 'created_at' => now(), 'sequence' => $envelope->chain_sequence + 1, 'previous_hash' => $envelope->chain_head, 'hash' => str_repeat('b', 64)];

    expect(fn () => DB::transaction(fn () => DB::table('crm_signing_events')->insert($row)))->toThrow(QueryException::class, 'chain is closed');

    s2WithoutTrigger('crm_signing_events', 'crm_signing_events_chain', "INSERT INTO crm_signing_events (company_id, signing_request_id, event, envelope_id, sequence, previous_hash, hash, created_at) VALUES ({$this->company->id}, {$signed->id}, 'OPENED', '{$envelope->id}', ".($envelope->chain_sequence + 1).", '{$envelope->chain_head}', '".str_repeat('b', 64)."', now())");

    expect(s2Codes($this->company))->toContain('AFTER_HEAD')->toContain('COUNTER');
});

it('reports an envelope with a head but no chain, also one without signer rows', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);
    s2SignAt(s2Token($sent['url']));
    $envelope = SigningEnvelope::query()->sole();

    s2WithoutTrigger('crm_signing_events', 'crm_signing_events_delete_with_request', "DELETE FROM crm_signing_events WHERE envelope_id = '{$envelope->id}'");
    expect(s2Codes($this->company))->toContain('NO_CHAIN');

    $empty = SigningEnvelope::create(['document_type' => 'QUOTE', 'sales_document_id' => $this->quote->id, 'document_path' => 'x.pdf', 'document_name' => 'x.pdf', 'document_sha256' => str_repeat('d', 64)]);
    s2WithoutTrigger('signing_envelopes', 'signing_envelopes_protect_chain', "UPDATE signing_envelopes SET chain_head = '".str_repeat('d', 64)."', chain_sequence = 1 WHERE id = '{$empty->id}'");

    expect(collect(s2Verify($this->company)['problems'])->where('envelope_id', $empty->id)->pluck('code')->all())->toContain('NO_CHAIN');
});

it('keeps the chain counter away from everyone but the insert trigger', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);

    expect(SigningEnvelope::query()->sole()->next_sequence)->toBe(2)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $sent['request']->envelope_id)->update(['next_sequence' => 1])))
        ->toThrow(QueryException::class, 'chain counter')
        ->and(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $sent['request']->envelope_id)->update(['next_sequence' => 5, 'last_hash' => str_repeat('a', 64)])))
        ->toThrow(QueryException::class, 'chain counter');
});

it('never reuses a sequence number after the anchor signer of a backfilled envelope is pruned', function () {
    $out = s2LegacyEnvelope($this, 'OUT', signers: 2);
    app(SigningChainBackfill::class)->run();
    [$first, $second] = SigningRequest::query()->where('envelope_id', $out->id)->orderBy('id')->get()->all();
    $start = SigningEvent::query()->where('envelope_id', $out->id)->sole();

    expect($start->signing_request_id)->toBe($first->id);

    // The first signer's link closed long ago and is pruned with its events, CHAIN_STARTED included.
    DB::table('crm_signing_requests')->where('id', $first->id)->update(['expires_at' => CarbonImmutable::now()->subYears(2)]);
    $this->travelBack();
    app(PruneSigningLinks::class)(CarbonImmutable::now());

    expect(SigningRequest::query()->whereKey($first->id)->exists())->toBeFalse()
        ->and(AuditLog::forCompany($this->company)->where('action', SigningChain::PRUNED)->sole()->payload['links'])->toEqual([['sequence' => 1, 'hash' => $start->hash, 'event' => 'CHAIN_STARTED']]);

    DB::transaction(fn () => app(SigningChain::class)->record($second->refresh(), SigningEventType::Opened));
    $opened = SigningEvent::query()->where('envelope_id', $out->id)->sole();

    expect($opened->sequence)->toBe(2)
        ->and($opened->previous_hash)->toBe($start->hash)
        ->and(s2Verify($this->company))->toMatchArray(['ok' => true, 'problems' => []]);
});

it('reads no PDF of an envelope whose signers were all pruned', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);
    $envelope = SigningEnvelope::query()->sole();
    Storage::disk('local')->delete($envelope->document_path);
    DB::table('crm_signing_requests')->where('id', $sent['request']->id)->delete();

    expect(collect(s2Verify($this->company)['problems'])->pluck('code')->all())->not->toContain('FILE_MISSING');
});
