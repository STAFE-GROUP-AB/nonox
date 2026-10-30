<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Branding\Brand;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Sets a company's logo and colours. The logo is a PNG, JPEG or WebP image of
 * at most 2 MB, stored privately under the company's own folder with a name
 * we choose; SVG is not accepted because it can carry scripts.
 */
class UpdateCompanyBranding
{
    private const array EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /**
     * @param  array<string, mixed>  $input  brand_color, brand_accent_color
     *
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, array $input, ?UploadedFile $logo = null, bool $removeLogo = false): void
    {
        Gate::forUser($user)->authorize('update', $company);

        $validated = Validator::make([...$input, 'logo' => $logo], [
            'brand_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand_accent_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:2048', 'dimensions:min_width=40,min_height=20,max_width=6000,max_height=6000'],
        ], [
            'brand_color.regex' => __('Skriv färgen som en hexkod, till exempel #1F4E79.'),
            'brand_accent_color.regex' => __('Skriv färgen som en hexkod, till exempel #1F4E79.'),
            'logo.mimes' => __('Loggan ska vara en PNG-, JPEG- eller WebP-bild.'),
            'logo.mimetypes' => __('Loggan ska vara en PNG-, JPEG- eller WebP-bild.'),
            'logo.max' => __('Loggan får vara högst 2 MB.'),
            'logo.dimensions' => __('Loggan behöver vara minst 40 × 20 och högst 6000 × 6000 pixlar.'),
        ])->validateWithBag('updateBranding');

        $before = $company->only(['logo_path', 'brand_color', 'brand_accent_color']);
        $oldLogo = $company->logo_path;
        $path = $oldLogo;

        if ($logo !== null) {
            $extension = strtolower($logo->getClientOriginalExtension());
            $extension = in_array($extension, self::EXTENSIONS, true) ? $extension : 'png';
            $filename = basename('logo-'.time().'-'.uniqid().'.'.$extension);
            $path = (string) $logo->storeAs("company-logos/{$company->id}", $filename, Brand::DISK);
        }

        if ($removeLogo && $logo === null) {
            $path = null;
        }

        $company->forceFill([
            'logo_path' => $path,
            'brand_color' => blank($validated['brand_color'] ?? null) ? null : strtoupper((string) $validated['brand_color']),
            'brand_accent_color' => blank($validated['brand_accent_color'] ?? null) ? null : strtoupper((string) $validated['brand_accent_color']),
        ])->save();

        if ($oldLogo !== null && $oldLogo !== $path) {
            Storage::disk(Brand::DISK)->delete($oldLogo);
        }

        Audit::record('company.branding_updated', $company, ['before' => $before, 'after' => $company->only(['logo_path', 'brand_color', 'brand_accent_color'])], $company, $user);
    }
}
