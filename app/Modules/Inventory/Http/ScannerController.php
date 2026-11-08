<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\RunScanOperation;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\ArticleImage;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Scanner\ScanCode;
use App\Modules\Inventory\Scanner\ScannerData;
use App\Modules\Inventory\Scanner\ScannerExtensions;
use App\Modules\Inventory\Scanner\ScannerStrings;
use App\Modules\StaffLedger\Support\QrSvg;
use App\Support\Files\StoredFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Skanna (ADR-040): the phone-first scanning page under /lager/skanna, its
 * service worker, and the JSON endpoints it calls with the signed-in
 * session. The page holds no company data: the reference data comes from
 * data(), and every change goes through operations(), which runs each
 * operation once (RunScanOperation). The company is always the session's.
 */
class ScannerController
{
    public const string ENTRY_JS = 'app/Modules/Inventory/resources/js/scanner.js';

    public const string SCOPE = '/lager/skanna';

    /** The camera is allowed on the scanning page only. */
    private const string PERMISSIONS = 'camera=(self), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()';

    public function shell(Request $request): Response
    {
        [$user, $company] = self::context($request);
        self::authorizeUse($user, $company);

        $url = route('inventory.scanner');

        return response()->view('inventory::scanner', [
            'qr' => QrSvg::render($url, 220),
            'url' => $url,
            'config' => [
                'strings' => ScannerStrings::all(),
                'urls' => [
                    'shell' => route('inventory.scanner', absolute: false),
                    'scope' => self::SCOPE,
                    'serviceWorker' => route('inventory.scanner.worker', absolute: false),
                    'data' => route('inventory.scanner.data', absolute: false),
                    'operations' => route('inventory.scanner.operations', absolute: false),
                    'lookup' => route('inventory.scanner.lookup', absolute: false),
                    'app' => route('inventory.overview', absolute: false),
                    // Through the page itself, so signing in returns to Skanna and not to the full app.
                    'login' => route('inventory.scanner', absolute: false),
                ],
            ],
        ])->header('Permissions-Policy', self::PERMISSIONS)->header('Cache-Control', 'no-cache, private');
    }

    public function data(Request $request): JsonResponse
    {
        [$user, $company] = self::context($request);
        self::authorizeUse($user, $company);

        return response()->json(ScannerData::for($user, $company), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Operations queued on the phone, oldest first. company_id is only
     * compared with the session's company, so a queue made in one company
     * is never sent into another; it never chooses the company.
     */
    public function operations(Request $request, RunScanOperation $run): JsonResponse
    {
        [$user, $company] = self::context($request);
        abort_unless(Gate::forUser($user)->allows('count', [StockMovement::class, $company]) || app(ScannerExtensions::class)->allowed($user, $company) !== [], 403);

        $operations = $request->input('operations');

        if (! is_array($operations) || count($operations) > 100) {
            return response()->json(['error' => 'invalid'], 422);
        }

        if ((int) $request->input('company_id') !== $company->id) {
            return response()->json(['error' => 'company_changed', 'company_id' => $company->id], 409);
        }

        $results = [];

        foreach (array_values(array_filter($operations, 'is_array')) as $operation) {
            $payload = $operation['payload'] ?? [];
            $results[] = $run(
                $user,
                $company,
                is_string($operation['uuid'] ?? null) ? $operation['uuid'] : '',
                is_string($operation['kind'] ?? null) ? $operation['kind'] : '',
                is_array($payload) ? $payload : [],
            );
        }

        return response()->json(['results' => $results], 200, ['Cache-Control' => 'no-store']);
    }

    /** Slå upp: what a code is, with stock per location. Never a price. */
    public function lookup(Request $request): JsonResponse
    {
        [$user, $company] = self::context($request);
        self::authorizeUse($user, $company);

        $found = ScanCode::resolve((string) $request->query('kod', ''));

        if ($found['type'] === 'article') {
            $article = $found['article']->load(['images' => fn ($query) => $query->orderBy('position')->orderBy('id')]);
            $levels = StockLevel::query()->where('article_id', $article->id)->where('quantity_milli', '<>', 0)
                ->with('location:id,full_code,name,sort_key')->get()
                ->sortBy(fn (StockLevel $level): string => $level->location->sort_key ?? '');

            return response()->json([
                'type' => 'article',
                'article' => ScannerData::article($article),
                'stock' => $levels->map(fn (StockLevel $level): array => [
                    'location_id' => $level->location_id,
                    'code' => $level->location?->full_code,
                    'quantity' => $level->quantity_milli,
                    'text' => Quantity::format($level->quantity_milli),
                ])->values()->all(),
            ]);
        }

        if ($found['type'] === 'location') {
            $location = $found['location'];
            $levels = StockLevel::query()->where('location_id', $location->id)->where('quantity_milli', '<>', 0)
                ->with('article:id,number,name,unit')->get()
                ->sortBy(fn (StockLevel $level): string => $level->article->number ?? '');

            return response()->json([
                'type' => 'location',
                'location' => ['id' => $location->id, 'code' => $location->full_code, 'name' => $location->name, 'active' => $location->is_active],
                'stock' => $levels->map(fn (StockLevel $level): array => [
                    'article_id' => $level->article_id,
                    'number' => $level->article?->number,
                    'name' => $level->article?->name,
                    'unit' => $level->article?->unit,
                    'quantity' => $level->quantity_milli,
                    'text' => Quantity::format($level->quantity_milli),
                ])->values()->all(),
            ]);
        }

        return response()->json(['type' => null], 404);
    }

    /** An article's picture, for the article card. Another company's image is 404 (the company scope). */
    public function image(Request $request, int $image): StreamedResponse
    {
        [$user, $company] = self::context($request);
        self::authorizeUse($user, $company);
        $image = ArticleImage::query()->findOrFail($image);
        abort_unless(Storage::disk(StoredFiles::DISK)->exists($image->path), 404);

        return Storage::disk(StoredFiles::DISK)->response($image->path, null, ['Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * The service worker: the page and its built files for offline starts.
     * It holds no data; the phone keeps that in IndexedDB.
     */
    public function worker(): Response
    {
        $precache = [route('inventory.scanner', absolute: false), ...self::assets()];

        $script = 'const PRECACHE = '.json_encode($precache, JSON_UNESCAPED_SLASHES).";\n"
            .'const VERSION = '.json_encode(substr(hash('sha256', implode('|', $precache)), 0, 12)).";\n"
            .'const SHELL = '.json_encode(route('inventory.scanner', absolute: false), JSON_UNESCAPED_SLASHES).";\n"
            .(string) file_get_contents(dirname(__DIR__).'/resources/js/scanner-sw.js');

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => self::SCOPE,
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * Lager's own users, and users of another module's scanner work (Kök's
     * Kökspersonal registering waste) who may not see Lager itself.
     */
    private static function authorizeUse(User $user, Company $company): void
    {
        abort_unless(Gate::forUser($user)->allows('viewAny', [StockMovement::class, $company]) || app(ScannerExtensions::class)->allowed($user, $company) !== [], 403);
    }

    /** @return array{0: User, 1: Company} */
    private static function context(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $company = $user->currentCompany();
        abort_unless($company instanceof Company, 404);

        return [$user, $company];
    }

    /**
     * The built files of the scanner entry and its chunks, from Vite's
     * manifest. The barcode decoder is loaded only on phones without a
     * built-in one, but is cached too, so the camera works offline.
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
