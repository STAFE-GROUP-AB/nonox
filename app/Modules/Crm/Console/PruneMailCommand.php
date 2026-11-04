<?php

declare(strict_types=1);

namespace App\Modules\Crm\Console;

use App\Modules\Crm\Domain\EmailStatus;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Mailing;
use App\Modules\Crm\Models\MailingClick;
use App\Modules\Crm\Models\MailingRecipient;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Retention for CRM stage 5 (ADR-048), once a day, for every company:
 *
 * - an e-mail in "Ej kopplade" that nobody handled is removed after 30 days;
 * - a logged e-mail that no activity shows any more (its account was
 *   erased) is removed;
 * - a mailing recipient's address is removed 90 days after the mailing
 *   finished, and the click marks after 13 months; the mailing keeps its
 *   counts. The row itself stays as a stub (the token's hash and the
 *   person's id), so the unsubscribe link in an old message keeps working
 *   (marknadsföringslagen 20 §); the person link goes when the person is erased.
 *
 * TODO(legal-verify): the 30-day, 90-day and 13-month periods
 * (docs/gdpr/lagringstider.md).
 */
class PruneMailCommand extends Command
{
    public const int WAITING_DAYS = 30;

    public const int RECIPIENT_EMAIL_DAYS = 90;

    public const int RECIPIENT_MONTHS = 13;

    protected $signature = 'crm:prune-mail';

    protected $description = 'Remove unhandled CRM e-mail after 30 days and mailing recipients\' addresses after 90 days';

    public function handle(): int
    {
        $now = CarbonImmutable::now();

        // Retention runs over every company's rows at once; nothing is read across companies, only removed by age.
        [$waiting, $orphans, $addresses, $rows] = CompanyScope::bypass(function () use ($now): array {
            $this->info('Removing unhandled e-mail...');
            $waiting = Email::query()->whereIn('status', [EmailStatus::Unmatched->value, EmailStatus::Held->value])->where('created_at', '<', $now->subDays(self::WAITING_DAYS))->delete();

            $this->info('Removing e-mail no timeline shows...');
            $orphans = Email::query()->where('status', EmailStatus::Logged->value)->whereNull('person_id')->whereDoesntHave('activities')->where('created_at', '<', $now->subDay())->delete();

            $this->info('Removing old mailing addresses...');
            $finished = fn (CarbonImmutable $before) => Mailing::query()->select('id')->whereNotNull('finished_at')->where('finished_at', '<', $before);
            $addresses = MailingRecipient::query()->whereNotNull('email')->whereIn('mailing_id', $finished($now->subDays(self::RECIPIENT_EMAIL_DAYS)))->update(['email' => null, 'updated_at' => $now]);
            $old = MailingRecipient::query()->select('id')->whereIn('mailing_id', $finished($now->subMonthsNoOverflow(self::RECIPIENT_MONTHS)));
            $rows = MailingClick::query()->whereIn('recipient_id', $old)->delete();
            MailingRecipient::query()->whereIn('mailing_id', $finished($now->subMonthsNoOverflow(self::RECIPIENT_MONTHS)))->whereNotNull('clicked_at')->update(['clicked_at' => null, 'updated_at' => $now]);

            return [$waiting, $orphans, $addresses, $rows];
        });

        $this->comment("Removed {$waiting} unhandled and {$orphans} unlinked e-mails, {$addresses} recipient addresses and {$rows} click marks.");

        return self::SUCCESS;
    }
}
