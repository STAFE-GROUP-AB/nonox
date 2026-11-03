<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Chat\SecureChat;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/*
 * The encrypted chat (ADR-045): the server keeps only ciphertext, in the
 * "chat" cache store (the array store in tests, which honours time to live
 * under travel), and nothing remains when a chat ends.
 */

function chatRoomId(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/** @return array{iv: string, ct: string} */
function sealed(int $bytes = 64): array
{
    return ['iv' => base64_encode(random_bytes(12)), 'ct' => base64_encode(random_bytes($bytes))];
}

/** @return list<string> */
function chatKeys(string $room, User ...$users): array
{
    $keys = ["chat:room:{$room}", "chat:msgs:{$room}"];

    foreach ($users as $user) {
        $keys[] = "chat:user:{$user->id}:a";
        $keys[] = "chat:user:{$user->id}:b";
    }

    return $keys;
}

function chatStoreHasAny(array $keys): bool
{
    foreach ($keys as $key) {
        if (Cache::store('chat')->has($key)) {
            return true;
        }
    }

    return false;
}

function chatPost(User $user, string $name, array $body = []): TestResponse
{
    return test()->actingAs($user)->postJson(route("chat.{$name}"), $body);
}

beforeEach(function () {
    Cache::store('chat')->flush();
    $this->starter = User::factory()->withCompany()->create(['name' => 'Stina Starter']);
    $this->joiner = User::factory()->withCompany()->create(['name' => 'Jonas Joiner']);
    $this->third = User::factory()->create(['name' => 'Tredje Person']);
    $this->room = chatRoomId();
});

it('answers only signed-in users', function () {
    foreach (['start', 'join', 'poll', 'send', 'end', 'companies', 'followable'] as $name) {
        $this->postJson(route("chat.{$name}"), ['room' => $this->room])->assertUnauthorized();
    }

    expect(chatStoreHasAny(chatKeys($this->room)))->toBeFalse();
});

it('starts a chat that the second user joins, with names and no code', function () {
    chatPost($this->starter, 'start', ['room' => $this->room])
        ->assertCreated()
        ->assertJson(['you' => 'a', 'peer' => null, 'joined' => false, 'seq' => 0]);

    chatPost($this->joiner, 'join', ['room' => $this->room])
        ->assertOk()
        ->assertJson(['you' => 'b', 'peer' => 'Stina Starter', 'joined' => true]);

    chatPost($this->starter, 'poll', ['room' => $this->room, 'since' => 0])
        ->assertOk()
        ->assertJson(['you' => 'a', 'peer' => 'Jonas Joiner', 'joined' => true, 'messages' => []]);
});

it('refuses a third person exactly as it refuses a code that does not exist', function () {
    chatPost($this->starter, 'start', ['room' => $this->room])->assertCreated();
    chatPost($this->joiner, 'join', ['room' => $this->room])->assertOk();

    $full = chatPost($this->third, 'join', ['room' => $this->room]);
    $unknown = chatPost($this->third, 'join', ['room' => chatRoomId()]);
    $own = chatPost($this->starter, 'join', ['room' => $this->room]);

    expect($full->status())->toBe(404)
        ->and($unknown->status())->toBe(404)
        ->and($own->status())->toBe(404)
        ->and($full->getContent())->toBe($unknown->getContent())
        ->and($own->getContent())->toBe($unknown->getContent());

    // Nor can the third person read or end it.
    chatPost($this->third, 'poll', ['room' => $this->room, 'since' => 0])->assertNotFound();
    chatPost($this->third, 'send', ['room' => $this->room, ...sealed()])->assertNotFound();
    chatPost($this->third, 'end', ['room' => $this->room])->assertOk();
    chatPost($this->starter, 'poll', ['room' => $this->room])->assertOk();
});

it('limits attempts to start or join per user', function () {
    for ($i = 0; $i < 10; $i++) {
        chatPost($this->third, 'join', ['room' => chatRoomId()])->assertNotFound();
    }

    chatPost($this->third, 'join', ['room' => chatRoomId()])->assertStatus(429);
    chatPost($this->third, 'start', ['room' => chatRoomId()])->assertStatus(429);

    // Ten minutes later the counter has expired.
    $this->travel(11)->minutes();
    chatPost($this->third, 'join', ['room' => chatRoomId()])->assertNotFound();
});

it('limits attempts per address too, more loosely, keyed by an HMAC of the address', function () {
    $people = User::factory()->count(61)->create();

    foreach ($people->take(60) as $person) {
        chatPost($person, 'join', ['room' => chatRoomId()])->assertNotFound();
    }

    chatPost($people->last(), 'join', ['room' => chatRoomId()])->assertStatus(429);

    $hmac = 'chat-attempt:ip:'.hash_hmac('sha256', '127.0.0.1', (string) config('app.key'));
    expect(Cache::store('chat')->has($hmac))->toBeTrue()
        ->and(Cache::store('chat')->has('chat-attempt:ip:'.hash('sha256', '127.0.0.1')))->toBeFalse();
});

it('ends a chat under its lock, so a send running at the same time cannot bring it back', function () {
    config(['nonox.chat.lock_wait_seconds' => 0]);
    chatPost($this->starter, 'start', ['room' => $this->room])->assertCreated();
    $lock = Cache::store('chat')->lock("chat:lock:{$this->room}", 10);
    expect($lock->get())->toBeTrue();

    expect(fn () => app(SecureChat::class)->end($this->starter, $this->room))->toThrow(LockTimeoutException::class)
        ->and(Cache::store('chat')->has("chat:room:{$this->room}"))->toBeTrue();

    $lock->release();
    expect(app(SecureChat::class)->end($this->starter, $this->room))->toBeTrue()
        ->and(Cache::store('chat')->has("chat:room:{$this->room}"))->toBeFalse();
});

it('keeps the chat you are in when you type a wrong code for another', function () {
    chatPost($this->starter, 'start', ['room' => $this->room])->assertCreated();
    chatPost($this->joiner, 'join', ['room' => $this->room])->assertOk();

    chatPost($this->joiner, 'join', ['room' => chatRoomId()])->assertNotFound();

    chatPost($this->joiner, 'poll', ['room' => $this->room])->assertOk()->assertJson(['you' => 'b']);
});

it('relays ciphertext in order and returns only what is new', function () {
    chatPost($this->starter, 'start', ['room' => $this->room])->assertCreated();
    chatPost($this->joiner, 'join', ['room' => $this->room])->assertOk();

    $first = sealed();
    chatPost($this->starter, 'send', ['room' => $this->room, ...$first])->assertCreated()->assertJson(['seq' => 1]);
    chatPost($this->joiner, 'send', ['room' => $this->room, ...sealed()])->assertCreated()->assertJson(['seq' => 2]);
    chatPost($this->starter, 'send', ['room' => $this->room, ...sealed()])->assertCreated()->assertJson(['seq' => 3]);

    $all = chatPost($this->joiner, 'poll', ['room' => $this->room, 'since' => 0])->assertOk()->json('messages');
    expect(array_column($all, 'seq'))->toBe([1, 2, 3])
        ->and(array_column($all, 'from'))->toBe(['a', 'b', 'a'])
        ->and($all[0]['iv'])->toBe($first['iv'])
        ->and($all[0]['ct'])->toBe($first['ct']);

    $new = chatPost($this->joiner, 'poll', ['room' => $this->room, 'since' => 1])->json('messages');
    expect(array_column($new, 'seq'))->toBe([2, 3]);
    expect(chatPost($this->joiner, 'poll', ['room' => $this->room, 'since' => 3])->json('messages'))->toBe([]);
});

it('keeps only opaque ciphertext: a page shared with Följ mina sidor looks like any message', function () {
    chatPost($this->starter, 'start', ['room' => $this->room])->assertCreated();
    chatPost($this->joiner, 'join', ['room' => $this->room])->assertOk();
    chatPost($this->starter, 'send', ['room' => $this->room, ...sealed(90)])->assertCreated();

    $stored = Cache::store('chat')->get("chat:msgs:{$this->room}");
    $meta = Cache::store('chat')->get("chat:room:{$this->room}");

    expect(array_keys($stored[0]))->toBe(['seq', 'from', 'iv', 'ct', 'at'])
        ->and(array_keys($meta))->toBe(['a', 'a_name', 'b', 'b_name', 'created', 'active', 'seq'])
        ->and(json_encode([$stored, $meta]))->not->toContain('nav')->not->toContain('path')->not->toContain('follow');
});

it('deletes everything at once when either person ends the chat', function () {
    chatPost($this->starter, 'start', ['room' => $this->room])->assertCreated();
    chatPost($this->joiner, 'join', ['room' => $this->room])->assertOk();
    chatPost($this->starter, 'send', ['room' => $this->room, ...sealed()])->assertCreated();

    expect(chatStoreHasAny(chatKeys($this->room, $this->starter, $this->joiner)))->toBeTrue();

    chatPost($this->joiner, 'end', ['room' => $this->room])->assertOk();

    expect(chatStoreHasAny(chatKeys($this->room, $this->starter, $this->joiner)))->toBeFalse();
    chatPost($this->starter, 'poll', ['room' => $this->room])
        ->assertNotFound()
        ->assertJson(['ended' => true, 'message' => 'Chatten är avslutad. Inget av det som skrevs finns kvar.']);
    chatPost($this->starter, 'send', ['room' => $this->room, ...sealed()])->assertNotFound();
    // The code no longer works either.
    chatPost($this->third, 'join', ['room' => $this->room])->assertNotFound();
});

it('deletes a chat after 30 minutes without a message, and polling does not keep it alive', function () {
    chatPost($this->starter, 'start', ['room' => $this->room])->assertCreated();
    chatPost($this->joiner, 'join', ['room' => $this->room])->assertOk();

    $this->travel(20)->minutes();
    chatPost($this->starter, 'send', ['room' => $this->room, ...sealed()])->assertCreated();

    // Only polling from here on.
    $this->travel(20)->minutes();
    chatPost($this->joiner, 'poll', ['room' => $this->room])->assertOk();
    $this->travel(9)->minutes();
    chatPost($this->joiner, 'poll', ['room' => $this->room])->assertOk();

    $this->travel(2)->minutes();

    expect(chatStoreHasAny(chatKeys($this->room, $this->starter, $this->joiner)))->toBeFalse();
    chatPost($this->joiner, 'poll', ['room' => $this->room])->assertNotFound();
});

it('deletes a chat eight hours after it started, however active it is', function () {
    chatPost($this->starter, 'start', ['room' => $this->room])->assertCreated();
    chatPost($this->joiner, 'join', ['room' => $this->room])->assertOk();

    // A message every 20 minutes, the last 7 h 40 min after the start.
    for ($minutes = 20; $minutes <= 460; $minutes += 20) {
        $this->travel(20)->minutes();
        chatPost($this->starter, 'send', ['room' => $this->room, ...sealed()])->assertCreated();
    }

    $this->travel(19)->minutes();
    chatPost($this->joiner, 'poll', ['room' => $this->room])->assertOk();
    $this->travel(2)->minutes();

    expect(chatStoreHasAny(chatKeys($this->room, $this->starter, $this->joiner)))->toBeFalse();
    chatPost($this->starter, 'poll', ['room' => $this->room])->assertNotFound();
});

it('deletes an unanswered code after 30 minutes', function () {
    chatPost($this->starter, 'start', ['room' => $this->room])->assertCreated();

    $this->travel(31)->minutes();

    chatPost($this->joiner, 'join', ['room' => $this->room])->assertNotFound();
    expect(chatStoreHasAny(chatKeys($this->room, $this->starter)))->toBeFalse();
});

it('checks sizes and base64 and nothing else', function () {
    chatPost($this->starter, 'start', ['room' => 'kort'])->assertStatus(422);
    chatPost($this->starter, 'start', ['room' => $this->room])->assertCreated();

    $bad = [
        ['iv' => base64_encode(random_bytes(16)), 'ct' => base64_encode(random_bytes(64))],
        ['iv' => base64_encode(random_bytes(12)), 'ct' => base64_encode(random_bytes(4097))],
        ['iv' => base64_encode(random_bytes(12)), 'ct' => base64_encode(random_bytes(16))],
        ['iv' => base64_encode(random_bytes(12)), 'ct' => 'inte base64!'],
        ['iv' => base64_encode(random_bytes(12)), 'ct' => ['array']],
        ['iv' => base64_encode(random_bytes(12))],
    ];

    foreach ($bad as $body) {
        chatPost($this->starter, 'send', ['room' => $this->room, ...$body])->assertStatus(422);
    }

    chatPost($this->starter, 'send', ['room' => $this->room, 'iv' => base64_encode(random_bytes(12)), 'ct' => base64_encode(random_bytes(4096))])->assertCreated();
});

it('holds no more than its maximum of messages', function () {
    config(['nonox.chat.max_messages' => 3]);
    chatPost($this->starter, 'start', ['room' => $this->room])->assertCreated();

    foreach (range(1, 3) as $ignored) {
        chatPost($this->starter, 'send', ['room' => $this->room, ...sealed()])->assertCreated();
    }

    chatPost($this->starter, 'send', ['room' => $this->room, ...sealed()])->assertStatus(422);
});

it('lets a user start one chat and join one chat at a time', function () {
    chatPost($this->starter, 'start', ['room' => $this->room])->assertCreated();
    $second = chatRoomId();
    chatPost($this->starter, 'start', ['room' => $second])->assertCreated();

    expect(Cache::store('chat')->has("chat:room:{$this->room}"))->toBeFalse()
        ->and(Cache::store('chat')->has("chat:room:{$second}"))->toBeTrue();

    $other = chatRoomId();
    chatPost($this->third, 'start', ['room' => $other])->assertCreated();
    chatPost($this->joiner, 'join', ['room' => $second])->assertOk();
    chatPost($this->joiner, 'join', ['room' => $other])->assertOk();

    expect(Cache::store('chat')->has("chat:room:{$second}"))->toBeFalse()
        ->and(Cache::store('chat')->has("chat:room:{$other}"))->toBeTrue();
});

it('ends the chats of someone who signs out', function () {
    chatPost($this->starter, 'start', ['room' => $this->room])->assertCreated();
    chatPost($this->joiner, 'join', ['room' => $this->room])->assertOk();

    $this->actingAs($this->joiner)->post(route('logout'));

    expect(chatStoreHasAny(chatKeys($this->room, $this->starter, $this->joiner)))->toBeFalse();
});

it('leaves no audit entry, no database row and no log line', function () {
    Log::spy();
    $auditRows = AuditLog::query()->count();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    chatPost($this->starter, 'start', ['room' => $this->room])->assertCreated();
    chatPost($this->joiner, 'join', ['room' => $this->room])->assertOk();
    chatPost($this->starter, 'send', ['room' => $this->room, ...sealed()])->assertCreated();
    chatPost($this->joiner, 'poll', ['room' => $this->room, 'since' => 0])->assertOk();
    chatPost($this->third, 'join', ['room' => $this->room])->assertNotFound();
    chatPost($this->starter, 'end', ['room' => $this->room])->assertOk();

    expect(AuditLog::query()->count())->toBe($auditRows)
        ->and(collect($queries)->filter(fn (string $sql): bool => preg_match('/^\s*(insert|update|delete)/i', $sql) === 1)->values()->all())->toBe([])
        ->and(implode(' ', $queries))->not->toContain($this->room);

    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log', 'write'] as $level) {
        Log::shouldNotHaveReceived($level);
    }
});

