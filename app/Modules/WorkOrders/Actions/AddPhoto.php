<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\WorkOrders\Actions\Concerns\GuardsWorkOrders;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderPhoto;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A photo taken on site (ADR-074 §8, §9), on the private disk with a name
 * of our own and an image extension only. It may show a home's interior or
 * people (AO-R39): never on the public disk, served only through the work
 * order to those who may see it.
 */
class AddPhoto
{
    use GuardsWorkOrders;

    public const array EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic'];

    public function __invoke(User $user, Company $company, WorkOrder $workOrder, UploadedFile $file, string $caption = ''): WorkOrderPhoto
    {
        $this->guardReport($user, $company, $workOrder);

        Validator::make(['photo' => $file], ['photo' => ['required', 'file', 'max:15360', 'mimes:jpg,jpeg,png,webp,heic']], [
            'photo.mimes' => __('Bilden ska vara JPG, PNG, WebP eller HEIC.'),
            'photo.max' => __('Bilden får vara högst 15 MB.'),
        ])->validate();

        if (! $workOrder->status->isOpen()) {
            throw ValidationException::withMessages(['photo' => __('Arbetsordern är avslutad.')]);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $extension = in_array($extension, self::EXTENSIONS, true) ? $extension : 'jpg';
        $name = basename('foto-'.time().'-'.bin2hex(random_bytes(6)).'.'.$extension);
        $path = $file->storeAs("arbetsorder/{$company->id}/{$workOrder->id}", $name, WorkOrderPhoto::DISK);

        if ($path === false) {
            throw ValidationException::withMessages(['photo' => __('Bilden kunde inte sparas. Försök igen.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $workOrder, $path, $caption): WorkOrderPhoto {
            $photo = WorkOrderPhoto::create([
                'work_order_id' => $workOrder->id,
                'path' => $path,
                'caption' => trim($caption) === '' ? null : mb_substr(trim($caption), 0, 255),
                'uploaded_by' => $user->id,
            ]);

            Audit::record('work_orders.photo_added', $workOrder, ['photo_id' => $photo->id], $company, $user);

            return $photo;
        });
    }
}
