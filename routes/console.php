<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// The systemdokumentation in use, when it has changed (#44), for installations without a deploy script.
Schedule::command('nonox:record-version')->hourlyAt(7)->withoutOverlapping();
// Nightly integrity check of every company's ledger (AGENTS.md §6.4).
Schedule::command('ledger:verify --all')->dailyAt('03:15')->withoutOverlapping();
// Nightly check of every signing envelope's hash chain and files (ADR-046 amendment, gap 4).
Schedule::command('signing:verify')->dailyAt('03:25')->withoutOverlapping();
// Reminders to open signers (08:00–18:00 Stockholm time only) and the expiry of envelopes past their last day (ADR-046 amendment, gap 9).
Schedule::command('signing:remind')->hourlyAt(41)->withoutOverlapping();
// The next fiscal year, thirty days before the latest one ends (ADR-010), before agreements invoice into it.
Schedule::command('fiscal-years:create-next')->dailyAt('05:35')->withoutOverlapping();
Schedule::command('invoices:run-agreements')->dailyAt('06:00')->withoutOverlapping();
// Try without registering (ADR-077): demo companies and guests whose time has run out; the only deletion NONOX makes.
Schedule::command('demo:prune')->everyTenMinutes()->withoutOverlapping();
// Personal data without a remaining purpose (GDPR art. 5.1 e), see docs/gdpr/lagringstider.md.
Schedule::command('privacy:prune')->dailyAt('04:10')->withoutOverlapping();
// Unsigned signing links a year after they closed, in every company (ADR-046, ADR-075 §7).
Schedule::command('signing:prune')->dailyAt('04:15')->withoutOverlapping();
// Work directories the PDF engine left behind when PHP died mid-call (ADR-046 amendment, S5): older than an hour.
Schedule::command('signing:prune-engine-work')->hourlyAt(17)->withoutOverlapping();
// The customer portal's invitations, inactive and disabled contacts, events and closed questions (ADR-075).
Schedule::command('portal:prune')->dailyAt('04:20')->withoutOverlapping();
Schedule::command('connect:prune')->dailyAt('04:25')->withoutOverlapping();
// Planering's schedule proposals (ADR-037 amendment, gap 3): deleted 90 days after they were made.
Schedule::command('planning:prune-proposals')->dailyAt('04:35')->withoutOverlapping();
// Shipments (ADR-081 E14): recipient data emptied after seven calendar years, cancelled ones deleted after 90 days.
Schedule::command('inventory:prune-shipments')->dailyAt('04:38')->withoutOverlapping();
// Source files of register imports (ADR-076): 30 days after a finished import, 7 after an unconfirmed preview.
Schedule::command('register-import:prune')->dailyAt('04:40')->withoutOverlapping();
// The encrypted chat (ADR-045): expired chats in a file store, which does not expire them by itself.
Schedule::command('chat:prune')->everyFiveMinutes()->withoutOverlapping();
// NONOX API webhooks (ADR-032): retries whose wait is over.
Schedule::command('connect:webhooks')->everyMinute()->withoutOverlapping();
// NONOX Inbox (ADR-023): IMAP mailboxes of self-hosted inboxes, the daily summary and retention.
Schedule::command('inbox:fetch')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('inbox:daily-summary')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('inbox:prune')->dailyAt('04:25')->withoutOverlapping();
// NONOX Planering (ADR-037): shift tasks and tick-offs are removed a year after the shift.
Schedule::command('planning:prune-tasks')->dailyAt('04:30')->withoutOverlapping();
// Schedule messages and their read receipts are removed a year after the last day they concern.
Schedule::command('planning:prune-messages')->dailyAt('04:40')->withoutOverlapping();
// Peppol e-faktura (ADR-050): received e-invoices and delivery status, beside the access point's webhook.
Schedule::command('peppol:sync')->everyFifteenMinutes()->withoutOverlapping();
// Tasks (ADR-028): this period's recurring tasks, and the morning digest for those who asked for it.
Schedule::command('tasks:generate-recurring')->dailyAt('05:10')->withoutOverlapping();
Schedule::command('tasks:send-digest')->weekdays()->at('06:45')->withoutOverlapping();
// Mina datum (ADR-051, stage 2): notices 7 days and 1 day before a company's deadlines.
Schedule::command('guide:send-deadline-reminders')->dailyAt('06:50')->withoutOverlapping();
// The waiting list of the open beta (ADR-038): retention, and one summary a day for the HQ admins.
Schedule::command('waitlist:prune')->dailyAt('04:40')->withoutOverlapping();
Schedule::command('waitlist:digest')->dailyAt('07:15')->withoutOverlapping();
// The two follow-up mails after approval (ADR-038 addendum), at a time when people read mail; at most once each, never after 14 days.
Schedule::command('waitlist:send-onboarding-reminders')->dailyAt('09:20')->withoutOverlapping();
// CRM (ADR-043): prospect, active and inactive from the ledger, once a day.
Schedule::command('crm:refresh-statuses')->dailyAt('05:25')->withoutOverlapping();
// CRM stage 2: "Vinn tillbaka" tasks for lost deals and accounts whose date has come, after the status rules.
Schedule::command('crm:win-back')->dailyAt('05:40')->withoutOverlapping();
// CRM stage 4 (ADR-047): the company register from SCB's and Bolagsverket's weekly open-data files (and SCB's
// API when a key is set), only where the operator has turned it on; then the "Ändring hos kunden" tasks every day.
if (config('services.company_register.import')) {
    // One after the other: the three share a lock, so a second one started while the first runs would fail.
    Schedule::command('registry:import scb-bulk')->weeklyOn(1, '03:30')->withoutOverlapping(360)
        ->then(function (): void {
            Artisan::call('registry:import', ['source' => 'bolagsverket-bulk']);

            if (config('services.company_register.scb_api_key')) {
                Artisan::call('registry:import', ['source' => 'scb-api']);
            }
        });
}
Schedule::command('crm:registry-watch')->dailyAt('06:10')->withoutOverlapping();
// CRM stage 6 (ADR-049): connected Google and Microsoft calendars; does nothing until a provider is configured.
Schedule::command('crm:calendar-sync')->everyFifteenMinutes()->withoutOverlapping();
// CRM stage 3 (ADR-046): renew agreements, remind before notice deadlines, prune unsigned signing links.
Schedule::command('crm:agreements')->dailyAt('05:50')->withoutOverlapping();
// Price agreements (ADR-082 P5): end those whose last day has passed; linked ones follow the CRM end date renewed above.
Schedule::command('pricing:expire')->dailyAt('06:05')->withoutOverlapping();
// CRM stage 5 (ADR-048): queued mailings within the hourly and daily caps; unlinked and orphaned mail pruned nightly.
Schedule::command('crm:send-mailings')->everyMinute()->withoutOverlapping();
Schedule::command('crm:prune-mail')->dailyAt('04:50')->withoutOverlapping();
