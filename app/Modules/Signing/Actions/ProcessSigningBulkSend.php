<?php

declare(strict_types=1);

namespace App\Modules\Signing\Actions;

use App\Actions\Signing\SendForSigning;
use App\Jobs\RunSigningBulkSend;
use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Signing\Models\SigningBulkSend;
use App\Modules\Signing\Models\SigningBulkSendRow;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\SigningModule;
use App\Modules\Signing\Support\TemplateFingerprint;
use App\Modules\Signing\Support\TemplateSend;
use App\Support\MailQuota;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Makes the envelopes of a confirmed bulk send (ADR-046 amendment 2026-11-18,
 * gap 8, package S15), for as long as one short job lasts, in the background. Every envelope is
 * made exactly as the send wizard makes one from the template (the roles map
 * to the row's people, the fields follow their role, the defaults apply), by
 * SendForSigning::envelope, so each has its own trail.
 *
 * Idempotent per row: a row is claimed (PENDING to PROCESSING) before it is
 * worked on, and a row left in PROCESSING by a worker that died is settled
 * from what exists (an envelope for its document means it was sent) or made
 * PENDING again. Resumable: the job queues itself for the rest, waits for the
 * company's mail quota instead of failing rows, and stops when the run is
 * cancelled, the person no longer may send, or the template is gone.
 *
 * Each row's addresses (and access code) are removed from it as soon as it
 * is sent; the run's end removes the rest.
 */
final class ProcessSigningBulkSend
{
    /** Seconds of work per job, so that one job stays short; the job then queues itself for the rest. */
    public const int BUDGET_SECONDS = 40;

    /** Never wait less than this for the quota, in seconds. */
    private const int MIN_WAIT = 60;

    public function __construct(
        private readonly SendForSigning $send,
        private readonly ManageSigningBulkSends $runs,
    ) {}

    public function __invoke(int $companyId, string $id): void
    {
        $company = Company::query()->findOrFail($companyId);

        CurrentCompany::run($company, fn () => $this->work($company, $id));
    }

    private function work(Company $company, string $id): void
    {
        $run = SigningBulkSend::query()->find($id);

        if (! $run instanceof SigningBulkSend || $run->status !== SigningBulkSend::RUNNING) {
            return;
        }

        $user = $run->created_by === null ? null : User::query()->find($run->created_by);
        $template = $run->template_id === null ? null : SigningTemplate::query()->with(['roles', 'fields'])->find($run->template_id);

        if (! $user instanceof User || ! $user->hasTeamPermission($company, SigningModule::MANAGE) || ! app(ModuleManager::class)->isEnabled(SigningModule::KEY, $company)) {
            $this->runs->stop($run, 'signing.bulk_send_cancelled', __('Massutskicket stoppades: den som startade det får inte längre skicka för signering.'), $company, null);

            return;
        }

        if (! $template instanceof SigningTemplate) {
            $this->runs->stop($run, 'signing.bulk_send_cancelled', __('Massutskicket stoppades: mallen finns inte kvar.'), $company, null);

            return;
        }

        if ($run->template_fingerprint === null || ! hash_equals($run->template_fingerprint, TemplateFingerprint::of($template))) {
            $this->runs->stop($run, 'signing.bulk_send_cancelled', __('Massutskicket stoppades: mallen ändrades efter förhandsgranskningen.'), $company, null);

            return;
        }

        $this->settleStale($run);

        $needed = $this->mailsPerEnvelope($template);
        $shared = $this->shared($company, $template);
        $started = microtime(true);

        while (microtime(true) - $started < self::BUDGET_SECONDS) {
            if (MailQuota::remaining($company, 'documents') < $needed) {
                $this->again($run, max(self::MIN_WAIT, MailQuota::availableIn($company, 'documents') + 5));

                return;
            }

            $row = SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->where('status', SigningBulkSendRow::PENDING)->orderBy('row_number')->first();

            if (! $row instanceof SigningBulkSendRow) {
                break;
            }

            if (SigningBulkSend::query()->whereKey($run->id)->value('status') !== SigningBulkSend::RUNNING) {
                $this->count($run);

                return;
            }

            $this->sendRow($user, $company, $template, $row, $shared, $needed);
        }

        $this->count($run);

        if (SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->whereIn('status', [SigningBulkSendRow::PENDING, SigningBulkSendRow::PROCESSING])->exists()) {
            $this->again($run, 0);

            return;
        }

        $this->runs->finish($run, SigningBulkSend::COMPLETED, 'signing.bulk_send_completed', $company, $user);
    }

    /**
     * What every row of the run shares: the template file, the fields by role index and the defaults.
     *
     * @return array{bytes: string, fields: array<int, array<string, mixed>>, expires_on: ?string, reminders: array<string, mixed>, copies: array<int, array<string, mixed>>}
     */
    private function shared(Company $company, SigningTemplate $template): array
    {
        $bytes = Storage::disk(SigningRequest::DISK)->get((string) $template->path);

        if (! is_string($bytes) || hash('sha256', $bytes) !== $template->sha256) {
            throw new \LogicException('The stored file of a signing template is missing or has changed.');
        }

        return [
            'bytes' => $bytes,
            'fields' => TemplateSend::fields($template),
            'expires_on' => TemplateSend::expiresOn($template),
            'reminders' => TemplateSend::reminders($template),
            'copies' => (array) ($template->copies ?? []),
        ];
    }

    /**
     * @param  array{bytes: string, fields: array<int, array<string, mixed>>, expires_on: ?string, reminders: array<string, mixed>, copies: array<int, array<string, mixed>>}  $shared
     */
    private function sendRow(User $user, Company $company, SigningTemplate $template, SigningBulkSendRow $row, array $shared, int $needed): void
    {
        $claimed = SigningBulkSendRow::query()->whereKey($row->id)->where('status', SigningBulkSendRow::PENDING)->update(['status' => SigningBulkSendRow::PROCESSING]);

        if ($claimed !== 1) {
            return;
        }

        $document = null;

        try {
            $document = $this->document($user, $company, $template, $row, $shared['bytes']);
            $people = collect((array) ($row->payload['signers'] ?? []))->keyBy('role_key');

            $signers = $template->roles->map(function ($role) use ($people, $template): array {
                $person = (array) $people->get($role->key);

                return [
                    'email' => (string) ($person['email'] ?? ''),
                    'step' => $role->step,
                    'verification' => $role->verification,
                    'access_code' => (string) ($person['access_code'] ?? ''),
                    'language' => (string) ($person['language'] ?? $template->language),
                ];
            })->values()->all();

            $envelope = $this->send->envelope($user, $company, $document, [
                'signers' => $signers,
                'copies' => $shared['copies'],
                'notify_copies' => false,
                'expires_on' => $shared['expires_on'],
                ...$shared['reminders'],
                'confidential' => $template->confidential,
                'fields' => $shared['fields'],
                'template_id' => $template->id,
            ], minSigners: 1);

            $this->settle($row, SigningBulkSendRow::SENT, null, $envelope);
        } catch (ValidationException $exception) {
            if ($this->alreadySent($row, $document)) {
                return;
            }

            $this->discard($user, $company, $document);

            if (MailQuota::remaining($company, 'documents') < $needed) {
                $going = SigningBulkSend::query()->whereKey($row->bulk_send_id)->value('status') === SigningBulkSend::RUNNING;
                SigningBulkSendRow::query()->whereKey($row->id)->where('status', SigningBulkSendRow::PROCESSING)->update($going
                    ? ['status' => SigningBulkSendRow::PENDING, 'document_id' => null]
                    : ['status' => SigningBulkSendRow::SKIPPED, 'reason' => __('Massutskicket avbröts innan raden skickades.'), 'payload' => null, 'processed_at' => now()]);

                return;
            }

            $this->settle($row, SigningBulkSendRow::FAILED, Str::limit((string) collect($exception->errors())->flatten()->first(), 250, ''));
        } catch (Throwable $exception) {
            report($exception);

            if ($this->alreadySent($row, $document)) {
                return;
            }

            $this->discard($user, $company, $document);
            $this->settle($row, SigningBulkSendRow::FAILED, __('Något gick fel när dokumentet skulle skickas. Gör en ny körning med den här raden.'));
        }
    }

    /** An envelope exists for the row's document: the row was sent, whatever went wrong after, and is settled as sent. */
    private function alreadySent(SigningBulkSendRow $row, ?SigningDocument $document): bool
    {
        if (! $document instanceof SigningDocument) {
            return false;
        }

        $envelope = SigningEnvelope::query()->where('document_type', SigningDocument::SIGNING_TYPE)->where('signable_id', $document->id)->first();

        if (! $envelope instanceof SigningEnvelope) {
            return false;
        }

        $this->settle($row, SigningBulkSendRow::SENT, null, $envelope);

        return true;
    }

    /** The row's own copy of the template's file, as an ordinary unsent document the send then uses. */
    private function document(User $user, Company $company, SigningTemplate $template, SigningBulkSendRow $row, string $bytes): SigningDocument
    {
        $existing = $row->document_id === null ? null : SigningDocument::query()->find($row->document_id);

        if ($existing instanceof SigningDocument) {
            return $existing;
        }

        $stored = "signing-documents/{$company->id}/".Str::uuid()->toString().'.pdf';
        Storage::disk(SigningRequest::DISK)->put($stored, $bytes);

        $document = SigningDocument::create([
            'company_id' => $company->id,
            'title' => $template->name,
            'path' => $stored,
            'name' => (string) $template->file_name,
            'sha256' => (string) $template->sha256,
            'bytes' => (int) $template->bytes,
            'page_count' => (int) $template->page_count,
            'uploaded_by' => $user->id,
        ]);

        SigningBulkSendRow::query()->whereKey($row->id)->update(['document_id' => $document->id]);

        return $document;
    }

    private function discard(User $user, Company $company, ?SigningDocument $document): void
    {
        if (! $document instanceof SigningDocument) {
            return;
        }

        try {
            app(DeleteSigningDocument::class)($user, $company, $document);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function settle(SigningBulkSendRow $row, string $status, ?string $reason, ?SigningEnvelope $envelope = null): void
    {
        SigningBulkSendRow::query()->whereKey($row->id)->where('status', SigningBulkSendRow::PROCESSING)->update([
            'status' => $status,
            'reason' => $reason,
            'envelope_id' => $envelope?->id,
            'payload' => null,
            'processed_at' => now(),
        ]);
    }

    /** A row a dead worker left in PROCESSING: sent if an envelope exists for its document, else tried again. */
    private function settleStale(SigningBulkSend $run): void
    {
        $stale = SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->where('status', SigningBulkSendRow::PROCESSING)->get();

        foreach ($stale as $row) {
            $envelope = $row->document_id === null ? null : SigningEnvelope::query()->where('document_type', SigningDocument::SIGNING_TYPE)->where('signable_id', $row->document_id)->first();

            if ($envelope instanceof SigningEnvelope) {
                $this->settle($row, SigningBulkSendRow::SENT, null, $envelope);

                continue;
            }

            SigningBulkSendRow::query()->whereKey($row->id)->update(['status' => SigningBulkSendRow::PENDING]);
        }
    }

    /** Mails one envelope takes from the quota: the signers of its first step (copies are not mailed at the send). */
    public function mailsPerEnvelope(SigningTemplate $template): int
    {
        $first = (int) $template->roles->min('step');

        return max(1, $template->roles->where('step', $first)->count());
    }

    private function count(SigningBulkSend $run): void
    {
        $this->runs->recount($run);
    }

    private function again(SigningBulkSend $run, int $seconds): void
    {
        $this->count($run);
        $job = RunSigningBulkSend::dispatch($run->company_id, $run->id);

        if ($seconds > 0) {
            $job->delay(now()->addSeconds($seconds));
        }
    }
}
