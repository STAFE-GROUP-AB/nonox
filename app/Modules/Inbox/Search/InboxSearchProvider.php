<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Search;

use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Models\InboxMessage;
use App\Support\Search\DatabaseSearchProvider;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;

/**
 * Inkorg: messages by sender name, sender address and subject, and the
 * day they arrived. The mail text is encrypted and never searched.
 */
final class InboxSearchProvider extends DatabaseSearchProvider
{
    public const string KEY = 'inbox';

    private const array COLUMNS = ['inbox_messages.from_name', 'inbox_messages.from_email', 'inbox_messages.subject'];

    private const array WAITING = [MessageStatus::Quarantine, MessageStatus::NeedsReview, MessageStatus::DraftReady];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Inkorg');
    }

    public function module(): string
    {
        return InboxModule::KEY;
    }

    public function order(): int
    {
        return 25;
    }

    public function authorize(SearchContext $context): bool
    {
        return $context->allows('viewAny', InboxMessage::class);
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup
    {
        if ($query->number !== null || $query->impliedAmount !== null || ! $this->wants($query, dates: true)) {
            return null;
        }

        $builder = InboxMessage::query()->where('inbox_messages.company_id', $context->company->id)->select('inbox_messages.*');

        $this->whereText($builder, self::COLUMNS, $query);
        $this->selectRank($builder, self::COLUMNS, $query);

        if ($query->hasDate()) {
            $builder->whereBetween('inbox_messages.received_at', [$query->dateFrom?->startOfDay(), $query->dateTo?->endOfDay()]);
        }

        [$messages, $total] = $this->fetch($builder, 'inbox_messages.received_at', $limit);

        return $this->group(
            $messages->map(fn (InboxMessage $message): SearchResult => $this->result($message)->withScore(self::rank($message)))->all(),
            $total,
            route('inbox.index'),
        );
    }

    public function find(string $id, SearchContext $context): ?SearchResult
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            return null;
        }

        $message = InboxMessage::query()->find($id);

        return $message instanceof InboxMessage ? $this->result($message) : null;
    }

    public function todo(SearchContext $context): array
    {
        $waiting = InboxMessage::query()->whereIn('status', array_map(fn (MessageStatus $status): string => $status->value, self::WAITING))->count();

        if ($waiting === 0) {
            return [];
        }

        return [new SearchResult(
            type: 'todo',
            id: 'inbox-waiting',
            title: trans_choice(':count underlag i inkorgen att granska|:count underlag i inkorgen att granska', $waiting, ['count' => $waiting]),
            url: route('inbox.index'),
            icon: 'inbox',
            remember: false,
        )];
    }

    private function result(InboxMessage $message): SearchResult
    {
        $tab = 'att-granska';

        foreach (MessageStatus::tabs() as $key => $statuses) {
            if (in_array($message->status, $statuses, true)) {
                $tab = $key;
            }
        }

        return new SearchResult(
            type: self::KEY,
            id: (string) $message->id,
            title: $message->subject !== null && $message->subject !== '' ? $message->subject : __('(inget ämne)'),
            url: route('inbox.index', ['flik' => $tab, 'id' => $message->id]),
            subtitle: self::join($message->senderLabel(), $message->received_at->toDateString()),
            badge: $message->status->label(),
            icon: 'inbox',
        );
    }
}
