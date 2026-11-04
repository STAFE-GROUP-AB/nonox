<?php

declare(strict_types=1);

namespace App\Modules\Crm\Console;

use App\Modules\Crm\Domain\EmailStatus;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Mailing;
use App\Modules\Crm\Models\MailingRecipient;
use App\Modules\Crm\Support\SuppressionList;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Retention for CRM stage 5 (ADR-048), once a day, for every company:
 *
 * - an e-mail in "Ej kopplade" that nobody handled is removed after 30 days;
 * - a logged e-mail that no activity shows any more (its account was
 *   erased) is removed;
 * - 90 days after a mailing finished, a recipient's address and person
 *   give way to the suppression list's keyed hash of the address: a stub
 *   that keeps the unsubscribe link in the message working
 *   (marknadsföringslagen 20 §) without saying who it was for;
 * - after three years the stub goes too; the mailing keeps its counts.
 *
 * TODO(legal-verify): the 30-day, 90-day and three-year periods
 * (docs/gdpr/lagringstider.md).
 */
class PruneMailCommand extends Command
{
    public const int WAITING_DAYS = 30;

    public const int RECIPIENT_EMAIL_DAYS = 90;

    public const int RECIPIENT_YEARS = 3;

    protected $signature = 'crm:prune-mail';

    protected $description = 'Remove unhandled CRM e-mail after 30 days and turn mailing recipients into stubs after 90 days';

    public function handle(): int
    {
        $now = CarbonImmutable::now();

        // Retention runs over every company's rows at once; nothing is read across companies, only removed by age.
        [$waiting, $orphans, $addresses, $rows] = CompanyScope::bypass(function () use ($now): array {
            $this->info('Removing unhandled e-mail...');
            $waiting = Email::query()->whereIn('status', [EmailStatus::Unmatched->value, EmailStatus::Held->value])->where('created_at', '<', $now->subDays(self::WAITING_DAYS))->delete();

            $this->info('Removing e-mail no timeline shows...');
            $orphans = Email::query()->where('status', EmailStatus::Logged->value)->whereNull('person_id')->whereDoesntHave('activities')->where('created_at', '<', $now->subDay())->delete();

            $this->info('Turning old mailing recipients into stubs...');
            $finished = fn (CarbonImmutable $before) => Mailing::query()->select('id')->whereNotNull('finished_at')->where('finished_at', '<', $before);
            $addresses = 0;

            MailingRecipient::query()
                ->whereIn('mailing_id', $finished($now->subDays(self::RECIPIENT_EMAIL_DAYS)))
                ->where(fn ($query) => $query->whereNotNull('email')->orWhereNotNull('person_id'))
                ->chunkById(500, function ($recipients) use ($now, &$addresses): void {
                    foreach ($recipients as $recipient) {
                        MailingRecipient::query()->whereKey($recipient->id)->update([
                            'email_hash' => $recipient->email_hash ?? ($recipient->email === null ? null : SuppressionList::emailHash($recipient->email)),
                            'email' => null,
                            'person_id' => null,
                            'account_id' => null,
                            'updated_at' => $now,
                        ]);
                        $addresses++;
                    }
                });

            $rows = MailingRecipient::query()->whereIn('mailing_id', $finished($now->subYears(self::RECIPIENT_YEARS)))->delete();

            return [$waiting, $orphans, $addresses, $rows];
        });

        $this->comment("Removed {$waiting} unhandled and {$orphans} unlinked e-mails, {$addresses} recipients made stubs and {$rows} stubs removed.");

        return self::SUCCESS;
    }
}
