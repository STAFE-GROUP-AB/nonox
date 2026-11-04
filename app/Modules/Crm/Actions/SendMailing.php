<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\MailingAudience;
use App\Modules\Crm\Domain\MailingStatus;
use App\Modules\Crm\Domain\MarketingEligibility;
use App\Modules\Crm\Domain\MergeFields;
use App\Modules\Crm\Jobs\SendMailingBatch;
use App\Modules\Crm\Mail\MailingMail;
use App\Modules\Crm\Models\Mailing;
use App\Modules\Crm\Models\MailingLink;
use App\Modules\Crm\Models\MailingRecipient;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\MailingRenderer;
use App\Modules\Crm\Support\MailingTokens;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sends a mailing (ADR-048), with crm:mailings.
 *
 * - start: the recipients are fixed now (MailingAudience: the segment
 *   filtered by MarketingEligibility), each with a row and a token, and
 *   the links of the body are stored. With a time in the future the
 *   mailing is scheduled; otherwise the first batch goes at once.
 * - batch: crm:send-mailings dispatches one batch per sending mailing
 *   every minute. A batch sends at most BATCH messages and stays within
 *   the company's caps per hour and per day (services.crm), so a large
 *   segment goes out over hours and the sending domain's reputation is
 *   not risked. Each recipient is checked again right before their
 *   message: who unsubscribed, objected or bounced since is skipped.
 * - cancel: what has not gone stays unsent.
 * - test: one message to the sender's own address, links not tracked.
 *
 * Every message has the sender's identity, postal address and an
 * unsubscribe link (marknadsföringslagen 20 §, e-handelslagen 9 §), and a
 * mailing cannot start without the company's postal address. A test
 * company's messages never leave NONOX (ADR-032).
 */
final class SendMailing
{
    /** Messages per batch (one batch a minute). */
    public const int BATCH = 50;

    public function __construct(private readonly ModuleManager $modules) {}

    public function start(User $user, Company $company, Mailing $mailing, ?string $scheduledAt = null): Mailing
    {
        Gate::forUser($user)->authorize('mailings', [Mailing::class, $company]);

        if (! MailingRenderer::hasPostalAddress($company)) {
            throw ValidationException::withMessages(['mailing' => __('Varje utskick ska ha företagets postadress. Fyll i adress, postnummer och ort under Företagsinställningar först.')]);
        }

        $when = blank($scheduledAt) ? null : CarbonImmutable::parse((string) $scheduledAt, 'Europe/Stockholm');

        if ($when !== null && $when->lessThan(now()->subMinute())) {
            throw ValidationException::withMessages(['scheduled_at' => __('Välj en tid som inte har passerat.')]);
        }

        $mailing = DB::transaction(fn (): Mailing => CurrentCompany::run($company, function () use ($user, $company, $mailing, $when): Mailing {
            $mailing = Mailing::query()->lockForUpdate()->findOrFail($mailing->id);
            SaveMailing::guardDraft($mailing);

            if ($mailing->segment_id === null) {
                throw ValidationException::withMessages(['segment_id' => __('Välj vilket segment utskicket ska gå till.')]);
            }

            $audience = MailingAudience::for($mailing);

            if ($audience['total'] > MailingAudience::MAX_RECIPIENTS) {
                throw ValidationException::withMessages(['segment_id' => __('Segmentet är för stort för ett utskick. Dela upp det i mindre segment.')]);
            }

            if ($audience['recipients'] === []) {
                throw ValidationException::withMessages(['segment_id' => __('Ingen i segmentet kan få utskicket. Se varför under Mottagare.')]);
            }

            foreach (MailingRenderer::links($mailing->body) as $position => $url) {
                MailingLink::create(['mailing_id' => $mailing->id, 'url' => mb_substr($url, 0, 2000), 'position' => $position + 1]);
            }

            foreach ($audience['recipients'] as $recipient) {
                $row = MailingRecipient::create([
                    'mailing_id' => $mailing->id,
                    'person_id' => $recipient['person']->id,
                    // The account that put the person in the segment: {företag} names it.
                    'account_id' => $recipient['person']->accounts->first()?->id,
                    'email' => $recipient['email'],
                    'token_hash' => hash('sha256', Str::random(40)),
                ]);
                $row->forceFill(['token_hash' => MailingTokens::hash(MailingTokens::token($row->id))])->save();
            }

            $scheduled = $when !== null && $when->isFuture();
            $mailing->forceFill([
                'status' => $scheduled ? MailingStatus::Scheduled : MailingStatus::Sending,
                'scheduled_at' => $when,
                'started_at' => $scheduled ? null : now(),
                'sent_by' => $user->id,
                'recipient_count' => count($audience['recipients']),
                'excluded' => $audience['excluded'],
            ])->save();

            Audit::record('crm.mailing_started', $mailing, ['mailing_id' => $mailing->id, 'recipients' => $mailing->recipient_count, 'excluded' => $audience['excluded'], 'scheduled' => $scheduled], $company, $user);

            return $mailing;
        }));

        if ($mailing->status === MailingStatus::Sending) {
            SendMailingBatch::dispatch($company->id, $mailing->id);
        }

        return $mailing;
    }