it('logs a failure by its class only, never with the chat in it', function () {
    $this->app->instance(SecureChat::class, Mockery::mock(SecureChat::class, function ($mock): void {
        $mock->shouldReceive('forParticipant')->andThrow(new RuntimeException('chat:room:'.test()->room));
    }));
    Log::spy();

    chatPost($this->starter, 'poll', ['room' => $this->room])->assertStatus(500);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Encrypted chat request failed.'
        && $context === ['exception' => RuntimeException::class]);
});

it('lets Följ mina sidor open only pages, never a file, a callback, an export or an audited read', function (string $path) {
    chatPost($this->joiner, 'followable', ['path' => $path])->assertOk()->assertExactJson(['followable' => false]);
})->with([
    '/lon/arbetsgivardeklaration/1/fil',
    '/lonearkiv/arbetsgivardeklaration/1/fil',
    '/moms/1/fil',
    '/lon/korningar/1/lonefil',
    '/lon/korningar/1/underlag/2/fil',
    '/leverantorsfakturor/betalfiler/00000000-0000-0000-0000-000000000000',
    '/leverantorsfakturor/00000000-0000-0000-0000-000000000000/faktura',
    '/registerutdrag/kund/1',
    '/registerutdrag/inkorg',
    '/profil/mina-uppgifter',
    '/inkorg/00000000-0000-0000-0000-000000000000/original',
    '/inkorg/00000000-0000-0000-0000-000000000000/bilagor/2',
    '/planering/kalender/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.ics',
    '/bank/klar',
    '/bank/testbank',
    '/anslutningar/kassa/zettle/klar',
    '/flytt/fortnox/klar',
    '/fakturor/00000000-0000-0000-0000-000000000000/pdf',
    '/arsredovisning/1/pdf',
    '/rakenskapsar/1/sie-fil',
    '/rapporter/csv',
    '/verifikationer/00000000-0000-0000-0000-000000000000/underlag/1',
    '/foretagsinbjudningar/1',
    '/personalliggare/kiosk/start',
    '/personalliggare/kontrollbesok',
    '/hq/kunder',
    '/mina-uppgifter',
    '/logga-ut',
    '/aktuellt-foretag',
    '/chatt/hamta',
    '/finns-inte/alls/har',
    'https://evil.example/oversikt',
    '//evil.example/oversikt',
    '/oversikt\\x',
    '',
]);

