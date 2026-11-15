<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopDevices;
use App\Modules\Manufacturing\Domain\DeviceKind;
use App\Modules\Manufacturing\Http\Middleware\AuthenticateWallScreen;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Support\WallBoard;
use App\Modules\Manufacturing\Support\WallStrings;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The workshop wall screen (ADR-071, M7): a page for a television in the
 * workshop that shows today's production, what is late and what is to be
 * delivered, with nobody signed in at the screen. The page holds no
 * company data; the board comes from the JSON endpoint after pairing, with
 * the screen's device token. A signed-in user with manufacturing:view sees
 * the same page as a preview, fed from the session.
 */
class WallScreenController
{
    public const string ENTRY_JS = 'app/Modules/Manufacturing/resources/js/wall.js';

    /** How often a screen's last_seen_at is written at most, so polling does not write on every request. */
    private const int SEEN_EVERY_SECONDS = 300;

    public function shell(): Response
    {
        return $this->page([
            'pair' => route('manufacturing.wallScreen.pair', absolute: false),
            'board' => route('manufacturing.wallScreen.board', absolute: false),
        ], false);
    }

    public function pair(Request $request, WorkshopDevices $devices): JsonResponse
    {
        $paired = $devices->pair((string) $request->input('code', ''), DeviceKind::WallScreen);

        if ($paired === null) {
            return response()->json(['error' => 'invalid_code'], 422);
        }

        [$device, $token] = $paired;

        return response()->json(['token' => $token, 'device' => ['id' => $device->id, 'name' => $device->name]]);
    }

    public function board(Request $request, WallBoard $board): JsonResponse
    {
        $device = $request->attributes->get(AuthenticateWallScreen::ATTRIBUTE);
        abort_unless($device instanceof WorkshopDevice, 401);

        if ($device->last_seen_at === null || $device->last_seen_at->lessThan(CarbonImmutable::now()->subSeconds(self::SEEN_EVERY_SECONDS))) {
            $device->forceFill(['last_seen_at' => CarbonImmutable::now()])->save();
        }

        // The screen has no signed-in user: its token named the company, which the middleware set.
        $company = CompanyScope::bypass(fn (): ?Company => Company::query()->find($device->company_id));
        abort_unless($company instanceof Company, 404);

        return response()->json($board->build($company), 200, ['Cache-Control' => 'no-store']);
    }

    /** The same page for a signed-in user, fed from the session: what the screen shows right now. */
    public function preview(Request $request): Response
    {
        $this->authorizePreview($request);

        return $this->page(['board' => route('manufacturing.wallScreen.preview.board', absolute: false)], true);
    }

    public function previewBoard(Request $request, WallBoard $board): JsonResponse
    {
        $company = $this->authorizePreview($request);

        return response()->json($board->build($company), 200, ['Cache-Control' => 'no-store']);
    }

    private function authorizePreview(Request $request): Company
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $company = $user->currentCompany();
        abort_unless($company instanceof Company, 404);
        abort_unless(Gate::forUser($user)->allows('viewAny', [WorkshopDevice::class, $company]), 403);

        return $company;
    }

    /** @param  array<string, string>  $urls */
    private function page(array $urls, bool $preview): Response
    {
        return response()->view('manufacturing::wall', [
            'config' => ['strings' => WallStrings::all(), 'urls' => $urls, 'preview' => $preview],
        ])->header('Cache-Control', 'no-cache');
    }
}
