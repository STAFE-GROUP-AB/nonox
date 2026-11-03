<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Support\Chat\ChatRoom;
use App\Support\Chat\FollowablePath;
use App\Support\Chat\SecureChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The encrypted chat's endpoints (ADR-045). Technical JSON endpoints that
 * nobody sees: the room id travels in the POST body, never in the path, so
 * not even the web server's access log holds it. The server checks sizes and
 * base64 only; it cannot read anything.
 */
final class SecureChatController
{
    public function __construct(private readonly SecureChat $chat) {}

    public function start(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $room = $this->room($request);

        if ($room === null) {
            return $this->invalid();
        }

        if (! $this->chat->allowAttempt($user, (string) $request->ip())) {
            return $this->tooMany();
        }

        $chat = $this->chat->start($user, $room);

        if (! $chat instanceof ChatRoom) {
            // The browser makes a new code and tries again.
            return response()->json(['message' => __('Koden är upptagen. Försök igen.')], 409);
        }

        return response()->json($this->state($chat, $user), 201);
    }

    public function join(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $room = $this->room($request);

        if ($room === null) {
            return $this->notFound();
        }

        if (! $this->chat->allowAttempt($user, (string) $request->ip())) {
            return $this->tooMany();
        }

        $chat = $this->chat->join($user, $room);

        // Unknown, already full and your own chat get the same answer: the code tells nobody anything.
        return $chat instanceof ChatRoom ? response()->json($this->state($chat, $user)) : $this->notFound();
    }

    public function poll(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $room = $this->room($request);
        $chat = $room === null ? null : $this->chat->forParticipant($user, $room);

        if (! $chat instanceof ChatRoom) {
            return $this->ended();
        }

        $since = max(0, $request->integer('since'));

        return response()->json([
            ...$this->state($chat, $user),
            'messages' => $this->chat->messagesSince($chat, $since),
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $room = $this->room($request);
        $iv = $request->input('iv');
        $ciphertext = $request->input('ct');

        if ($room === null || ! $this->isBase64($iv, 12, 12) || ! $this->isBase64($ciphertext, 17, (int) config('nonox.chat.max_ciphertext_bytes', 4096))) {
            return $this->invalid();
        }

        /** @var string $iv */
        /** @var string $ciphertext */
        $sent = $this->chat->send($user, $room, $iv, $ciphertext);

        if ($sent === null) {
            return $this->ended();
        }

        if ($sent === false) {
            return response()->json(['message' => __('Chatten rymmer inte fler meddelanden. Avsluta den och starta en ny.')], 422);
        }

        return response()->json($sent, 201);
    }

    public function end(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $room = $this->room($request);

        if ($room !== null) {
            $this->chat->end($user, $room);
        }

        // Ended, or already gone: the same for the browser.
        return response()->json(['ended' => true]);
    }

    /**
     * The user's own companies, so their browser can decide by itself whether
     * it may follow a page. The followed company never reaches the server.
     */
    public function companies(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'current' => $user->currentCompany()?->getKey(),
            'companies' => $user->companies()
                ->map(fn (Company $company): array => ['id' => $company->getKey(), 'name' => $company->name])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Whether "Följ mina sidor" may open a path in this user's browser: only
     * pages, never a file, a callback or an audited read (FollowablePath).
     * The path is not secret from the server, since opening the page would
     * request it anyway; nothing is written or logged.
     */
    public function followable(Request $request, FollowablePath $paths): JsonResponse
    {
        $this->user($request);

        return response()->json(['followable' => $paths->allows($request->input('path'))]);
    }

    /**
     * @return array{you: string, peer: ?string, joined: bool, seq: int, expires_at: int, max_at: int}
     */
    private function state(ChatRoom $chat, User $user): array
    {
        $userId = (int) $user->getKey();

        return [
            'you' => (string) $chat->slotOf($userId),
            'peer' => $chat->peerNameFor($userId),
            'joined' => $chat->joinerId !== null,
            'seq' => $chat->sequence,
            'expires_at' => $this->chat->expiresAt($chat),
            'max_at' => $chat->createdAt + (int) config('nonox.chat.max_seconds', 28800),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function room(Request $request): ?string
    {
        $room = $request->input('room');

        return is_string($room) && preg_match(SecureChat::ROOM_PATTERN, $room) === 1 ? $room : null;
    }

    private function isBase64(mixed $value, int $minBytes, int $maxBytes): bool
    {
        if (! is_string($value) || $value === '' || strlen($value) > (int) ceil($maxBytes / 3) * 4) {
            return false;
        }

        if (preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $value) !== 1) {
            return false;
        }

        $bytes = base64_decode($value, true);

        return is_string($bytes) && strlen($bytes) >= $minBytes && strlen($bytes) <= $maxBytes;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => __('Det finns ingen chatt att ansluta till med den koden.')], 404);
    }

    private function ended(): JsonResponse
    {
        return response()->json(['message' => __('Chatten är avslutad. Inget av det som skrevs finns kvar.'), 'ended' => true], 404);
    }

    private function invalid(): JsonResponse
    {
        return response()->json(['message' => __('Meddelandet kunde inte tas emot.')], 422);
    }

    private function tooMany(): JsonResponse
    {
        return response()->json(['message' => __('För många försök. Vänta tio minuter och försök igen.')], 429);
    }
}
