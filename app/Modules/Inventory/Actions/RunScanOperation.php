<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\PickListLine;
use App\Modules\Inventory\Models\ScanOperation;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountLine;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockReceiptLine;
use App\Modules\Inventory\Scanner\ScannerData;
use App\Modules\Inventory\Scanner\ScannerExtensions;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Runs one operation from the scanning app (ADR-040) exactly once. The
 * phone makes a UUID per operation; the first arrival runs it through the
 * existing Lager actions (receive, pick, move, count) and keeps the answer,
 * a resend gets that answer back and posts nothing. Ids come from the phone
 * and are looked up within the current company only: another company's id
 * is "not found", never an error that tells it exists.
 *
 * Kinds and payloads (quantities as decimal text, "2" or "1.5"):
 * - receive: receipt_id (null = a new receipt), lines [{article_id, location_id, quantity}]
 * - pick: pick_list_id, lines [{line_id, picked, reason}]
 * - move: article_id, from_location_id, to_location_id, quantity
 * - count: stock_count_id, lines [{article_id, location_id, counted, reason}], complete
 * - count_start: location_id (null = everything)
 *
 * Kinds registered by another module (ScannerExtension, such as Kök's
 * waste) run through that module's own action, under its permission.
 */
class RunScanOperation
{
    use GuardsInventory;

    public const array KINDS = ['receive', 'pick', 'move', 'count', 'count_start'];

