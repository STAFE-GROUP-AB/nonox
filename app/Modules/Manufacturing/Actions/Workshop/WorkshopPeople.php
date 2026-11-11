<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Workshop;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\ManufacturingSettings;
use App\Modules\Manufacturing\Models\WorkshopPerson;
use App\Modules\Manufacturing\Models\WorkshopTimeEntry;
use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Domain\Pin;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The people on the workshop terminal (ADR-071 §7): a name, a personal
 * code and an optional photo. The photo is stored only with the person's
 * consent and can be removed at any time; the code is never stored, only a
 * salt and a PBKDF2 verifier. Archiving takes a person off the terminal;
 * their reported time stays until the retention period has passed, and is
 * then cut loose from them (PruneWorkshopTime). Audit entries carry ids
 * only, never names.
 */
final class WorkshopPeople
{
    public const string DISK = 'local';

    public const int PHOTO_SIZE = 256;

    /** About a 48-megapixel phone photo; larger is refused before decoding. */
    public const int MAX_PIXELS = 50_000_000;

    /** @param  array<string, mixed>  $input  name, code (optional) */
    public function create(User $user, Company $company, array $input): WorkshopPerson
    {
        $this->authorize($user, $company);

        $name = self::name((string) ($input['name'] ?? ''));

        return DB::transaction(function () use ($user, $company, $input, $name): WorkshopPerson {
            $person = WorkshopPerson::create(['name' => $name, 'created_by' => $user->id]);

            if (($input['code'] ?? '') !== '') {
                $this->applyCode($person, (string) $input['code']);
            }

            Audit::record('manufacturing.terminal_person_created', $person, [], $company, $user);

            return $person;
        });
    }

    public function rename(User $user, Company $company, WorkshopPerson $person, string $name): void
    {
        $this->authorize($user, $company);

        $person->forceFill(['name' => self::name($name)])->save();

        Audit::record('manufacturing.terminal_person_changed', $person, [], $company, $user);
    }

    /** Sets a new code and lifts a lock-out. */
    public function setCode(User $user, Company $company, WorkshopPerson $person, string $code): void
    {
        $this->authorize($user, $company);
        $this->applyCode($person, $code);

        Audit::record('manufacturing.terminal_code_set', $person, [], $company, $user);
    }

    /**
     * Stores the photo as a small square JPEG on the private disk. Requires
     * that the person has consented.
     */
    public function setPhoto(User $user, Company $company, WorkshopPerson $person, UploadedFile $file, bool $consent): void
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
        $path = "manufacturing/workshop-photos/{$company->id}/".basename('person-'.Str::uuid().'.jpg');
        Storage::disk(self::DISK)->put($path, $jpeg);

        $person->forceFill(['photo_path' => $path, 'photo_consent_at' => CarbonImmutable::now()])->save();

        Audit::record('manufacturing.terminal_photo_set', $person, ['consent' => true], $company, $user);
    }

    public function removePhoto(User $user, Company $company, WorkshopPerson $person): void
    {
        $this->authorize($user, $company);
        $this->deletePhotoFile($person);
        $person->forceFill(['photo_path' => null, 'photo_consent_at' => null])->save();

        Audit::record('manufacturing.terminal_photo_removed', $person, [], $company, $user);
    }

    public function archive(User $user, Company $company, WorkshopPerson $person, bool $archived): void
    {
        $this->authorize($user, $company);
        $person->forceFill(['archived_at' => $archived ? CarbonImmutable::now() : null])->save();

        // An archived person keeps no photo on the terminal's disk longer than needed.
        if ($archived) {
            $this->deletePhotoFile($person);
            $person->forceFill(['photo_path' => null, 'photo_consent_at' => null])->save();
        }

        Audit::record($archived ? 'manufacturing.terminal_person_archived' : 'manufacturing.terminal_person_restored', $person, [], $company, $user);
    }

    /**
     * Removes a person who never reported anything. One who did is only
     * archived: the time entries keep pointing at them until retention ends.
     */
    public function delete(User $user, Company $company, WorkshopPerson $person): bool
    {
        $this->authorize($user, $company);

        if (WorkshopTimeEntry::query()->where('person_id', $person->id)->exists()) {
            $this->archive($user, $company, $person, true);

            return false;
        }

        $this->deletePhotoFile($person);
        Audit::record('manufacturing.terminal_person_deleted', $person, [], $company, $user);
        $person->delete();

        return true;
    }

    public static function photoPath(WorkshopPerson $person): ?string
    {
        return $person->photo_path === null || $person->photo_consent_at === null ? null : $person->photo_path;
    }

    /** The photo as a data URL for the terminal, or null. */
    public static function photoDataUrl(WorkshopPerson $person): ?string
    {
        $path = self::photoPath($person);
        $contents = $path === null ? null : Storage::disk(self::DISK)->get($path);

        return $contents === null ? null : 'data:image/jpeg;base64,'.base64_encode($contents);
    }

    public function deletePhotoFile(WorkshopPerson $person): void
    {
        if ($person->photo_path !== null) {
            Storage::disk(self::DISK)->delete($person->photo_path);
        }
    }

    private function applyCode(WorkshopPerson $person, string $code): void
    {
        if (! Pin::isValidFormat($code)) {
            throw ValidationException::withMessages(['code' => __('Koden ska vara fyra siffror.')]);
        }

        if (Pin::isTrivial($code)) {
            throw ValidationException::withMessages(['code' => __('Välj en kod som är svårare att gissa än 1234 eller 0000.')]);
        }

        $salt = Pin::newSalt();
        $person->forceFill([
            'code_salt' => $salt,
            'code_verifier' => Pin::verifier($code, $salt),
            'failed_attempts' => 0,
            'locked_until' => null,
        ])->save();
    }

    private static function name(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => __('Skriv ett namn på högst 100 tecken.')]);
        }

        return $name;
    }

    /** A centred square crop, scaled to PHOTO_SIZE, as JPEG; null when the image cannot be read. */
    private static function square(string $contents): ?string
    {
        $size = @getimagesizefromstring($contents);

        // Read the size first: a small file can declare a huge picture and exhaust memory.
        if ($size === false || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }

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
        app(ModuleManager::class)->ensureEnabled(ManufacturingModule::KEY, $company);
        Gate::forUser($user)->authorize('terminal', [WorkshopPerson::class, $company]);
        abort_unless(ManufacturingSettings::terminalEnabled($company), 404);
    }
}
