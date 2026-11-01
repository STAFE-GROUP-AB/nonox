<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Domain\Pin;
use App\Modules\StaffLedger\Domain\QrToken;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What a person uses at the kiosk: the PIN, the personal QR code and an
 * optional photo (only with the person's consent, removable at any time).
 * Archiving takes a person off the kiosk; their ledger entries stay.
 */
final class PersonCredentials
{
    public const string DISK = 'local';

    public const int PHOTO_SIZE = 256;

    public function setPin(User $user, Company $company, Person $person, string $pin): void
    {
        $this->authorize($user, $company);

        if (! Pin::isValidFormat($pin)) {
            throw ValidationException::withMessages(['pin' => __('Koden ska vara fyra siffror.')]);
        }

        if (Pin::isTrivial($pin)) {
            throw ValidationException::withMessages(['pin' => __('Välj en kod som är svårare att gissa än 1234 eller 0000.')]);
        }

        $salt = Pin::newSalt();
        $controlSalt = Pin::newSalt();
        $person->forceFill([
            'pin_salt' => $salt,
            'pin_verifier' => Pin::verifier($pin, $salt),
            'control_salt' => $controlSalt,
            'control_key' => Pin::controlKey($pin, $controlSalt),
        ])->save();

        Audit::record('staff_ledger.pin_set', $person, [], $company, $user);
    }

    public function renewQr(User $user, Company $company, Person $person): void
    {
        $this->authorize($user, $company);
        QrToken::issue($person);

        Audit::record('staff_ledger.qr_renewed', $person, [], $company, $user);
    }

    /**
     * Stores the photo as a small square JPEG, encrypted at rest like other
     * private files. Requires that the person has consented.
     */
    public function setPhoto(User $user, Company $company, Person $person, UploadedFile $file, bool $consent): void
    {
        $this->authorize($user, $company);

        if (! $consent) {
            throw ValidationException::withMessages(['photo' => __('Ett foto får bara sparas om personen har samtyckt.')]);
        }

        $ext = strtolower($file->getClientOriginalExtension());

        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || $file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['photo' => __('Välj en bild i JPG, PNG eller WebP på högst 5 MB.')]);
        }

        $jpeg = self::square((string) file_get_contents($file->getRealPath()));

        if ($jpeg === null) {
            throw ValidationException::withMessages(['photo' => __('Bilden gick inte att läsa.')]);
        }

        $this->deletePhotoFile($person);
        $path = basename('person-'.Str::uuid().'.jpg');
        Storage::disk(self::DISK)->put("staff-ledger/photos/{$company->id}/{$path}", $jpeg);

        $person->forceFill(['photo_path' => "staff-ledger/photos/{$company->id}/{$path}", 'photo_consent_at' => CarbonImmutable::now()])->save();

        Audit::record('staff_ledger.photo_set', $person, ['consent' => true], $company, $user);
    }

    public function removePhoto(User $user, Company $company, Person $person): void
    {
        $this->authorize($user, $company);
        $this->deletePhotoFile($person);
        $person->forceFill(['photo_path' => null, 'photo_consent_at' => null])->save();

        Audit::record('staff_ledger.photo_removed', $person, [], $company, $user);
    }

    public function archive(User $user, Company $company, Person $person, bool $archived): void
    {
        $this->authorize($user, $company);
        $person->forceFill(['archived_at' => $archived ? CarbonImmutable::now() : null])->save();

        Audit::record($archived ? 'staff_ledger.person_archived' : 'staff_ledger.person_restored', $person, [], $company, $user);
    }

    /** The photo as a data URL for the kiosk, or null. */
    public static function photoDataUrl(Person $person): ?string
    {
        if ($person->photo_path === null || $person->photo_consent_at === null) {
            return null;
        }

        $contents = Storage::disk(self::DISK)->get($person->photo_path);

        return $contents === null ? null : 'data:image/jpeg;base64,'.base64_encode($contents);
    }

    public function deletePhotoFile(Person $person): void
    {
        if ($person->photo_path !== null) {
            Storage::disk(self::DISK)->delete($person->photo_path);
        }
    }

    /** A centred square crop, scaled to PHOTO_SIZE, as JPEG; null when the image cannot be read. */
    private static function square(string $contents): ?string
    {
        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return null;
        }

        $side = min(imagesx($image), imagesy($image));
        $target = imagecreatetruecolor(self::PHOTO_SIZE, self::PHOTO_SIZE);

        if ($target === false) {
            return null;
        }

        imagecopyresampled($target, $image, 0, 0, (int) ((imagesx($image) - $side) / 2), (int) ((imagesy($image) - $side) / 2), self::PHOTO_SIZE, self::PHOTO_SIZE, $side, $side);

        ob_start();
        imagejpeg($target, null, 82);

        return (string) ob_get_clean();
    }

    private function authorize(User $user, Company $company): void
    {
        app(ModuleManager::class)->ensureEnabled(StaffLedgerModule::KEY, $company);
        Gate::forUser($user)->authorize('manage', [Person::class, $company]);
    }
}