    public function cancel(User $user, Company $company, Mailing $mailing): void
    {
        Gate::forUser($user)->authorize('mailings', [Mailing::class, $company]);

        DB::transaction(fn () => CurrentCompany::run($company, function () use ($user, $company, $mailing): void {
            $mailing = Mailing::query()->lockForUpdate()->findOrFail($mailing->id);

            if (! in_array($mailing->status, [MailingStatus::Scheduled, MailingStatus::Sending], true)) {
                throw ValidationException::withMessages(['mailing' => __('Utskicket går inte att avbryta.')]);
            }

            $cancelled = MailingRecipient::query()->where('mailing_id', $mailing->id)->where('status', MailingRecipient::QUEUED)->update(['status' => MailingRecipient::CANCELLED, 'updated_at' => now()]);
            $mailing->forceFill(['status' => MailingStatus::Cancelled, 'finished_at' => now()])->save();

            Audit::record('crm.mailing_cancelled', $mailing, ['mailing_id' => $mailing->id, 'unsent' => $cancelled], $company, $user);
        }));
    }

    /** One message to the sender's own address: merge fields with their own name, no tracking, no unsubscribe token. */
    public function test(User $user, Company $company, Mailing $mailing): void
    {
        Gate::forUser($user)->authorize('mailings', [Mailing::class, $company]);
        MailQuota::take($company, 'documents', 'mailing');

        $person = new Person(['name' => $user->name, 'email' => $user->email]);
        $values = MergeFields::values($company, $user, $person, null);
        $subject = __('TEST: :subject', ['subject' => MergeFields::render($mailing->subject, $values)]);

        if (! $company->isTestCompany()) {
            Mail::to((string) $user->email)->send(new MailingMail(
                $company->legal_name ?: $company->name,
                $company->email,
                mb_substr($subject, 0, 250),
                MailingRenderer::html($mailing, $company, $values, '#'),
                MailingRenderer::text($mailing, $company, $values, null),
                null,
                null,
            ));
        }

        Audit::record('crm.mailing_tested', $mailing, ['mailing_id' => $mailing->id], $company, $user);
    }

    /** Scheduled mailings whose time has come start sending; called every minute by crm:send-mailings. */
    public function release(Company $company): void
    {
        CurrentCompany::run($company, fn () => Mailing::query()
            ->where('status', MailingStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now())
            ->update(['status' => MailingStatus::Sending->value, 'started_at' => now(), 'updated_at' => now()]));
    }

    /**
     * Sends the next batch of one mailing. Returns how many messages went.
     * One batch at a time per mailing (a cache lock).
     */
    public function batch(Company $company, int $mailingId): int
    {
        if (! $this->modules->isEnabled(CrmModule::KEY, $company) || $company->isArchived()) {
            return 0;
        }

        // One batch at a time per company, so two mailings cannot both use the same room under the caps.
        $lock = Cache::lock("crm-mailing-batch:{$company->id}", 600);

        if (! $lock->get()) {
            return 0;
        }

        try {
            return CurrentCompany::run($company, fn (): int => $this->sendBatch($company, $mailingId));
        } finally {
            $lock->release();
        }
    }

