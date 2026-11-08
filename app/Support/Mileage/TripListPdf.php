<?php

declare(strict_types=1);

namespace App\Support\Mileage;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\Company;
use App\Models\User;
use App\Models\VehicleTrip;
use App\Models\Voucher;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The month's trips as a PDF, attached to the voucher that books or pays
 * them as underlag (BFL 5 kap. 6–7 §§; ADR-072 §4 and §7): date, car,
 * kind, from and to, purpose and distance, with the sum.
 */
final class TripListPdf
{
    /** @param Collection<int, VehicleTrip> $trips with vehicle loaded */
    public static function render(Company $company, string $title, string $driver, Collection $trips): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('mileage.trip-list-pdf', [
            'company' => $company,
            'title' => $title,
            'driver' => $driver,
            'trips' => $trips,
            'totalHm' => (int) $trips->sum('distance_hm'),
        ])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    /** @param Collection<int, VehicleTrip> $trips with vehicle loaded */
    public static function attach(User $user, Voucher $voucher, Company $company, string $title, string $driver, Collection $trips): void
    {
        $contents = self::render($company, $title, $driver, $trips);
        $path = "underlag/{$voucher->company_id}/{$voucher->id}/".Str::uuid()->toString().'.pdf';
        Storage::disk(AttachToVoucher::DISK)->put($path, $contents);

        app(AttachToVoucher::class)->linkStoredFile($user, $voucher, $path, Str::slug($title).'.pdf', 'application/pdf', strlen($contents), hash('sha256', $contents));
    }
}
