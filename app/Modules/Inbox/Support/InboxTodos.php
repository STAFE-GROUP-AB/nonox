<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

use App\Domain\Overview\Contracts\TodoProvider;
use App\Domain\Overview\TodoItem;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\Models\InboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The inbox's part of "Att göra" on Översikt: received documents to review
 * and proposals ready to book. One query.
 */
final class InboxTodos implements TodoProvider
{
    public function todos(User $user, Company $company, CarbonImmutable $today): array
    {
        if (! $user->hasTeamPermission($company, 'inbox:manage')) {
            return [];
        }

        $review = [MessageStatus::Quarantine->value, MessageStatus::NeedsReview->value];
        $counts = InboxMessage::query()
            ->whereIn('status', [...$review, MessageStatus::DraftReady->value])
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as aggregate'))
            ->toBase()
            ->pluck('aggregate', 'status');

        $toReview = (int) $counts->only($review)->sum();
        $ready = (int) ($counts[MessageStatus::DraftReady->value] ?? 0);
        $items = [];

        if ($toReview > 0) {
            $items[] = new TodoItem(
                'inbox-review',
                'inbox',
                $toReview === 1 ? __('1 underlag i inkorgen att granska') : __(':count underlag i inkorgen att granska', ['count' => $toReview]),
                __('Kontrollera avsändaren och vad dokumentet är.'),
                route('inbox.index', ['flik' => 'att-granska']),
                TodoItem::PRIORITY_INBOX,
            );
        }

        if ($ready > 0) {
            $items[] = new TodoItem(
                'inbox-ready',
                'inbox',
                $ready === 1 ? __('1 förslag i inkorgen klart att bokföra') : __(':count förslag i inkorgen klara att bokföra', ['count' => $ready]),
                __('Granska förslaget och bokför det med ett klick.'),
                route('inbox.index', ['flik' => 'utkast-klara']),
                TodoItem::PRIORITY_INBOX + 1,
            );
        }

        return $items;
    }

    public function invalidatedBy(): array
    {
        return [InboxMessage::class];
    }
}
