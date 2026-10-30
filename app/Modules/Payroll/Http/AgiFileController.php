<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a generated arbetsgivardeklaration. The file holds personal
 * numbers, so every download is written to the audit log. Another company's
 * file answers 404.
 */
class AgiFileController
{
    public function __invoke(Request $request, AgiSubmission $submission): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = Company::query()->findOrFail($submission->company_id);

        abort_unless($user->hasTeamPermission($company, 'payroll:agi-submit') || $user->hasTeamPermission($company, 'payroll:archive-view'), 404);
        abort_unless(Storage::disk(AttachToVoucher::DISK)->exists($submission->xml_path), 404);

        Audit::record('payroll.agi_downloaded', $submission, ['period' => $submission->period, 'sequence' => $submission->sequence], $company, $user);

        return Storage::disk(AttachToVoucher::DISK)->download($submission->xml_path, $submission->filename(), [
            'Content-Type' => 'application/xml',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }
}
