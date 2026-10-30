<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Support\Branding\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A company's logo, for the members of that company only. Anyone else, and
 * a company without a logo, gets 404.
 */
class CompanyLogoController
{
    public function __invoke(Request $request, int $company): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = Company::query()->find($company);

        abort_unless($company instanceof Company && $user->belongsToTeam($company) && $company->logo_path !== null, 404);
        abort_unless(Storage::disk(Brand::DISK)->exists($company->logo_path), 404);

        return Storage::disk(Brand::DISK)->response($company->logo_path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