    private function sendBatch(Company $company, int $mailingId): int
    {
        $mailing = Mailing::query()->find($mailingId);

        if (! $mailing instanceof Mailing || $mailing->status !== MailingStatus::Sending) {
            return 0;
        }

        $room = min(self::BATCH, $this->room($company, 'hour', 3600, (int) config('services.crm.mailing_hourly_cap', 500)), $this->room($company, 'day', 86_400, (int) config('services.crm.mailing_daily_cap', 2000)));

        $recipients = $room <= 0 ? collect() : MailingRecipient::query()
            ->where('mailing_id', $mailing->id)
            ->where('status', MailingRecipient::QUEUED)
            ->orderBy('id')
            ->limit($room)
            ->get();

        $people = Person::query()->whereIn('id', $recipients->pluck('person_id')->filter())->with('accounts.customer')->get()->keyBy('id');
        $links = MailingRenderer::linkMap(MailingLink::query()->where('mailing_id', $mailing->id)->get());
        $sent = 0;

        foreach ($recipients as $recipient) {
            $person = $recipient->person_id === null ? null : $people->get($recipient->person_id);
            $reason = $person instanceof Person ? MarketingEligibility::check($person, $mailing->customer_exception) : null;

            if (! $person instanceof Person || $reason !== null || mb_strtolower((string) $person->email) !== $recipient->email) {
                MailingRecipient::query()->whereKey($recipient->id)->where('status', MailingRecipient::QUEUED)->update(['status' => MailingRecipient::SKIPPED, 'skip_reason' => $reason->value ?? 'CHANGED', 'updated_at' => now()]);

                continue;
            }

            // Claimed before it goes: a cancel in between has turned the row CANCELLED, and then nothing is sent.
            if (MailingRecipient::query()->whereKey($recipient->id)->where('status', MailingRecipient::QUEUED)->update(['status' => MailingRecipient::SENT, 'sent_at' => now(), 'updated_at' => now()]) !== 1) {
                continue;
            }

            $this->hit($company, 'hour', 3600);
            $this->hit($company, 'day', 86_400);

            try {
                $this->sendOne($company, $mailing, $recipient, $person, $links);
                Mailing::query()->whereKey($mailing->id)->increment('sent_count');
                $sent++;
            } catch (Throwable $exception) {
                Log::warning('CRM mailing: a message could not be sent.', ['company_id' => $company->id, 'mailing_id' => $mailing->id, 'recipient_id' => $recipient->id, 'error' => $exception->getMessage()]);
                MailingRecipient::query()->whereKey($recipient->id)->update(['status' => MailingRecipient::FAILED, 'sent_at' => null, 'updated_at' => now()]);
                Mailing::query()->whereKey($mailing->id)->increment('failed_count');
            }
        }

        // Only a mailing still sending becomes sent: a cancel in between stays cancelled.
        if (! MailingRecipient::query()->where('mailing_id', $mailing->id)->where('status', MailingRecipient::QUEUED)->exists()
            && Mailing::query()->whereKey($mailing->id)->where('status', MailingStatus::Sending->value)->update(['status' => MailingStatus::Sent->value, 'finished_at' => now(), 'updated_at' => now()]) === 1) {
            Audit::record('crm.mailing_sent', $mailing, ['mailing_id' => $mailing->id, 'sent' => (int) $mailing->fresh()?->sent_count], $company);
        }

        return $sent;
    }

    /** @param  array<string, MailingLink>  $links */
    private function sendOne(Company $company, Mailing $mailing, MailingRecipient $recipient, Person $person, array $links): void
    {
        $token = MailingTokens::token($recipient->id);
        $unsubscribe = MailingTokens::unsubscribeUrl($token);
        $account = $person->accounts->firstWhere('id', $recipient->account_id) ?? $person->accounts->first();
        $values = MergeFields::values($company, null, $person, $account);
        $track = fn (string $url): ?string => isset($links[$url]) ? MailingTokens::clickUrl($token, $links[$url]) : null;

        if ($company->isTestCompany()) {
            return;
        }

        Mail::to($recipient->email)->send(new MailingMail(
            $company->legal_name ?: $company->name,
            $company->email,
            mb_substr(MergeFields::render($mailing->subject, $values), 0, 250),
            MailingRenderer::html($mailing, $company, $values, $unsubscribe, $track),
            MailingRenderer::text($mailing, $company, $values, $unsubscribe),
            $unsubscribe,
            $recipient->id,
        ));
    }

    private function room(Company $company, string $period, int $seconds, int $cap): int
    {
        // The limiter keeps a count per key; read it as the whole number it is.
        return max(0, $cap - (int) RateLimiter::attempts("crm-mailing-{$period}:{$company->id}"));
    }

    private function hit(Company $company, string $period, int $seconds): void
    {
        RateLimiter::hit("crm-mailing-{$period}:{$company->id}", $seconds);
    }
}
