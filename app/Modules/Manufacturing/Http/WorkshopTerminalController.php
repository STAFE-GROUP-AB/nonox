<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http;

use App\Modules\Manufacturing\Actions\Workshop\RecordTerminalTime;
use App\Modules\Manufacturing\Actions\Workshop\WaitingFlags;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopDevices;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopSessions;
use App\Modules\Manufacturing\Domain\WaitingKind;
use App\Modules\Manufacturing\Http\Middleware\AuthenticateWorkshopDevice;
use App\Modules\Manufacturing\Http\Middleware\AuthenticateWorkshopPerson;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Models\WorkshopPerson;
use App\Modules\Manufacturing\Support\TerminalData;
use App\Modules\Manufacturing\Support\TerminalStrings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The workshop terminal (ADR-071 §7): an installable page with its manifest,
 * icon and service worker, which need no login, and the JSON endpoints it
 * calls with its device token (and, after a worker has typed their code,
 * the session token). The page itself holds no company data; everything
 * comes from the endpoints after pairing. Online only: a report that could
 * not be sent is not shown as done.
 */
class WorkshopTerminalController
{
    public const string ENTRY_JS = 'app/Modules/Manufacturing/resources/js/workshop.js';

    public const string SCOPE = '/verkstad/terminal/';

    public function shell(): Response
    {
        return response()->view('manufacturing::terminal', [
            'config' => [
                'strings' => TerminalStrings::all(),
                'urls' => [
                    'shell' => route('manufacturing.terminal', absolute: false),
                    'scope' => self::SCOPE,
                    'serviceWorker' => route('manufacturing.terminal.worker', absolute: false),
                    'pair' => route('manufacturing.terminal.pair', absolute: false),
                    'people' => route('manufacturing.terminal.people', absolute: false),
                    'signIn' => route('manufacturing.terminal.sign-in', absolute: false),
                    'orders' => route('manufacturing.terminal.orders', absolute: false),
                    'report' => route('manufacturing.terminal.report', absolute: false),
                    'correct' => route('manufacturing.terminal.correct', absolute: false),
                    'wait' => route('manufacturing.terminal.wait', absolute: false),
                    'clearWait' => route('manufacturing.terminal.clear-wait', absolute: false),
                ],
            ],
        ])->header('Cache-Control', 'no-cache');
    }

    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => 'NONOX Verkstadsterminal',
            'short_name' => 'Verkstad',
            'lang' => 'sv',
            'start_url' => route('manufacturing.terminal', absolute: false),
            'scope' => self::SCOPE,
            'display' => 'fullscreen',
            'orientation' => 'any',
            'background_color' => '#f7f5f0',
            'theme_color' => '#17171a',
            'icons' => [['src' => route('manufacturing.terminal.icon', absolute: false), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any maskable']],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    public function icon(): Response
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect width="512" height="512" rx="96" fill="#17171a"/>'
            .'<circle cx="256" cy="256" r="120" fill="none" stroke="#f7f5f0" stroke-width="32"/><path d="M256 168v96l64 40" fill="none" stroke="#ff4a1c" stroke-width="32" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=86400']);
    }

    public function worker(): Response
    {
        $precache = [
            route('manufacturing.terminal', absolute: false),
            route('manufacturing.terminal.manifest', absolute: false),
            route('manufacturing.terminal.icon', absolute: false),
            ...self::assets(),
        ];

        $script = 'const PRECACHE = '.json_encode($precache, JSON_UNESCAPED_SLASHES).";\n"
            .'const VERSION = '.json_encode(substr(hash('sha256', implode('|', $precache)), 0, 12)).";\n"
            .'const SHELL = '.json_encode(route('manufacturing.terminal', absolute: false), JSON_UNESCAPED_SLASHES).";\n"
            .(string) file_get_contents(dirname(__DIR__).'/resources/js/workshop-sw.js');

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => self::SCOPE,
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function pair(Request $request, WorkshopDevices $devices): JsonResponse
    {
        $paired = $devices->pair((string) $request->input('code', ''));

        if ($paired === null) {
            return response()->json(['error' => 'invalid_code'], 422);
        }

        [$device, $token] = $paired;

        return response()->json(['token' => $token, 'device' => ['id' => $device->id, 'name' => $device->name]]);
    }

    public function people(Request $request): JsonResponse
    {
        $device = self::device($request);
        $device->forceFill(['last_seen_at' => now()])->save();

        return response()->json(['people' => TerminalData::people()], 200, ['Cache-Control' => 'no-store']);
    }

    public function signIn(Request $request, WorkshopSessions $sessions): JsonResponse
    {
        $personId = $request->input('person_id');
        $code = $request->input('code');

        if (! is_int($personId) || ! is_string($code)) {
            return response()->json(['error' => 'invalid'], 422);
        }

        $result = $sessions->signIn(self::device($request), $personId, $code);

        if ($result === null) {
            return response()->json(['ok' => false, 'locked' => false]);
        }

        if (isset($result['locked'])) {
            return response()->json(['ok' => false, 'locked' => true], 429);
        }

        return response()->json(['ok' => true, 'token' => $result['token'], 'name' => $result['name']], 200, ['Cache-Control' => 'no-store']);
    }

    public function orders(Request $request, WorkshopSessions $sessions): JsonResponse
    {
        $person = self::person($request);

        return response()->json([
            'session' => $sessions->refresh(self::device($request), $person),
            'orders' => TerminalData::orders($person),
            'mine' => TerminalData::mine($person),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function report(Request $request, RecordTerminalTime $record, WorkshopSessions $sessions): JsonResponse
    {
        $input = $request->only(['operation_id', 'hours', 'minutes']);

        if (! is_int($input['operation_id'] ?? null) || ! is_int($input['hours'] ?? null) || ! is_int($input['minutes'] ?? null)) {
            return response()->json(['error' => 'invalid', 'message' => __('Skriv timmar och minuter som hela tal.')], 422);
        }

        return $this->answer($request, $sessions, function () use ($request, $record, $input): void {
            $record(self::device($request), self::person($request), $input['operation_id'], $input['hours'], $input['minutes']);
        });
    }

    public function correct(Request $request, RecordTerminalTime $record, WorkshopSessions $sessions): JsonResponse
    {
        $entryId = $request->input('entry_id');

        if (! is_int($entryId)) {
            return response()->json(['error' => 'invalid'], 422);
        }

        return $this->answer($request, $sessions, function () use ($request, $record, $entryId): void {
            $record->correct(self::device($request), self::person($request), $entryId);
        });
    }

    public function wait(Request $request, WaitingFlags $flags, WorkshopSessions $sessions): JsonResponse
    {
        $operationId = $request->input('operation_id');
        $kind = is_string($request->input('kind')) ? WaitingKind::tryFrom($request->input('kind')) : null;
        $reason = $request->input('reason');

        if (! is_int($operationId) || $kind === null || ! is_string($reason)) {
            return response()->json(['error' => 'invalid'], 422);
        }

        return $this->answer($request, $sessions, fn () => $flags->raise(self::device($request), $operationId, $kind, $reason));
    }

    public function clearWait(Request $request, WaitingFlags $flags, WorkshopSessions $sessions): JsonResponse
    {
        $operationId = $request->input('operation_id');

        if (! is_int($operationId)) {
            return response()->json(['error' => 'invalid'], 422);
        }

        return $this->answer($request, $sessions, fn () => $flags->clear(self::device($request), $operationId));
    }

    /**
     * Runs a write and answers with the refreshed lists, or with the first
     * validation message in Swedish.
     *
     * @param  callable(): mixed  $write
     */
    private function answer(Request $request, WorkshopSessions $sessions, callable $write): JsonResponse
    {
        try {
            $write();
        } catch (ValidationException $exception) {
            return response()->json(['error' => 'rejected', 'message' => (string) collect($exception->errors())->flatten()->first()], 422);
        }

        $person = self::person($request);

        return response()->json([
            'ok' => true,
            'session' => $sessions->refresh(self::device($request), $person),
            'orders' => TerminalData::orders($person),
            'mine' => TerminalData::mine($person),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    private static function device(Request $request): WorkshopDevice
    {
        $device = $request->attributes->get(AuthenticateWorkshopDevice::ATTRIBUTE);
        abort_unless($device instanceof WorkshopDevice, 401);

        return $device;
    }

    private static function person(Request $request): WorkshopPerson
    {
        $person = $request->attributes->get(AuthenticateWorkshopPerson::ATTRIBUTE);
        abort_unless($person instanceof WorkshopPerson, 401);

        return $person;
    }

    /**
     * The built files of the terminal entry, its imports and lazily loaded
     * chunks, from Vite's manifest.
     *
     * @return list<string>
     */
    private static function assets(): array
    {
        $path = public_path('build/manifest.json');

        if (! is_file($path)) {
            return [];
        }

        /** @var array<string, array{file: string, css?: list<string>, imports?: list<string>, dynamicImports?: list<string>}> $manifest */
        $manifest = json_decode((string) file_get_contents($path), true) ?: [];
        $files = [];
        $visit = function (string $key) use (&$visit, &$files, $manifest): void {
            $chunk = $manifest[$key] ?? null;

            if ($chunk === null || in_array('/build/'.$chunk['file'], $files, true)) {
                return;
            }

            $files[] = '/build/'.$chunk['file'];

            foreach ($chunk['css'] ?? [] as $css) {
                $files[] = '/build/'.$css;
            }

            foreach ([...($chunk['imports'] ?? []), ...($chunk['dynamicImports'] ?? [])] as $import) {
                $visit($import);
            }
        };

        $visit(self::ENTRY_JS);

        return array_values(array_unique($files));
    }
}
