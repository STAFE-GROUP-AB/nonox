<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\AgiUpload;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Review\ReviewConfirmation;
use App\Support\Skatteverket\Agd\AgdApi;
use App\Support\Skatteverket\Agd\AgdEmployer;
use App\Support\Skatteverket\Skatteverket;
use App\Support\Skatteverket\SkatteverketFailed;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The arbetsgivardeklaration through Skatteverket's API (ADR-054, stage 2):
 * the file AgiSubmissions builds is read into the company's own space at
 * Skatteverket, checked there, and reviewed and submitted by an authorised
 * person on Mina sidor through the review link. Reading it in does not file
 * the declaration (Allmänna villkor Arbetsgivardeklaration inlämning 8 a).
 *
 * Every call is made with the signed-in person's own token, and only the
 * person who read a file in can fetch its check result, save, cancel or
 * remove it, or create the review link for it (Tjänstebeskrivning 1.23,
 * 4.1.1). Another person's upload is shown but never acted on. The file
 * download stays for installations without an agreement.
 */
class AgiSkatteverketUploads
{
    /** States of the own space where nothing may be read in or removed (grunddata, statusEgetUtrymme). */
    private const array BLOCKING_STATES = ['LOCKED', 'LOCKED_FOR_SIGNING', 'SIGNING'];

    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * Reads the generated file in. The period's live state is read first: a
     * locked period or one being signed stops it. An earlier upload is not
     * touched here; it is removed only once the new one is saved (result,
     * saveAnyway), so a refused new file never leaves the month empty.
     *
     * @throws SkatteverketFailed
     */
    public function upload(User $user, AgiSubmission $submission, bool $figuresReviewed = false): AgiUpload
    {
        $company = $this->company($submission->company_id);
        $this->authorize($user, $company, 'payroll:agi-submit');
        ReviewConfirmation::require($figuresReviewed, 'figures_reviewed', 'agi_upload', $submission, $company, $user);

        $lock = Cache::lock("agi-upload:{$company->id}:{$submission->period}", 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['skatteverket' => __('Filen håller redan på att skickas till Skatteverket. Vänta en stund och hämta sedan kontrollresultatet.')]);
        }