it('lets Följ mina sidor open ordinary pages', function (string $path) {
    chatPost($this->joiner, 'followable', ['path' => $path])->assertOk()->assertExactJson(['followable' => true]);
})->with([
    '/oversikt',
    '/kontoplan',
    '/verifikationer',
    '/verifikationer?sida=2',
    '/verifikationer/00000000-0000-0000-0000-000000000000',
    '/fakturor',
    '/fakturor/00000000-0000-0000-0000-000000000000',
    '/leverantorsfakturor/00000000-0000-0000-0000-000000000000',
    '/moms',
    '/rapporter?rapport=resultat',
    '/bank',
    '/uppgifter/4',
    '/profil',
    '/mina-foretag',
]);

it('answers whether a page can be followed without writing or logging anything', function () {
    Log::spy();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    chatPost($this->joiner, 'followable', ['path' => '/kontoplan'])->assertOk();
    chatPost($this->joiner, 'followable', ['path' => '/moms/1/fil'])->assertOk();

    expect(collect($queries)->filter(fn (string $sql): bool => preg_match('/^\s*(insert|update|delete)/i', $sql) === 1)->values()->all())->toBe([]);

    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log', 'write'] as $level) {
        Log::shouldNotHaveReceived($level);
    }
});

it('deletes expired chat files when the store is files, and keeps the rest', function () {
    $path = storage_path('framework/testing/chat-'.bin2hex(random_bytes(4)));
    config(['cache.stores.chat' => ['driver' => 'file', 'path' => $path]]);
    mkdir("{$path}/ab/cd", 0755, true);
    file_put_contents("{$path}/ab/cd/expired", (now()->getTimestamp() - 1).serialize('ciphertext'));
    file_put_contents("{$path}/ab/cd/alive", (now()->getTimestamp() + 600).serialize('ciphertext'));

    $this->artisan('chat:prune')->assertSuccessful();

    expect(file_exists("{$path}/ab/cd/expired"))->toBeFalse()
        ->and(file_exists("{$path}/ab/cd/alive"))->toBeTrue();

    unlink("{$path}/ab/cd/alive");
    rmdir("{$path}/ab/cd");
    rmdir("{$path}/ab");
    rmdir($path);
});

it('tells the browser which companies the user belongs to, and only theirs', function () {
    $response = chatPost($this->joiner, 'companies')->assertOk();

    expect($response->json('current'))->toBe($this->joiner->currentCompany()->id)
        ->and(array_column($response->json('companies'), 'id'))->toBe([$this->joiner->currentCompany()->id])
        ->and(array_column($response->json('companies'), 'id'))->not->toContain($this->starter->currentCompany()->id);
});

it('shows the chat on every signed-in page, also without a company', function () {
    $this->actingAs($this->starter)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('secureChat(', false)
        ->assertSee('data-chat-trigger', false)
        ->assertSee('Krypterad från din webbläsare till den andras. Allt raderas när chatten avslutas, och senast efter 30 minuter utan meddelanden.');

    $this->actingAs($this->third)->get(route('onboarding'))
        ->assertOk()
        ->assertSee('secureChat(', false);
});
