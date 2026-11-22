<?php

declare(strict_types=1);

namespace App\Modules\Signing\Actions;

use App\Jobs\RunSigningBulkSend;
use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Signing\Models\SigningBulkSend;
use App\Modules\Signing\Models\SigningBulkSendRow;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\SigningModule;
use App\Modules\Signing\Support\BulkSendRows;
use App\Modules\Signing\Support\TemplateFingerprint;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bulk send for signing (ADR-046 amendment 2026-11-18, gap 8, package S15):
 * one template and a file with one row per envelope. prepare() reads the
 * file once and checks every row, and sends nothing (principle 9: a person
 * decides what is sent); confirm() is that person's decision and queues the
 * envelopes (ProcessSigningBulkSend); cancel() stops a run, or throws a
 * preview away. Everything needs signing:manage and the module on.
 *
 * The file is never stored. The people of each row are kept encrypted in the
 * run's rows until the row is sent or the run ends, and then removed. The
 * audit entries hold ids and counts only.
 */
final class ManageSigningBulkSends
{
    /** Runs that are previewed or running at the same time, per company. */
    public const int MAX_OPEN = 5;

    public const string TEMPLATE_CHANGED = 'Mallen har ändrats sedan förhandsgranskningen. Ta bort förhandsgranskningen och läs in filen igen.';

    /** A preview nobody confirmed is thrown away after this many hours. */
    public const int PREVIEW_HOURS = 24;

    /** A running run with no progress this long is queued again; after STOP_DAYS it is stopped. */
    public const int REDISPATCH_HOURS = 2;

    public const int STOP_DAYS = 7;

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function prepare(User $user, Company $company, SigningTemplate $template, string $path): SigningBulkSend
    {
        $this->authorize($user, $company);
        abort_unless($template->company_id === $company->id && SigningTemplate::query()->visibleTo($user, $company)->whereKey($template->id)->exists(), 404);

        if ($template->isArchived() || ! $template->hasFile()) {
            throw ValidationException::withMessages(['template' => __('Massutskick går bara med en aktiv mall för ett eget dokument.')]);
        }

        if ($template->roles()->count() === 0) {
            throw ValidationException::withMessages(['template' => __('Mallen har inga roller.')]);
        }

        $open = CurrentCompany::run($company, fn (): int => SigningBulkSend::query()->whereIn('status', [SigningBulkSend::PREVIEW, SigningBulkSend::RUNNING])->count());

        if ($open >= self::MAX_OPEN) {
            throw ValidationException::withMessages(['file' => __('Det finns redan :count pågående massutskick. Avsluta eller ta bort några innan du startar ett nytt.', ['count' => self::MAX_OPEN])]);
        }

        $read = BulkSendRows::read($template, $path);
        $valid = count(array_filter($read['rows'], fn (array $row): bool => $row['status'] === SigningBulkSendRow::PENDING));

        return DB::transaction(fn (): SigningBulkSend => CurrentCompany::run($company, function () use ($user, $company, $template, $read, $valid): SigningBulkSend {
            $run = SigningBulkSend::create([
                'company_id' => $company->id,
                'template_id' => $template->id,
                'status' => SigningBulkSend::PREVIEW,
                'total_rows' => count($read['rows']),
                'valid_rows' => $valid,
                'invalid_rows' => count($read['rows']) - $valid,
                'template_fingerprint' => TemplateFingerprint::of($template),
                'created_by' => $user->id,
            ]);

            foreach ($read['rows'] as $row) {
                SigningBulkSendRow::create([
                    'company_id' => $company->id,
                    'bulk_send_id' => $run->id,
                    'row_number' => $row['row'],
                    'status' => $row['status'],
                    'reason' => $row['reason'],
                    'payload' => $row['payload'],
                ]);
            }

            Audit::record('signing.bulk_send_prepared', $run, ['signing_bulk_send_id' => $run->id, 'signing_template_id' => $template->id, 'rows' => $run->total_rows, 'valid' => $run->valid_rows, 'invalid' => $run->invalid_rows], $company, $user);

            return $run;
        }));
    }

