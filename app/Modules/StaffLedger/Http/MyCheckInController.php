<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Http;

use App\Models\User;
use App\Modules\StaffLedger\Domain\QrToken;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\Support\QrSvg;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * The signed-in person's own QR code as SVG, to show to the kiosk camera.
 */
class MyCheckInController
{
    public function qr(): Response
    {
        /** @var User $user */
        $user = Auth::user();
        $person = Person::query()->where('user_id', $user->id)->whereNull('archived_at')->first();

        abort_unless($person instanceof Person && $user->can('checkInAs', $person), 404);

        return response(QrSvg::render(QrToken::for($person)), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
