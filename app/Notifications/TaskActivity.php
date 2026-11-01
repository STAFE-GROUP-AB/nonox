<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Company;
use App\Models\Task;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * An in-app notice about a task (ADR-028): assigned to you, commented on,
 * completed. Stored in the database only; e-mail is the daily digest. The
 * notice keeps the company's id so that opening it goes through the company
 * switch, which refuses a company the user no longer belongs to.
 */
class TaskActivity extends Notification
{
    public const string ASSIGNED = 'assigned';

    public const string COMMENTED = 'commented';

    public const string COMPLETED = 'completed';

    public function __construct(
        public readonly string $kind,
        public readonly int $companyId,
        public readonly string $companyName,
        public readonly int $taskId,
        public readonly string $title,
        public readonly string $actor,
    ) {}

    public static function about(string $kind, Task $task, Company $company, User $actor): self
    {
        return new self($kind, $company->id, (string) $company->name, $task->id, $task->title, (string) $actor->name);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'company_id' => $this->companyId,
            'company_name' => $this->companyName,
            'task_id' => $this->taskId,
            'title' => $this->title,
            'actor' => $this->actor,
        ];
    }

    /**
     * The sentence shown in the list of notices.
     *
     * @param  array<string, mixed>  $data
     */
    public static function sentence(array $data): string
    {
        $replace = ['actor' => (string) ($data['actor'] ?? ''), 'title' => (string) ($data['title'] ?? '')];

        return match ($data['kind'] ?? null) {
            self::ASSIGNED => __(':actor gav dig uppgiften ”:title”.', $replace),
            self::COMMENTED => __(':actor kommenterade ”:title”.', $replace),
            self::COMPLETED => __(':actor markerade ”:title” som klar.', $replace),
            default => (string) ($data['title'] ?? ''),
        };
    }
}
