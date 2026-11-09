<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Http;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\Company;
use App\Models\User;
use App\Modules\Boardroom\Domain\RegisterException;
use App\Modules\Boardroom\Models\Meeting;
use App\Modules\Boardroom\Models\Minutes;
use App\Modules\Boardroom\Models\Shareholder;
use App\Modules\Boardroom\Support\BoardroomPdf;
use App\Modules\Boardroom\Support\ShareholderExport;
use App\Modules\Boardroom\Support\ShareRegister;
use App\Support\Audit\Audit;
use App\Support\Tenancy\ArchivedCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The boardroom's documents: the register at a date (full, or the courtesy
 * copy without personal numbers), the notice of a meeting, approved minutes
 * as stored with their SHA-256, and a shareholder's register extract (GDPR
 * art. 15 and 20). Every print and extract is written to the audit log,
 * with the version, so it shows who printed personal numbers (ADR-070 §6;
 * ADR-068 will move these reads to the read access log). Another company's
 * meeting, minutes or shareholder answers 404.
 */
class BoardroomDocumentsController
{
    public function register(Request $request): Response
    {
        [$user, $company] = $this->context($request, 'share-register:view');
        $masked = $request->query('version') === 'utan-personnummer';
        $date = $this->date($request->query('datum'));

        try {
            $state = ShareRegister::at($date);
        } catch (RegisterException $exception) {
            abort(409, $exception->getMessage());
        }

        Audit::record('share_register.printed', $company, ['date' => $date->toDateString(), 'version' => $masked ? 'without_personal_numbers' : 'full'], $company, $user);

        $name = ($masked ? 'aktiebok-utdrag-' : 'aktiebok-').$date->format('Ymd').'.pdf';

        return response(BoardroomPdf::register($company, $state, $date, $masked, $user), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$name}\"",
        ]);
    }

    public function notice(Request $request, int $meeting): Response
    {
        [, $company] = $this->context($request, 'meetings:view');
        $found = Meeting::query()->findOrFail($meeting);

        return response(BoardroomPdf::notice($company, $found), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="kallelse-'.$found->meeting_date->format('Ymd').'.pdf"',
        ]);
    }

    public function minutes(Request $request, int $minutes): Response
    {
        [$user, $company] = $this->context($request, 'meetings:view');
        $found = Minutes::query()->findOrFail($minutes);

        abort_unless($found->isApproved() && $found->pdf_path !== null, 404);
        $pdf = Storage::disk(AttachToVoucher::DISK)->get($found->pdf_path);
        abort_if($pdf === null, 404);
        // The stored file is the one approved: its hash must still match.
        abort_unless(hash('sha256', $pdf) === $found->sha256, 409, __('Protokollets fil stämmer inte med kontrollsumman från godkännandet.'));

        Audit::record('boardroom.minutes_downloaded', $found, ['sha256' => $found->sha256], $company, $user);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="protokoll-'.$found->id.'.pdf"',
        ]);
    }

    public function shareholderExport(Request $request, int $shareholder): JsonResponse
    {
        [$user, $company] = $this->context($request, 'share-register:view');
        $found = Shareholder::query()->findOrFail($shareholder);

        Audit::record('privacy.exported', $found, ['subject' => 'shareholder'], $company, $user);

        return response()->json(ShareholderExport::for($found), 200, [
            'Content-Disposition' => 'attachment; filename="registerutdrag-aktieagare-'.$found->id.'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{0: User, 1: Company} */
    private function context(Request $request, string $permission): array
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Company $company */
        $company = $user->currentCompany();
        $allowed = ArchivedCompany::reading(fn (): bool => $user->hasTeamPermission($company, $permission));

        abort_unless($allowed, 403);

        return [$user, $company];
    }

    private function date(mixed $input): CarbonImmutable
    {
        if (! is_string($input) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
            return CarbonImmutable::today();
        }

        return CarbonImmutable::parse($input);
    }
}
