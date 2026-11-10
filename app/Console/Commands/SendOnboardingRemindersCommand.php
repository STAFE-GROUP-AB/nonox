<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Waitlist\WaitlistStatus;
use App\Mail\OnboardingReminderMail;
use App\Models\WaitlistEntry;
use App\Support\Audit\Audit;
use App\Support\Hq\OnboardingProgress;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The two follow-up mails of the onboarding after approval (ADR-038
 * addendum), once a day:
 *
 *  - login: some days after approval, to someone who has not signed in;
 *  - help: some days after the company was created, if nothing has been
 *    moved in (no finished SIE or register import, no Fortnox move, no posted
 *    voucher and no issued invoice).
 *
 * Each goes out at most once per entry (the sent_at columns, checked again
 * under a row lock), nothing goes out later than 14 days after the approval,
 * and never to an entry that is no longer approved or has opted out. The
 * audit log gets ids only.
 */
class SendOnboardingRemindersCommand extends Command
{
    protected $signature = 'waitlist:send-onboarding-reminders';

    protected $description = 'Skickar de två påminnelserna efter godkännande: logga in, och Behöver du hjälp?';

    private const array COLUMNS = [
        OnboardingReminderMail::LOGIN => 'login_reminder_sent_at',
        OnboardingReminderMail::HELP => 'help_reminder_sent_at',
    ];

    public function handle(): int
    {
        $sent = 0;

        foreach (self::COLUMNS as $kind => $column) {
            $ids = $this->due($kind)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

            $this->info('Påminnelse '.$kind.': '.count($ids).' att skicka …');

            foreach ($ids as $id) {
                if ($this->send($id, $kind, $column)) {
                    $sent++;
                }
            }
        }

        $this->comment("Klart, {$sent} mejl köade.");

        return self::SUCCESS;
    }

    /** @return Builder<WaitlistEntry> */
    private function due(string $kind): Builder
    {
        $stop = now()->subDays((int) config('nonox.waitlist.reminders_stop_after_days', 14));

        $query = OnboardingProgress::query()
            ->whereNull('reminders_opted_out_at')
            ->where('has_earlier_entry', false)
            ->where('decided_at', '>', $stop);

        if ($kind === OnboardingReminderMail::LOGIN) {
            return $query
                ->whereNull('login_reminder_sent_at')
                ->where('decided_at', '<=', now()->subDays((int) config('nonox.waitlist.login_reminder_after_days', 2)))
                ->where('signed_in', false)
                ->where('has_company', false)
                ->where('owned_company_before', false)
                ->where('approved_user_existed', false);
        }

        return $query
            ->whereNull('help_reminder_sent_at')
            ->where('has_company', true)
            ->where('moved_in', false)
            ->where('bookkept', false)
            ->where('invoice_sent', false)
            ->where('company_created_at', '<=', now()->subDays((int) config('nonox.waitlist.help_reminder_after_days', 5)));
    }

    private function send(int $id, string $kind, string $column): bool
    {
        try {
            return DB::transaction(function () use ($id, $kind, $column): bool {
                $entry = WaitlistEntry::query()->lockForUpdate()->find($id);

                // Checked again under the lock: another run, an opt-out or a decision may have come in since the list was read.
                if ($entry === null || $entry->status !== WaitlistStatus::Approved || $entry->reminders_opted_out_at !== null || $entry->getAttribute($column) !== null) {
                    return false;
                }

                // Stamped first, in the same transaction as the queueing: if the mail cannot be queued the stamp is rolled back.
                $entry->forceFill([$column => now()])->save();
                Audit::instance('waitlist.reminder_sent', $entry, ['entry_id' => $entry->id, 'kind' => $kind]);
                Mail::to($entry->email)->queue(new OnboardingReminderMail($entry, $kind));

                return true;
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->warn("Anmälan {$id}: påminnelsen kunde inte köas, försöker igen nästa dag.");

            return false;
        }
    }
}
