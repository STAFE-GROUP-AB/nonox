<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Support\Branding\Brand;
use App\Support\Branding\FirmBranding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The logo of the accounting firm a client company shows (ADR-030), served
 * through the client: to the client's members, and on the login page reached
 * from an invitation through a short-lived signed address. It is never served
 * by the firm's id, so the route reveals nothing about which firm a company
 * uses to anyone outside it. Anything else answers 404.
 */
class FirmLogoController
{
    public function member(Request $request, int $company): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $client = Company::query()->find($company);

        abort_unless($client instanceof Company && $user->belongsToTeam($client), 404);

        return $this->logo($client);
    }

    /** The route carries the `signed` middleware. */
    public function signed(int $company): StreamedResponse
    {
        $client = Company::query()->find($company);

        abort_unless($client instanceof Company, 404);

        return $this->logo($client);
    }

    private function logo(Company $client): StreamedResponse
    {
        $brand = FirmBranding::clientFacing($client);

        abort_unless($brand->viaFirm() && $brand->logoPath !== null && Storage::disk(Brand::DISK)->exists($brand->logoPath), 404);

        return Storage::disk(Brand::DISK)->response($brand->logoPath, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
