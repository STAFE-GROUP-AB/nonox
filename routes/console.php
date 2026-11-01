<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Nightly integrity check of every company's ledger (AGENTS.md §6.4).
Schedule::command('ledger:verify --all')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('invoices:run-agreements')->dailyAt('06:00')->withoutOverlapping();
// Personal data without a remaining purpose (GDPR art. 5.1 e), see docs/gdpr/lagringstider.md.
Schedule::command('privacy:prune')->dailyAt('04:10')->withoutOverlapping();
Schedule::command('connect:prune')->dailyAt('04:25')->withoutOverlapping();
// NONOX Inbox (ADR-023): IMAP mailboxes of self-hosted inboxes, the daily summary and retention.
Schedule::command('inbox:fetch')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('inbox:daily-summary')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('inbox:prune')->dailyAt('04:25')->withoutOverlapping();
// Tasks (ADR-028): this period's recurring tasks, and the morning digest for those who asked for it.
Schedule::command('tasks:generate-recurring')->dailyAt('05:10')->withoutOverlapping();
Schedule::command('tasks:send-digest')->weekdays()->at('06:45')->withoutOverlapping();
