<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Http;

use App\Modules\StaffLedger\Actions\KioskDevices;
use App\Modules\StaffLedger\Actions\OpenControlVisit;
use App\Modules\StaffLedger\Actions\RecordKioskEvents;
use App\Modules\StaffLedger\Http\Middleware\AuthenticateKioskDevice;
use App\Modules\StaffLedger\Models\Device;
use App\Modules\StaffLedger\Support\KioskRoster;
use App\Modules\StaffLedger\Support\KioskStrings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Vite;

/**
 * The kiosk (ADR-022): an installable page with its manifest, icon and
 * service worker, which need no login, and the JSON endpoints it calls with
 * its device token. The page itself holds no company data; everything comes
 * from the endpoints after pairing.
 */
class KioskController
{
    public const string ENTRY_JS = 'app/Modules/StaffLedger/resources/js/kiosk.js';

    /** The camera for QR codes is allowed on the kiosk page only. */
    private const string PERMISSIONS = 'camera=(self), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()';

    public function shell(): Response
    {
        return response()->view('staff-ledger::kiosk', [
            'config' => [
                'strings' => KioskStrings::all(),
                'languages' => KioskStrings::LANGUAGES,
                'rtl' => KioskStrings::RTL,
                'urls' => [
                    'shell' => route('staff-ledger.kiosk', absolute: false),
                    'scope' => '/personalliggare/kiosk/',
                    'serviceWorker' => route('staff-ledger.kiosk.worker', absolute: false),
                    'pair' => route('staff-ledger.kiosk.pair', absolute: false),
                    'roster' => route('staff-ledger.kiosk.roster', absolute: false),
                    'events' => route('staff-ledger.kiosk.events', absolute: false),
                    'controlVisit' => route('staff-ledger.kiosk.control-visit', absolute: false),
                ],
            ],
        ])->header('Permissions-Policy', self::PERMISSIONS)->header('Cache-Control', 'no-cache');
    }

    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => 'NONOX Personalliggare',
            'short_name' => 'Personalliggare',
            'lang' => 'sv',
            'start_url' => route('staff-ledger.kiosk', absolute: false),
            'scope' => '/personalliggare/kiosk/',
            'display' => 'fullscreen',
            'orientation' => 'any',
            'background_color' => '#f7f5f0',
            'theme_color' => '#17171a',
            'icons' => [['src' => route('staff-ledger.kiosk.icon', absolute: false), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any maskable']],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    public function icon(): Response
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect width="512" height="512" rx="96" fill="#17171a"/>'
            .'<circle cx="256" cy="200" r="72" fill="#ff4a1c"/><path d="M128 400c0-70 57-120 128-120s128 50 128 120" fill="none" stroke="#f7f5f0" stroke-width="40" stroke-linecap="round"/></svg>';

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=86400']);
    }

    public function worker(): Response
    {
        $precache = [
            route('staff-ledger.kiosk', absolute: false),
            route('staff-ledger.kiosk.manifest', absolute: false),
            route('staff-ledger.kiosk.icon', absolute: false),
            ...self::assets(),
        ];

        $script = 'const PRECACHE = '.json_encode($precache, JSON_UNESCAPED_SLASHES).";\n"
            .'const VERSION = '.json_encode(substr(hash('sha256', implode('|', $precache)), 0, 12)).";\n"
            .'const SHELL = '.json_encode(route('staff-ledger.kiosk', absolute: false), JSON_UNESCAPED_SLASHES).";\n"
            .(string) file_get_contents(dirname(__DIR__).'/resources/js/kiosk-sw.js');

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/personalliggare/kiosk/',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function pair(Request $request, KioskDevices $devices): JsonResponse
    {
        $paired = $devices->pair((string) $request->input('code', ''));

        if ($paired === null) {
            return response()->json(['error' => 'invalid_code'], 422);
        }

        [$device, $token] = $paired;

        return response()->json(['token' => $token, 'device' => ['id' => $device->id, 'name' => $device->name]]);
    }

    public function roster(Request $request): JsonResponse
    {
        $device = self::device($request);
        $device->forceFill(['last_seen_at' => now()])->save();

        return response()->json(KioskRoster::for($device), 200, ['Cache-Control' => 'no-store']);
    }

    public function events(Request $request, RecordKioskEvents $record): JsonResponse
    {
        $events = $request->input('events', []);
        $logs = $request->input('logs', []);

        if (! is_array($events) || ! is_array($logs) || count($events) > 500 || count($logs) > 100) {
            return response()->json(['error' => 'invalid'], 422);
        }

        $sentAt = $request->input('sent_at');

        return response()->json($record(
            self::device($request),
            array_values(array_filter($events, 'is_array')),
            is_string($sentAt) ? $sentAt : null,
            array_values(array_filter($logs, 'is_array')),
        ));
    }

    public function controlVisit(Request $request, OpenControlVisit $open): JsonResponse
    {
        $device = self::device($request);

        if (OpenControlVisit::lockedOut($device)) {
            return response()->json(['ok' => false, 'locked' => true], 429);
        }

        $manager = $open->atKiosk($device, (string) $request->input('pin', ''));

        if ($manager === null) {
            return response()->json(['ok' => false, 'locked' => OpenControlVisit::lockedOut($device)]);
        }

        return response()->json(['ok' => true, 'roster' => KioskRoster::for($device)], 200, ['Cache-Control' => 'no-store']);
    }

    private static function device(Request $request): Device
    {
        $device = $request->attributes->get(AuthenticateKioskDevice::ATTRIBUTE);
        abort_unless($device instanceof Device, 401);

        return $device;
    }

    /**
     * The built files of the kiosk entry, its imports and lazily loaded
     * chunks (jsQR), from Vite's manifest.
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