    /**
     * The person's decision: the valid rows are queued, the invalid ones are never sent.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function confirm(User $user, Company $company, SigningBulkSend $run): SigningBulkSend
    {
        $this->authorize($user, $company);
        abort_unless($run->company_id === $company->id, 404);

        $confirmed = DB::transaction(fn (): SigningBulkSend => CurrentCompany::run($company, function () use ($user, $company, $run): SigningBulkSend {
            $locked = SigningBulkSend::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SigningBulkSend::PREVIEW) {
                throw ValidationException::withMessages(['run' => __('Massutskicket är redan startat eller avslutat.')]);
            }

            $template = $locked->template_id === null ? null : SigningTemplate::query()->visibleTo($user, $company)->find($locked->template_id);

            if (! $template instanceof SigningTemplate || $template->isArchived()) {
                throw ValidationException::withMessages(['run' => __('Mallen finns inte kvar eller är arkiverad. Starta ett nytt massutskick.')]);
            }

            if (! is_string($locked->template_fingerprint) || ! hash_equals($locked->template_fingerprint, TemplateFingerprint::of($template))) {
                throw ValidationException::withMessages(['run' => __(self::TEMPLATE_CHANGED)]);
            }

            if ($locked->valid_rows < 1) {
                throw ValidationException::withMessages(['run' => __('Ingen rad kan skickas. Rätta filen och läs in den igen.')]);
            }

            $now = CarbonImmutable::now();
            $locked->forceFill(['status' => SigningBulkSend::RUNNING, 'confirmed_at' => $now, 'started_at' => $now])->save();
            Audit::record('signing.bulk_send_confirmed', $locked, ['signing_bulk_send_id' => $locked->id, 'signing_template_id' => $locked->template_id, 'rows' => $locked->valid_rows], $company, $user);

            return $locked;
        }));

        RunSigningBulkSend::dispatch($company->id, $confirmed->id);

        return $confirmed;
    }

    /**
     * A preview is thrown away; a run that is going stops (what is sent stays sent) and its unsent rows are skipped.
     *
     * @throws AuthorizationException
     */
    public function cancel(User $user, Company $company, SigningBulkSend $run): void
    {
        $this->authorize($user, $company);
        abort_unless($run->company_id === $company->id, 404);

        DB::transaction(fn () => CurrentCompany::run($company, function () use ($user, $company, $run): void {
            $locked = SigningBulkSend::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === SigningBulkSend::PREVIEW) {
                $locked->delete();
                Audit::record('signing.bulk_send_discarded', null, ['signing_bulk_send_id' => $locked->id], $company, $user);

                return;
            }

            if ($locked->status !== SigningBulkSend::RUNNING) {
                return;
            }

            $this->stop($locked, 'signing.bulk_send_cancelled', __('Massutskicket avbröts innan raden skickades.'), $company, $user);
        }));
    }

    /**
     * Ends a run that is still going: its unsent rows are skipped with a reason, every payload is emptied. A row that
     * already has an envelope is settled as sent first, and a row that a live worker is sending is left to it: its
     * own settling (only from PROCESSING) records the result.
     */
    public function stop(SigningBulkSend $run, string $audit, string $reason, Company $company, ?User $user): void
    {
        $this->settleSent($run);
        SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->where('status', SigningBulkSendRow::PENDING)
            ->update(['status' => SigningBulkSendRow::SKIPPED, 'reason' => $reason, 'processed_at' => now()]);
        $this->finish($run, SigningBulkSend::CANCELLED, $audit, $company, $user);
    }

    /** A row in PROCESSING for which an envelope exists is sent, whatever else happened to the run. */
    public function settleSent(SigningBulkSend $run): void
    {
        $rows = SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->where('status', SigningBulkSendRow::PROCESSING)->whereNotNull('document_id')->get();

        foreach ($rows as $row) {
            $envelope = SigningEnvelope::query()->where('document_type', SigningDocument::SIGNING_TYPE)->where('signable_id', $row->document_id)->first();

            if ($envelope instanceof SigningEnvelope) {
                SigningBulkSendRow::query()->whereKey($row->id)->where('status', SigningBulkSendRow::PROCESSING)
                    ->update(['status' => SigningBulkSendRow::SENT, 'reason' => null, 'envelope_id' => $envelope->id, 'payload' => null, 'processed_at' => now()]);
            }
        }
    }

    /**
     * What signing:prune does with runs that are going (ADR-046 S15): a run with no progress for REDISPATCH_HOURS is
     * queued again (the job is single per run, so this never doubles work); one with none for STOP_DAYS is stopped,
     * its rows settled and every payload emptied. A run that ended with a row still PROCESSING is settled too.
     */
    public function tidy(Company $company, CarbonImmutable $now): void
    {
        $running = SigningBulkSend::query()->where('status', SigningBulkSend::RUNNING)->get();

        foreach ($running as $run) {
            $last = SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->max('processed_at');
            $progress = CarbonImmutable::parse($last ?: ($run->started_at ?? $run->created_at));

            if ($progress->lt($now->subDays(self::STOP_DAYS))) {
                $this->stop($run, 'signing.bulk_send_cancelled', __('Massutskicket avbröts automatiskt: det kom inte vidare på :days dagar.', ['days' => self::STOP_DAYS]), $company, null);

                continue;
            }

            if ($progress->lt($now->subHours(self::REDISPATCH_HOURS))) {
                RunSigningBulkSend::dispatch($company->id, $run->id);
            }
        }

        $leftover = SigningBulkSend::query()->whereIn('status', [SigningBulkSend::CANCELLED, SigningBulkSend::COMPLETED])->whereExists(fn ($query) => $query->selectRaw('1')->from('signing_bulk_send_rows')->whereColumn('signing_bulk_send_rows.bulk_send_id', 'signing_bulk_sends.id')->whereIn('status', [SigningBulkSendRow::PROCESSING, SigningBulkSendRow::PENDING]))->get();

        foreach ($leftover as $run) {
            $this->settleSent($run);
            SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->whereIn('status', [SigningBulkSendRow::PROCESSING, SigningBulkSendRow::PENDING])->update(['status' => SigningBulkSendRow::SKIPPED, 'reason' => __('Massutskicket avbröts innan raden skickades.'), 'payload' => null, 'processed_at' => now()]);
            $this->recount($run);
        }
    }

    public function recount(SigningBulkSend $run): void
    {
        $counts = SigningBulkSendRow::query()->where('bulk_send_id', $run->id)
            ->selectRaw("count(*) filter (where status = 'SENT') as sent, count(*) filter (where status in ('FAILED', 'SKIPPED')) as failed")
            ->first();

        $run->forceFill(['sent_count' => (int) ($counts->sent ?? 0), 'failed_count' => (int) ($counts->failed ?? 0)])->save();
    }

    /** Counts, status, no payloads left. */
    public function finish(SigningBulkSend $run, string $status, string $audit, Company $company, ?User $user): void
    {
        SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->whereNotNull('payload')->update(['payload' => null]);

        $this->recount($run);

        // Only a run that is still going can end: a cancel that came in meanwhile is not overwritten, and writes no second end.
        $ended = SigningBulkSend::query()->whereKey($run->id)->where('status', SigningBulkSend::RUNNING)->update(['status' => $status, 'finished_at' => CarbonImmutable::now()]);
        $run->refresh();

        if ($ended !== 1) {
            return;
        }

        Audit::record($audit, $run, ['signing_bulk_send_id' => $run->id, 'sent' => $run->sent_count, 'failed' => $run->failed_count], $company, $user);
    }

    private function authorize(User $user, Company $company): void
    {
        abort_unless(app(ModuleManager::class)->isEnabled(SigningModule::KEY, $company), 404);

        if (! $user->hasTeamPermission($company, SigningModule::MANAGE)) {
            throw new AuthorizationException;
        }
    }
}