        try {
            return CurrentCompany::run($company, function () use ($user, $company, $submission): AgiUpload {
                $submission = AgiSubmission::query()->findOrFail($submission->id);

                if ($submission->status !== AgiSubmission::GENERATED) {
                    throw ValidationException::withMessages(['skatteverket' => __('Bara en fil som inte är markerad som inlämnad kan skickas till Skatteverket.')]);
                }

                $xml = Storage::disk(AttachToVoucher::DISK)->get($submission->xml_path);

                if (! is_string($xml) || hash('sha256', $xml) !== $submission->sha256) {
                    throw ValidationException::withMessages(['skatteverket' => __('Filen för :period går inte att läsa eller har ändrats. Skapa filen på nytt.', ['period' => $submission->period])]);
                }

                $employer = AgdEmployer::resolve($user, $company, Skatteverket::SCOPE_AGD, Skatteverket::SCOPE_AGD_PERIOD);
                $this->settleOwnProcessing($employer, $user, $submission->period);
                $live = $employer->api->period($employer->id, AgdEmployer::period($submission->period));

                if (in_array($live['state'] ?? null, self::BLOCKING_STATES, true)) {
                    throw ValidationException::withMessages(['skatteverket' => $live['state'] === 'SIGNING'
                        ? __('Ingenting har skickats. Deklarationen för :period håller på att skrivas under hos Skatteverket. Vänta tills underskriften är klar och hämta sedan status.', ['period' => $submission->period])
                        : __('Ingenting har skickats. Perioden :period är låst hos Skatteverket, så inga underlag kan läsas in. Lås upp perioden på Mina sidor först.', ['period' => $submission->period])]);
                }

                $uploadId = $employer->api->upload($xml);

                $upload = AgiUpload::query()->create([
                    'agi_submission_id' => $submission->id,
                    'period' => $submission->period,
                    'upload_id' => $uploadId,
                    'sha256' => $submission->sha256,
                    'status' => AgiUpload::PROCESSING,
                    'uploaded_by' => $user->id,
                    'uploaded_at' => CarbonImmutable::now(),
                ]);

                if ($live !== null) {
                    $upload->forceFill(['period_state' => $live['state'], 'period_signed' => $live['signed'], 'period_fetched_at' => CarbonImmutable::now()])->save();
                }

                Audit::record('payroll.agi_skatteverket_uploaded', $upload, ['period' => $submission->period, 'sequence' => $submission->sequence, 'upload_id' => $uploadId, 'period_state' => $live['state'] ?? null, 'sha256' => $submission->sha256], $company, $user);

                return $upload;
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Hämta kontrollresultat. The result's error texts are returned to be
     * shown, never stored: they can quote what the file holds. When the
     * new upload is saved, the person's earlier unsigned uploads for the
     * month are removed. An upload Skatteverket no longer has (404) is
     * marked removed and the result is null.
     *
     * @return array{upload: AgiUpload, result: ?array{status: string, reportStatus: ?string, receivedAt: ?string, hu: ?int, iu: ?int, errors: ?int, warnings: ?int, messages: list<array{kind: string, text: string, specification: ?int}>}, replaced: array{removed: int, kept: int, others: int, failed: ?string}}
     *
     * @throws SkatteverketFailed
     */
    public function result(User $user, AgiUpload $upload): array
    {
        $company = $this->company($upload->company_id);
        $this->authorize($user, $company, 'payroll:agi-submit');

        return CurrentCompany::run($company, function () use ($user, $company, $upload): array {
            $upload = $this->own($user, $this->fresh($upload, [AgiUpload::REMOVED]));
            $employer = AgdEmployer::resolve($user, $company, Skatteverket::SCOPE_AGD, Skatteverket::SCOPE_AGD_PERIOD);
            $wasSaved = $upload->isInOwnSpace();
            $result = $this->check($employer, $upload);

            Audit::record('payroll.agi_skatteverket_checked', $upload, ['period' => $upload->period, 'upload_id' => $upload->upload_id, 'status' => $result['status'] ?? 'GONE', 'errors' => $result['errors'] ?? null, 'warnings' => $result['warnings'] ?? null], $company, $user);

            $replaced = ! $wasSaved && $upload->isInOwnSpace() ? $this->replaceEarlier($employer, $user, $upload) : self::nothingReplaced();

            return ['upload' => $upload, 'result' => $result, 'replaced' => $replaced];
        });
    }

    /**
     * Spara inlämning: keeps an upload with errors in the own space. The
     * errors must be put right on Mina sidor before the declaration can be
     * signed. The person's earlier unsigned uploads are then removed.
     *
     * @return array{upload: AgiUpload, replaced: array{removed: int, kept: int, others: int, failed: ?string}}
     *
     * @throws SkatteverketFailed
     */
    public function saveAnyway(User $user, AgiUpload $upload): array
    {
        $company = $this->company($upload->company_id);
        $this->authorize($user, $company, 'payroll:agi-submit');

        return CurrentCompany::run($company, function () use ($user, $company, $upload): array {
            $upload = $this->own($user, $this->fresh($upload));

            if ($upload->status !== AgiUpload::FAILED) {
                throw ValidationException::withMessages(['skatteverket' => __('Bara en fil där Skatteverkets kontroll hittade fel kan sparas ändå.')]);
            }

            $employer = AgdEmployer::resolve($user, $company, Skatteverket::SCOPE_AGD, Skatteverket::SCOPE_AGD_PERIOD);
            $employer->api->save($upload->upload_id);
            $upload->forceFill(['status' => AgiUpload::SAVED_WITH_ERRORS])->save();

            Audit::record('payroll.agi_skatteverket_saved', $upload, ['period' => $upload->period, 'upload_id' => $upload->upload_id], $company, $user);

            return ['upload' => $upload, 'replaced' => $this->replaceEarlier($employer, $user, $upload)];
        });
    }

    /**
     * Removes what the upload put into the own space: Avbryt inlämning for
     * an upload with errors that is not saved, Ta bort sparad inlämning for
     * a saved one. An upload Skatteverket no longer has counts as removed.
     * The upload behind a signed declaration is never removed.
     *
     * @throws SkatteverketFailed
     */
    public function remove(User $user, AgiUpload $upload): AgiUpload
    {
        $company = $this->company($upload->company_id);
        $this->authorize($user, $company, 'payroll:agi-submit');

        return CurrentCompany::run($company, function () use ($user, $company, $upload): AgiUpload {
            $upload = $this->own($user, $this->fresh($upload));

            if (! in_array($upload->status, [AgiUpload::FAILED, ...AgiUpload::IN_OWN_SPACE], true)) {
                throw ValidationException::withMessages(['skatteverket' => __('Det finns inget i Skatteverkets eget utrymme att ta bort för den här filen.')]);
            }

            $employer = AgdEmployer::resolve($user, $company, Skatteverket::SCOPE_AGD, Skatteverket::SCOPE_AGD_PERIOD);

            if ($upload->isInOwnSpace()) {
                $live = $employer->api->period($employer->id, AgdEmployer::period($upload->period));

                if (self::signed($live) || in_array($live['state'] ?? null, self::BLOCKING_STATES, true)) {
                    throw ValidationException::withMessages(['skatteverket' => __('Ingenting har tagits bort. Deklarationen för :period är underskriven, håller på att skrivas under eller är låst hos Skatteverket. Ändra den på Mina sidor.', ['period' => $upload->period])]);
                }
            }

            $this->removeAt($employer, $upload);

            Audit::record('payroll.agi_skatteverket_removed', $upload, ['period' => $upload->period, 'upload_id' => $upload->upload_id], $company, $user);

            return $upload;
        });
    }

    /**
     * Skapa granskningsunderlag: the link where an authorised person reviews
     * and submits the declaration on Mina sidor. The period is not locked.
     *
     * @return array{upload: AgiUpload, ready: bool, state: string}
     *
     * @throws SkatteverketFailed
     */
    public function review(User $user, AgiUpload $upload): array
    {
        $company = $this->company($upload->company_id);
        $this->authorize($user, $company, 'payroll:agi-submit');

        return CurrentCompany::run($company, function () use ($user, $company, $upload): array {
            $upload = $this->own($user, $this->fresh($upload));

            if (! $upload->isInOwnSpace()) {
                throw ValidationException::withMessages(['skatteverket' => __('Granskningslänken kan skapas först när filen är sparad i Skatteverkets eget utrymme. Hämta kontrollresultatet först.')]);
            }

            $employer = AgdEmployer::resolve($user, $company, Skatteverket::SCOPE_AGD);
            $review = $employer->api->review($employer->id, AgdEmployer::period($upload->period));

            $upload->forceFill([
                'review_state' => $review['state'] === '' ? null : $review['state'],
                // A review link only in a state that has one; on 409 INCORRECT_DATA the link is Skatteverket's error report.
                'review_link' => $review['ready'] || $review['state'] === 'INCORRECT_DATA' ? $review['link'] : null,
                'review_created_at' => CarbonImmutable::now(),
            ])->save();

            Audit::record('payroll.agi_skatteverket_review_link', $upload, ['period' => $upload->period, 'upload_id' => $upload->upload_id, 'state' => $upload->review_state, 'ready' => $review['ready']], $company, $user);

            return ['upload' => $upload, 'ready' => $review['ready'], 'state' => $review['state']];
        });
    }

    /**
     * The period at Skatteverket (Arbetsgivardeklaration hantera
     * redovisningsperiod): its state, events and receipts. For a person who
     * may submit the declaration, the state and the latest receipt are kept
     * on the period's latest upload and the call is written to the audit
     * log; for the archive role it is read only.
     *
     * @return array{period: ?array{state: string, signed: bool, correcting: bool, deadline: ?string, paymentDate: ?string, underlag: ?string, iuOk: int, iuErrors: int, iuWarnings: int}, events: list<array{at: string, text: string}>, receipts: list<array{id: string, signedAt: string, individuals: ?int}>}
     *
     * @throws SkatteverketFailed
     */
    public function periodStatus(User $user, Company $company, string $period): array
    {
        $this->authorize($user, $company, 'payroll:agi-submit', 'payroll:archive-view');

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw ValidationException::withMessages(['skatteverket' => __('Välj en månad.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $period): array {
            $employer = AgdEmployer::resolve($user, $company, Skatteverket::SCOPE_AGD_PERIOD);
            $code = AgdEmployer::period($period);
            $status = $employer->api->period($employer->id, $code);
            $events = $employer->api->events($employer->id, $code);
            $receipts = $employer->api->receipts($employer->id, $code);

            usort($receipts, fn (array $a, array $b): int => strcmp($b['signedAt'], $a['signedAt']));

            if (! $user->hasTeamPermission($company, 'payroll:agi-submit')) {
                return ['period' => $status, 'events' => $events, 'receipts' => $receipts];
            }

            $latest = $receipts[0] ?? null;
            $upload = AgiUpload::query()->where('period', $period)->where('status', '!=', AgiUpload::REMOVED)->latest('id')->first();
            $upload?->forceFill([
                'period_state' => $status['state'] ?? null,
                'period_signed' => $status['signed'] ?? null,
                'receipt_count' => count($receipts),
                'latest_receipt_id' => $latest === null || $latest['id'] === '' ? null : $latest['id'],
                'latest_signed_at' => $latest === null || $latest['signedAt'] === '' ? null : self::time($latest['signedAt']),
                'period_fetched_at' => CarbonImmutable::now(),
            ])->save();

            Audit::record('payroll.agi_skatteverket_status', $upload, ['period' => $period, 'state' => $status['state'] ?? null, 'signed' => $status['signed'] ?? null, 'receipts' => count($receipts)], $company, $user);

            return ['period' => $status, 'events' => $events, 'receipts' => $receipts];
        });
    }

    /**
     * Fetches the result of the person's own uploads for the month that are
     * still being checked, so a new upload knows where they stand; one that
     * is still being checked stops the new upload.
     */
    private function settleOwnProcessing(AgdEmployer $employer, User $user, string $period): void
    {
        $processing = AgiUpload::query()->where('period', $period)->where('status', AgiUpload::PROCESSING)->where('uploaded_by', $user->id)->get();

        foreach ($processing as $upload) {
            $this->check($employer, $upload);

            if ($upload->status === AgiUpload::PROCESSING) {
                throw ValidationException::withMessages(['skatteverket' => __('Ingenting har skickats. Skatteverket kontrollerar fortfarande den förra filen för :period. Vänta en stund och hämta kontrollresultatet för den först.', ['period' => $period])]);
            }
        }
    }

    /**
     * Fetches and stores one upload's check result; 404 (kod 32, also after
     * Skatteverket's 24-hour clean-up) marks it removed and answers null.
     *
     * @return ?array{status: string, reportStatus: ?string, receivedAt: ?string, hu: ?int, iu: ?int, errors: ?int, warnings: ?int, messages: list<array{kind: string, text: string, specification: ?int}>}
     */
    private function check(AgdEmployer $employer, AgiUpload $upload): ?array
    {
        try {
            $result = $employer->api->result($upload->upload_id);
        } catch (SkatteverketFailed $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }

            $upload->forceFill(['status' => AgiUpload::REMOVED, 'review_state' => null, 'review_link' => null, 'checked_at' => CarbonImmutable::now()])->save();

            return null;
        }

        $status = match ($result['status']) {
            AgdApi::DONE_SUCCESS => AgiUpload::SAVED,
            AgdApi::DONE_FAILED => AgiUpload::FAILED,
            AgdApi::DONE_REJECTED => AgiUpload::REJECTED,
            default => AgiUpload::PROCESSING,
        };

        // Saving an upload with errors does not change Skatteverket's check result (still DONE_FAILED).
        if ($upload->status === AgiUpload::SAVED_WITH_ERRORS && $status === AgiUpload::FAILED) {
            $status = AgiUpload::SAVED_WITH_ERRORS;
        }

        $upload->forceFill([
            'status' => $status,
            'report_status' => $result['reportStatus'],
            'main_count' => $result['hu'],
            'individual_count' => $result['iu'],
            'error_count' => $result['errors'],
            'warning_count' => $result['warnings'],
            'checked_at' => CarbonImmutable::now(),
        ])->save();

        return $result;
    }

    /**
     * After a new upload is saved: the person's own earlier uploads for the
     * month are removed from the own space, unless the period is signed,
     * being signed or locked (then they belong to a filed declaration and a
     * correction is read in beside them) or a receipt signed after them is
     * known. Another person's uploads cannot be removed by this person
     * (Tjänstebeskrivning 4.1.1) and are counted. A removal Skatteverket
     * refuses does not undo the new upload; the reason is returned.
     *
     * TODO(provider-verify): whether removing a saved upload after reading in a new one is how Skatteverket expects a draft to be replaced, or whether individuppgifter with the same specification number are replaced by the new upload on their own. Confirm in the test service.
     *
     * @return array{removed: int, kept: int, others: int, failed: ?string}
     */
    private function replaceEarlier(AgdEmployer $employer, User $user, AgiUpload $new): array
    {
        $earlier = AgiUpload::query()->where('period', $new->period)->where('id', '<', $new->id)
            ->whereIn('status', [AgiUpload::FAILED, ...AgiUpload::IN_OWN_SPACE])->orderBy('id')->get();
        $others = $earlier->filter(fn (AgiUpload $upload): bool => $upload->uploaded_by !== $user->id);
        $own = $earlier->filter(fn (AgiUpload $upload): bool => $upload->uploaded_by === $user->id);
        $replaced = ['removed' => 0, 'kept' => 0, 'others' => $others->count(), 'failed' => null];

        if ($own->isEmpty()) {
            return $replaced;
        }

        try {
            $live = $employer->api->period($employer->id, AgdEmployer::period($new->period));
        } catch (SkatteverketFailed $exception) {
            return [...$replaced, 'kept' => $own->count(), 'failed' => $exception->userMessage];
        }

        $locked = self::signed($live) || in_array($live['state'] ?? null, self::BLOCKING_STATES, true);

        foreach ($own as $upload) {
            $filed = $upload->latest_signed_at !== null && $upload->latest_signed_at->greaterThan($upload->uploaded_at);

            if ($upload->isInOwnSpace() && ($locked || $filed)) {
                $replaced['kept']++;

                continue;
            }

            try {
                $this->removeAt($employer, $upload);
                $replaced['removed']++;
            } catch (SkatteverketFailed $exception) {
                $replaced['kept']++;
                $replaced['failed'] = $exception->userMessage;
            }
        }

        return $replaced;
    }

    private function removeAt(AgdEmployer $employer, AgiUpload $upload): void
    {
        try {
            $upload->status === AgiUpload::FAILED
                ? $employer->api->cancel($upload->upload_id)
                : $employer->api->remove($employer->id, AgdEmployer::period($upload->period), $upload->upload_id);
        } catch (SkatteverketFailed $exception) {
            // Gone already (kod 31, 32) or cleared after 24 hours.
            if ($exception->status !== 404) {
                throw $exception;
            }
        }

        $upload->forceFill(['status' => AgiUpload::REMOVED, 'review_state' => null, 'review_link' => null])->save();
    }

    /** @param  ?array{state: string, signed: bool, correcting: bool}  $live */
    private static function signed(?array $live): bool
    {
        return $live !== null && ($live['signed'] || $live['correcting'] || $live['state'] === 'SIGNED');
    }

    /** @return array{removed: int, kept: int, others: int, failed: ?string} */
    private static function nothingReplaced(): array
    {
        return ['removed' => 0, 'kept' => 0, 'others' => 0, 'failed' => null];
    }

    /** Only the person who read the file in may act on it at Skatteverket. */
    private function own(User $user, AgiUpload $upload): AgiUpload
    {
        if ($upload->uploaded_by !== $user->id) {
            throw ValidationException::withMessages(['skatteverket' => __('Bara den som skickade filen till Skatteverket kan hämta kontrollresultatet, spara, ta bort eller skapa granskningslänken för den. Den går också att ändra eller ta bort på Mina sidor.')]);
        }

        return $upload;
    }

    /** @param  list<string>  $refused */
    private function fresh(AgiUpload $upload, array $refused = []): AgiUpload
    {
        $upload = AgiUpload::query()->findOrFail($upload->id);

        if (in_array($upload->status, $refused, true)) {
            throw ValidationException::withMessages(['skatteverket' => __('Filen är borttagen hos Skatteverket.')]);
        }

        return $upload;
    }

    private function company(int $id): Company
    {
        return Company::query()->findOrFail($id);
    }

    private function authorize(User $user, Company $company, string ...$permissions): void
    {
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);

        if (! Skatteverket::isAvailableTo($user)) {
            throw ValidationException::withMessages(['skatteverket' => __('Kopplingen till Skatteverket är inte konfigurerad i den här installationen.')]);
        }

        foreach ($permissions as $permission) {
            if ($user->hasTeamPermission($company, $permission)) {
                return;
            }
        }

        throw new AuthorizationException;
    }

    private static function time(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
