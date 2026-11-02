<?php

declare(strict_types=1);

namespace App\Modules\Pos\Jobs;

use App\Modules\ModuleManager;
use App\Modules\Pos\Actions\RecordPosSale;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosWebhookMessage;
use App\Modules\Pos\PosModule;
use App\Modules\Pos\Support\PosDrivers;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\RunsForCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Handles a stored webhook message from a till (ADR-042): a new purchase is
 * read and recorded once. A message that cannot be read is marked with the
 * error and the nightly fetch picks the sale up.
 */
class ProcessPosWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsForCompany;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(public int $companyId, public int $messageId) {}

    public function handle(RecordPosSale $record, ModuleManager $modules): void
    {
        CurrentCompany::set($this->companyId);

        if (! $modules->isEnabled(PosModule::KEY, $this->companyId)) {
            return;
        }

        $message = PosWebhookMessage::query()->find($this->messageId);

        if (! $message instanceof PosWebhookMessage || $message->processed_at !== null) {
            return;
        }

        $connection = PosConnection::query()->findOrFail($message->pos_connection_id);
        $envelope = json_decode($message->body, true);
        $payload = is_array($envelope) && is_string($envelope['payload'] ?? null) ? json_decode($envelope['payload'], true) : null;
        $sale = is_array($payload) ? PosDrivers::for($connection->provider)->parseSale($payload) : null;

        if ($sale === null) {
            $message->forceFill(['processed_at' => now(), 'last_error' => 'not a sale'])->save();
            $connection->log('warning', __('Ett meddelande från kassan gick inte att läsa. Försäljningen hämtas vid nästa avstämning.'));

            return;
        }

        $record($connection, $sale, PosSale::WEBHOOK);
        $message->forceFill(['processed_at' => now(), 'last_error' => null])->save();
    }
}
