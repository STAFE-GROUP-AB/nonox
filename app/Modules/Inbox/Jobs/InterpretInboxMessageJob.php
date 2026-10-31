<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Jobs;

use App\Modules\Inbox\Actions\InterpretInboxMessage;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Reads a released message in the background. Carries the company id and
 * sets the company itself (AGENTS.md §6.0); stops quietly when the module
 * has been turned off since.
 */
class InterpretInboxMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly int $companyId, public readonly string $messageId) {}

    public function handle(InterpretInboxMessage $interpret, ModuleManager $modules): void
    {
        if (! $modules->isEnabled(InboxModule::KEY, $this->companyId)) {
            return;
        }

        CurrentCompany::run($this->companyId, function () use ($interpret): void {
            $message = InboxMessage::query()->find($this->messageId);

            if ($message instanceof InboxMessage) {
                $interpret($message);
            }
        });
    }
}
