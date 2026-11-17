<?php

declare(strict_types=1);

namespace App\Modules\Planning\Console;

use App\Modules\Planning\Models\Message;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Removes schedule messages, with their recipients and read receipts, 12
 * months after the last day the message concerns (ADR-037 gap 5, Lead
 * decision 2026-11-17, docs/gdpr/lagringstider.md). The period is a
 * proposed default and the owner's decision.
 *
 * TODO(legal-verify): the period; whether read and acknowledgement receipts
 * need a shorter one than the message itself.
 */
class PruneMessagesCommand extends Command
{
    public const int KEEP_MONTHS = 12;

    protected $signature = 'planning:prune-messages';

    protected $description = 'Remove schedule messages and their receipts a year after the last day they concern';

    public function handle(): int
    {
        $cutoff = CarbonImmutable::today('Europe/Stockholm')->subMonths(self::KEEP_MONTHS)->toDateString();

        // Retention runs across every company: one delete (receipts follow by cascade), no company's data is read.
        $removed = CompanyScope::bypass(fn (): int => Message::query()->where('date_to', '<', $cutoff)->delete());

        $this->comment("Removed {$removed} schedule messages older than ".self::KEEP_MONTHS.' months.');

        return self::SUCCESS;
    }
}
