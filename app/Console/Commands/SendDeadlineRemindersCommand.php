<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Deadlines\CompanyDeadlines;
use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineAudience;
use App\Domain\Deadlines\DeadlineCompletion;
use App\Domain\Deadlines\DeadlineType;
use App\Domain\Deadlines\Workdays;
use App\Models\Company;
use App\Models\DeadlineReminder;
use App\Notifications\DeadlineReminder as DeadlineReminderNotice;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Every morning, a notice in the app to the owner and those who look after
 * the company (DeadlineAudience) before a date on Mina datum (ADR-051,
 * stage 2; REGLER.md §10): 7 days before and on the last working day
 * before, and also 30 days before the income tax return and the annual
 * report to Bolagsverket. A reminder that was missed (the command did not
 * run that day) is sent the next run while the date is still ahead; only
 * the nearest due reminder is sent. Recommendations get none, nor a VAT or
 * AGI period that is already reported, and a date that has passed is not
 * reminded of. Each reminder is recorded in
 * deadline_reminders, unique per person, deadline and kind, so running the
 * command again sends nothing twice. Companies that have turned
 * Företagsguiden off, and archived companies, get nothing.
 */
class SendDeadlineRemindersCommand extends Command
{
    /** Days before a deadline a reminder goes out; 1 means the last working day before it. */
    public const array DAYS_BEFORE = [7, 1];

    /** The dates that also get a reminder 30 days before (REGLER.md §10). */
    public const array EARLY = [DeadlineType::IncomeTaxReturn, DeadlineType::AnnualReport];

    protected $signature = 'guide:send-deadline-reminders';

    protected $description = 'Påminner om datum i Mina datum 30, 7 och 1 dag före';

    public function handle(CompanyDeadlines $deadlines): int
    {
        $today = CarbonImmutable::today();
        $sent = 0;
        $companies = Company::query()->where('guide_enabled', true)->whereNull('archived_at')->where('personal_team', false)->get();

        foreach ($companies as $company) {
            $this->info("Går igenom företag {$company->id}...");
            $audience = DeadlineAudience::of($company);

            if ($audience === []) {
                continue;
            }

            $upcoming = $deadlines->upcoming($company, $today, 2);
            // A period already reported needs no reminder (as the VAT and AGI items in Att göra know it).
            $done = DeadlineCompletion::done($company, $upcoming);

            foreach ($upcoming as $deadline) {
                $before = self::due($deadline, $today);

                if ($before === null || in_array($deadline->key, $done, true)) {
                    continue;
                }

                $days = $deadline->daysLeft($today);

                foreach ($audience as $user) {
                    $inserted = CurrentCompany::run($company, fn (): int => DeadlineReminder::query()->insertOrIgnore([
                        'company_id' => $company->id,
                        'user_id' => $user->id,
                        'deadline_key' => $deadline->key,
                        'deadline_date' => $deadline->date->toDateString(),
                        'days_before' => $before,
                        'sent_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));

                    if ($inserted === 1) {
                        $user->notify(DeadlineReminderNotice::for($company, $deadline, $days));
                        $sent++;
                    }
                }
            }
        }

        $this->comment("{$sent} påminnelser skickade.");

        return self::SUCCESS;
    }

    /**
     * The nearest reminder that is due today for the deadline, as its day
     * count (30, 7 or 1), or null when none is.
     */
    public static function due(Deadline $deadline, CarbonImmutable $today): ?int
    {
        if ($deadline->isRecommendation || $deadline->date->lessThan($today->startOfDay())) {
            return null;
        }

        if (Workdays::before($deadline->date)->lessThanOrEqualTo($today)) {
            return 1;
        }

        if ($deadline->daysLeft($today) <= 7) {
            return 7;
        }

        return in_array($deadline->type, self::EARLY, true) && $deadline->daysLeft($today) <= 30 ? 30 : null;
    }
}
