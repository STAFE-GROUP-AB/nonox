<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Console;

use App\Models\Company;
use App\Modules\Inbox\Actions\NotifyInbox;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Mail\InboxSummaryMail;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Support\InboxAddress;
use App\Modules\Inbox\Support\InboxSettings;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * The daily summary per company that has asked for it, to the members who
 * handle the inbox. Sent once a day; nothing is sent when nothing happened.
 */
class InboxSummaryCommand extends Command
{
    protected $signature = 'inbox:daily-summary';

    protected $description = 'Send the daily inbox summary to companies that have asked for it';

    public function handle(ModuleManager $modules): int
    {
        $sent = 0;

        foreach (Company::query()->whereIn('id', $modules->companiesWith(InboxModule::KEY))->whereNull('archived_at')->orderBy('id')->get() as $company) {
            if (! InboxSettings::for($company)['daily_summary']) {
                continue;
            }

            $this->info("Summarising the inbox of company `{$company->id}`...");
            $sent += CurrentCompany::run($company, function () use ($company): int {
                $mailbox = InboxAddress::mailbox($company);

                if ($mailbox->summary_sent_on?->isToday()) {
                    return 0;
                }

                $statuses = fn (string $tab): array => array_map(fn (MessageStatus $status): string => $status->value, MessageStatus::tabs()[$tab]);
                $counts = [
                    'review' => InboxMessage::query()->whereIn('status', $statuses('att-granska'))->count(),
                    'ready' => InboxMessage::query()->whereIn('status', $statuses('utkast-klara'))->count(),
                    'booked' => InboxMessage::query()->whereIn('status', $statuses('bokfort'))->where('handled_at', '>=', now()->subDay())->count(),
                    'received' => InboxMessage::query()->where('received_at', '>=', now()->subDay())->count(),
                ];

                if (array_sum($counts) === 0) {
                    return 0;
                }

                $recipients = NotifyInbox::recipients($company);

                foreach ($recipients as $user) {
                    Mail::to($user->email)->send(new InboxSummaryMail($company->name, $counts));
                }

                $mailbox->forceFill(['summary_sent_on' => now()->toDateString()])->save();

                return $recipients->count();
            });
        }

        $this->comment("Sent {$sent} summaries.");

        return self::SUCCESS;
    }
}