    public function __construct(
        private readonly SaveReceipt $saveReceipt,
        private readonly ReceiveStock $receiveStock,
        private readonly PickLists $pickLists,
        private readonly RecordMovement $recordMovement,
        private readonly StockCounts $stockCounts,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{uuid: string, status: string, message: ?string, data: array<string, mixed>|null}
     */
    public function __invoke(User $user, Company $company, string $uuid, string $kind, array $payload): array
    {
        $extension = app(ScannerExtensions::class)->forKind($kind);

        if ($extension === null) {
            $this->guard($user, $company, 'count');
        } else {
            app(ModuleManager::class)->ensureEnabled(InventoryModule::KEY, $company);

            if (! $extension->allows($user, $company)) {
                throw new AuthorizationException;
            }
        }

        if (! Str::isUuid($uuid) || ($extension === null && ! in_array($kind, self::KINDS, true))) {
            return self::answer($uuid, ScanOperation::REJECTED, __('Operationen går inte att läsa.'));
        }

        $uuid = strtolower($uuid);

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company, $uuid, $kind, $payload, $extension): array {
            // A second request with the same UUID waits here until the first has committed, then sees its row.
            $inserted = ScanOperation::query()->insertOrIgnore([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'uuid' => $uuid,
                'kind' => $kind,
                'status' => ScanOperation::PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $operation = ScanOperation::query()->where('uuid', $uuid)->firstOrFail();

            if ($inserted === 0) {
                if ($operation->user_id !== $user->id || $operation->kind !== $kind) {
                    return self::answer($uuid, ScanOperation::REJECTED, __('Operationen har redan skickats från en annan enhet.'));
                }

                /** @var array{uuid: string, status: string, message: ?string, data: array<string, mixed>|null} */
                return [...($operation->result ?? self::answer($uuid, $operation->status, null)), 'replayed' => true];
            }

            try {
                $data = DB::transaction(fn (): array => $extension === null ? $this->run($user, $company, $kind, $payload) : $extension->run($user, $company, $kind, $payload));
                $answer = self::answer($uuid, ScanOperation::OK, null, $data);
            } catch (ValidationException $exception) {
                $answer = self::answer($uuid, ScanOperation::REJECTED, (string) collect($exception->errors())->flatten()->first());
            } catch (ModelNotFoundException) {
                $answer = self::answer($uuid, ScanOperation::NOT_FOUND, __('Det finns inte längre, eller hör till ett annat företag.'));
            }

            $operation->update(['status' => $answer['status'], 'result' => $answer]);

            return $answer;
        }));
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array{uuid: string, status: string, message: ?string, data: array<string, mixed>|null}
     */
    private static function answer(string $uuid, string $status, ?string $message, ?array $data = null): array
    {
        return ['uuid' => $uuid, 'status' => $status, 'message' => $message, 'data' => $data];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function run(User $user, Company $company, string $kind, array $payload): array
    {
        $today = CarbonImmutable::today();

        return match ($kind) {
            'receive' => $this->receive($user, $company, $payload, $today),
            'pick' => $this->pick($user, $company, $payload, $today),
            'move' => $this->move($user, $company, $payload, $today),
            'count' => $this->count($user, $company, $payload),
            default => $this->startCount($user, $company, $payload, $today),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function receive(User $user, Company $company, array $payload, CarbonImmutable $date): array
    {
        $rows = self::rows($payload);

        if ($rows === []) {
            throw ValidationException::withMessages(['lines' => __('Skanna minst en artikel.')]);
        }

        if (($payload['receipt_id'] ?? null) === null) {
            $receipt = ($this->saveReceipt)($user, $company, [
                'reference' => __('Mottaget med mobilen'),
                'lines' => array_map(fn (array $row): array => [
                    'article_id' => (int) ($row['article_id'] ?? 0),
                    'location_id' => ($row['location_id'] ?? null) === null ? null : (int) $row['location_id'],
                    'quantity' => (string) ($row['quantity'] ?? ''),
                ], $rows),
            ]);
            $receipt = $this->receiveStock->all($user, $company, $receipt, $date);

            return ['receipt_id' => $receipt->id, 'number' => $receipt->number, 'status' => $receipt->status];
        }

        $receipt = StockReceipt::query()->findOrFail((int) $payload['receipt_id']);
        $lines = $receipt->lines()->orderBy('position')->orderBy('id')->get();

        // One call per round: the receive action takes one location per line, so a line received into two places takes two rounds.
        $rounds = [];

        foreach ($rows as $index => $row) {
            $line = $lines->first(fn (StockReceiptLine $line): bool => $line->article_id === (int) ($row['article_id'] ?? 0))
                ?? throw ValidationException::withMessages(["lines.{$index}.article_id" => __('Artikeln finns inte på inleverans :number.', ['number' => $receipt->number])]);
            $round = 0;

            while (isset($rounds[$round][$line->id]) && ($rounds[$round][$line->id]['location_id'] ?? null) !== ($row['location_id'] ?? null)) {
                $round++;
            }

            $quantity = self::quantity($row['quantity'] ?? null, "lines.{$index}.quantity");
            $previous = isset($rounds[$round][$line->id]) ? Quantity::parse((string) $rounds[$round][$line->id]['quantity']) : 0;
            $rounds[$round][$line->id] = ['quantity' => Quantity::decimal($previous + $quantity), 'location_id' => $row['location_id'] ?? null];
        }

        foreach ($rounds as $receive) {
            $receipt = ($this->receiveStock)($user, $company, $receipt, $receive, $date);
        }

        return ['receipt_id' => $receipt->id, 'number' => $receipt->number, 'status' => $receipt->status];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function pick(User $user, Company $company, array $payload, CarbonImmutable $date): array
    {
        $list = PickList::query()->findOrFail((int) ($payload['pick_list_id'] ?? 0));
        $lines = $list->lines()->get()->keyBy('id');

        foreach (self::rows($payload) as $index => $row) {
            $line = $lines->get((int) ($row['line_id'] ?? 0)) ?? throw ValidationException::withMessages(["lines.{$index}" => __('Raden hör inte till plocklistan.')]);
            /** @var PickListLine $line */
            $picked = self::quantity($row['picked'] ?? null, "lines.{$index}.picked", allowZero: true);
            $reason = is_string($row['reason'] ?? null) ? $row['reason'] : null;

            if ($picked < $line->quantity_milli && ($reason === null || $reason === '')) {
                throw ValidationException::withMessages(["lines.{$index}.reason" => __('Ange varför det fattas på en rad.')]);
            }

            $this->pickLists->pick($user, $company, $line, Quantity::decimal($picked), $reason);
        }

        $list = $this->pickLists->complete($user, $company, $list, $date);

        return ['pick_list_id' => $list->id, 'number' => $list->number, 'status' => $list->status];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function move(User $user, Company $company, array $payload, CarbonImmutable $date): array
    {
        $movements = ($this->recordMovement)($user, $company, [
            'kind' => 'transfer',
            'article_id' => $payload['article_id'] ?? null,
            'location_id' => $payload['from_location_id'] ?? null,
            'to_location_id' => $payload['to_location_id'] ?? null,
            'quantity' => is_scalar($payload['quantity'] ?? null) ? (string) $payload['quantity'] : '',
            'date' => $date->toDateString(),
            'note' => __('Flyttat med mobilen'),
        ]);

        return ['movements' => count($movements)];
    }

    /**
     * Saves what was counted (absolute quantities, so a resend changes
     * nothing) and completes the stocktake when asked.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function count(User $user, Company $company, array $payload): array
    {
        $count = StockCount::query()->findOrFail((int) ($payload['stock_count_id'] ?? 0));

        foreach (self::rows($payload) as $index => $row) {
            $articleId = (int) ($row['article_id'] ?? 0);
            $locationId = (int) ($row['location_id'] ?? 0);
            $line = StockCountLine::query()->where('stock_count_id', $count->id)->where('article_id', $articleId)->where('location_id', $locationId)->first()
                ?? $this->stockCounts->addLine($user, $company, $count, $articleId, $locationId);
            $counted = self::quantity($row['counted'] ?? null, "lines.{$index}.counted", allowZero: true);
            $reason = is_string($row['reason'] ?? null) && $row['reason'] !== '' ? $row['reason'] : $line->reason;

            $this->stockCounts->record($user, $company, $line, Quantity::decimal($counted), $reason);
        }

        if (($payload['complete'] ?? false) === true) {
            $count = $this->stockCounts->complete($user, $company, $count);
        }

        $count->load(['location:id,full_code', 'lines']);

        return ['stock_count_id' => $count->id, 'number' => $count->number, 'status' => $count->status, 'count' => ScannerData::count($count)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function startCount(User $user, Company $company, array $payload, CarbonImmutable $date): array
    {
        $locationId = ($payload['location_id'] ?? null) === null ? null : (int) $payload['location_id'];
        $count = $this->stockCounts->start($user, $company, $locationId, $date, __('Startad med mobilen'));
        $count->load(['location:id,full_code', 'lines']);

        return ['stock_count_id' => $count->id, 'number' => $count->number, 'status' => $count->status, 'count' => ScannerData::count($count)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private static function rows(array $payload): array
    {
        $lines = $payload['lines'] ?? [];

        if (! is_array($lines) || count($lines) > 500) {
            throw ValidationException::withMessages(['lines' => __('Operationen går inte att läsa.')]);
        }

        return array_values(array_filter($lines, 'is_array'));
    }

    private static function quantity(mixed $value, string $field, bool $allowZero = false): int
    {
        try {
            $milli = Quantity::parse(is_scalar($value) ? (string) $value : '');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Antalet går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
        }

        if ($milli < 0 || (! $allowZero && $milli === 0)) {
            throw ValidationException::withMessages([$field => __('Antalet måste vara större än noll.')]);
        }

        return $milli;
    }
}
