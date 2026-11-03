<?php

declare(strict_types=1);

namespace App\Support\Chat;

use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The encrypted chat between two signed-in users (ADR-045). The browsers
 * encrypt everything; this class only keeps ciphertext, in the cache store
 * "chat", never in the database, and forgets it all when the chat ends, after
 * 30 minutes without a message or join, or 8 hours after it started.
 *
 * Keys, all with the same time to live:
 *   chat:room:{room}   who is in it (ChatRoom)
 *   chat:msgs:{room}   the encrypted messages
 *   chat:user:{id}:a   the chat the user started, and :b the one they joined
 *
 * Nothing here writes to the audit log or the application log: a chat leaves
 * no trace once it is gone (the owner's decision).
 */
class SecureChat
{
    public const string SLOT_STARTER = 'a';

    public const string SLOT_JOINER = 'b';

    /** A room id is 32 bytes from HKDF, base64url without padding. */
    public const string ROOM_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public function __construct(private readonly CacheFactory $cache) {}

    /** The chat's own endpoints, whose errors and fields must never reach a log (bootstrap/app.php). */
    public static function handles(Request $request): bool
    {
        return $request->is('chatt/*');
    }

    public function store(): Repository
    {
        return $this->cache->store('chat');
    }

    /**
     * Counts one attempt to start or join for the user and the address; false
     * when either has used up its attempts. The counters expire on their own.
     */
    public function allowAttempt(User $user, string $ip): bool
    {
        $limiter = new RateLimiter($this->store());
        $decay = (int) config('nonox.chat.join_decay_seconds', 600);
        // Many people can share one address (an office), so the address gets a looser limit than the user.
        // The address is keyed by an HMAC with the app key, so the counter does not hold it in a guessable form.
        $limits = [
            'chat-attempt:user:'.$user->getKey() => (int) config('nonox.chat.join_attempts', 10),
            'chat-attempt:ip:'.hash_hmac('sha256', $ip, (string) config('app.key')) => (int) config('nonox.chat.join_attempts_per_ip', 60),
        ];

        foreach ($limits as $key => $max) {
            if ($limiter->tooManyAttempts($key, $max)) {
                return false;
            }
        }

        foreach (array_keys($limits) as $key) {
            $limiter->hit($key, $decay);
        }

        return true;
    }

    /**
     * Opens a chat under a room id the starter's browser derived from the
     * code. Null when the id is taken (the browser makes a new code). A chat
     * the user started before is ended: one at a time.
     */
    public function start(User $user, string $room): ?ChatRoom
    {
        $previous = $this->store()->get($this->indexKey((int) $user->getKey(), self::SLOT_STARTER));

        $started = $this->locked($room, function () use ($user, $room): ?ChatRoom {
            if ($this->find($room) instanceof ChatRoom) {
                return null;
            }

            $now = $this->now();
            $chat = new ChatRoom($room, (int) $user->getKey(), (string) $user->name, null, null, $now, $now, 0);

            $this->save($chat, []);

            return $chat;
        });

        if ($started instanceof ChatRoom && is_string($previous) && $previous !== $room) {
            $this->end($user, $previous);
        }

        return $started;
    }

    /**
     * Takes slot B. Null for a room that does not exist, is already full, or
     * was started by the same user: the caller answers all three the same.
     */
    public function join(User $user, string $room): ?ChatRoom
    {
        $userId = (int) $user->getKey();
        $previous = $this->store()->get($this->indexKey($userId, self::SLOT_JOINER));

        $joined = $this->locked($room, function () use ($user, $userId, $room): ?ChatRoom {
            $chat = $this->find($room);

            if (! $chat instanceof ChatRoom || $chat->starterId === $userId) {
                return null;
            }

            if ($chat->joinerId === $userId) {
                return $chat;
            }

            if ($chat->joinerId !== null) {
                return null;
            }

            $joined = $chat->with(joinerId: $userId, joinerName: (string) $user->name, activeAt: $this->now());
            $this->save($joined, $this->messages($chat));

            return $joined;
        });

        // Only a successful join ends the chat the user had joined before: a wrong code changes nothing.
        if ($joined instanceof ChatRoom && is_string($previous) && $previous !== $room) {
            $this->end($user, $previous);
        }

        return $joined;
    }

    /** Whether the user started this chat: only they can learn it, and they already hold its code. */
    public function startedBy(User $user, string $room): bool
    {
        $chat = $this->find($room);

        return $chat instanceof ChatRoom && $chat->starterId === (int) $user->getKey();
    }

    /** The chat, when the user is one of its two people; null otherwise. */
    public function forParticipant(User $user, string $room): ?ChatRoom
    {
        $chat = $this->find($room);

        return $chat instanceof ChatRoom && $chat->slotOf((int) $user->getKey()) !== null ? $chat : null;
    }

    /**
     * @return list<array{seq: int, from: string, iv: string, ct: string, at: int}>
     */
    public function messagesSince(ChatRoom $chat, int $since): array
    {
        if ($since >= $chat->sequence) {
            return [];
        }

        return array_values(array_filter($this->messages($chat), fn (array $message): bool => $message['seq'] > $since));
    }

    /**
     * Stores one encrypted message from the user. Null when the user is not in
     * the chat (any more); false when the chat holds its maximum.
     *
     * @return array{seq: int, at: int}|false|null
     */
    public function send(User $user, string $room, string $iv, string $ciphertext): array|false|null
    {
        return $this->locked($room, function () use ($user, $room, $iv, $ciphertext): array|false|null {
            $chat = $this->forParticipant($user, $room);

            if (! $chat instanceof ChatRoom) {
                return null;
            }

            $messages = $this->messages($chat);

            if (count($messages) >= (int) config('nonox.chat.max_messages', 500)) {
                return false;
            }

            $now = $this->now();
            $sequence = $chat->sequence + 1;
            $messages[] = ['seq' => $sequence, 'from' => (string) $chat->slotOf((int) $user->getKey()), 'iv' => $iv, 'ct' => $ciphertext, 'at' => $now];

            $this->save($chat->with(activeAt: $now, sequence: $sequence), $messages);

            return ['seq' => $sequence, 'at' => $now];
        });
    }

    /** Ends the chat for both people and deletes everything in it. False when the user is not in it. */
    public function end(User $user, string $room): bool
    {
        // Under the chat's lock, so a send that is running cannot write it back after it is gone.
        return $this->locked($room, function () use ($user, $room): bool {
            $chat = $this->forParticipant($user, $room);

            if (! $chat instanceof ChatRoom) {
                return false;
            }

            $this->forget($chat);

            return true;
        });
    }

    /** Ends every chat the user is in, for example when they sign out. */
    public function endAllFor(User $user): void
    {
        $this->endIndexed($user, self::SLOT_STARTER);
        $this->endIndexed($user, self::SLOT_JOINER);
    }

    /** When the chat is deleted at the latest: the idle limit or the absolute one, whichever comes first. */
    public function expiresAt(ChatRoom $chat): int
    {
        return min(
            $chat->activeAt + (int) config('nonox.chat.idle_seconds', 1800),
            $chat->createdAt + (int) config('nonox.chat.max_seconds', 28800),
        );
    }

    private function find(string $room): ?ChatRoom
    {
        $data = $this->store()->get($this->roomKey($room));
        $chat = is_array($data) ? ChatRoom::fromArray($room, $data) : null;

        if (! $chat instanceof ChatRoom) {
            return null;
        }

        // Every key expires at this moment in the store too (save() sets it); this makes the limit exact
        // without deleting outside the chat's lock.
        return $this->expiresAt($chat) > $this->now() ? $chat : null;
    }

    /**
     * @return list<array{seq: int, from: string, iv: string, ct: string, at: int}>
     */
    private function messages(ChatRoom $chat): array
    {
        $messages = $this->store()->get($this->messagesKey($chat->id));

        if (! is_array($messages)) {
            return [];
        }

        /** @var list<array{seq: int, from: string, iv: string, ct: string, at: int}> $list */
        $list = array_values($messages);

        return $list;
    }

    /**
     * @param  list<array{seq: int, from: string, iv: string, ct: string, at: int}>  $messages
     */
    private function save(ChatRoom $chat, array $messages): void
    {
        $ttl = $this->expiresAt($chat) - $this->now();

        if ($ttl <= 0) {
            $this->forget($chat);

            return;
        }

        $store = $this->store();
        $store->put($this->roomKey($chat->id), $chat->toArray(), $ttl);
        $store->put($this->messagesKey($chat->id), $messages, $ttl);
        $store->put($this->indexKey($chat->starterId, self::SLOT_STARTER), $chat->id, $ttl);

        if ($chat->joinerId !== null) {
            $store->put($this->indexKey($chat->joinerId, self::SLOT_JOINER), $chat->id, $ttl);
        }
    }

    private function forget(ChatRoom $chat): void
    {
        $store = $this->store();
        $store->forget($this->roomKey($chat->id));
        $store->forget($this->messagesKey($chat->id));

        foreach ([self::SLOT_STARTER => $chat->starterId, self::SLOT_JOINER => $chat->joinerId] as $slot => $userId) {
            if ($userId !== null && $store->get($this->indexKey($userId, $slot)) === $chat->id) {
                $store->forget($this->indexKey($userId, $slot));
            }
        }
    }

    private function endIndexed(User $user, string $slot): void
    {
        $room = $this->store()->get($this->indexKey((int) $user->getKey(), $slot));

        if (is_string($room)) {
            $this->end($user, $room);
        }

        $this->store()->forget($this->indexKey((int) $user->getKey(), $slot));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(string $room, callable $callback): mixed
    {
        $store = $this->store()->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('The chat store must support locks.');
        }

        return $store->lock('chat:lock:'.$room, 10)->block((int) config('nonox.chat.lock_wait_seconds', 5), $callback);
    }

    private function now(): int
    {
        return Carbon::now()->getTimestamp();
    }

    private function roomKey(string $room): string
    {
        return 'chat:room:'.$room;
    }

    private function messagesKey(string $room): string
    {
        return 'chat:msgs:'.$room;
    }

    private function indexKey(int $userId, string $slot): string
    {
        return "chat:user:{$userId}:{$slot}";
    }
}
