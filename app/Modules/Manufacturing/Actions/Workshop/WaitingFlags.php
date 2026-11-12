<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Workshop;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\Domain\WaitingKind;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Models\WorkshopWaitingFlag;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * An operation flagged at the workshop terminal as waiting for material or
 * for work, with a short reason (ADR-071 §7, §13.5). One open flag per
 * operation: raising a new one replaces the old. A flag is cleared at the
 * terminal, or by itself as soon as someone reports time on the operation.
 * The flag names the tablet, never the person who tapped it.
 *
 * What the planning calendar (M6) adds from the terminal (scrap, overrun
 * with a new remaining estimate, a stopped workstation) is not here: those
 * are production_deviations rows, which belong to M6.
 */
final class WaitingFlags
{
    /** What a reason is replaced by when it is erased or has been kept long enough. */
    public const string ERASED = '–';

    /** Months after which a reason is blanked by privacy:prune; kind and times stay. */
    public const int REASON_RETENTION_MONTHS = 12;

    /** Blanks the free text of a flag (it may have named a person); the kind and the times stay. */
    public function eraseReason(User $user, Company $company, WorkshopWaitingFlag $flag): void
    {
        // Also with the module off, like withdrawing a photo: the free text may name someone.
        Gate::forUser($user)->authorize('terminal', [WorkshopDevice::class, $company]);

        $flag->forceFill(['reason' => self::ERASED])->save();

        Audit::record('manufacturing.terminal_waiting_reason_erased', $flag, ['production_order_operation_id' => $flag->production_order_operation_id], $company, $user);
    }

    public function raise(WorkshopDevice $device, int $operationId, WaitingKind $kind, string $reason): WorkshopWaitingFlag
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw ValidationException::withMessages(['reason' => __('Skriv en kort orsak, högst 200 tecken. Skriv inga namn.')]);
        }

        return DB::transaction(function () use ($device, $operationId, $kind, $reason): WorkshopWaitingFlag {
            $operation = ProductionOrderOperation::query()->find($operationId);
            $order = $operation instanceof ProductionOrderOperation
                ? ProductionOrder::query()->whereKey($operation->production_order_id)->lockForUpdate()->first()
                : null;

            if (! $operation instanceof ProductionOrderOperation || ! $order instanceof ProductionOrder || ! $order->status->reserves()) {
                throw ValidationException::withMessages(['operation' => __('Ordern är inte öppen. Fråga arbetsledaren.')]);
            }

            $this->clearForOperation($operation->id, $device);

            $flag = WorkshopWaitingFlag::create([
                'production_order_id' => $order->id,
                'production_order_operation_id' => $operation->id,
                'kind' => $kind,
                'reason' => $reason,
                'raised_by_device_id' => $device->id,
                'raised_at' => CarbonImmutable::now(),
            ]);

            Audit::withoutRequest(fn () => Audit::record('manufacturing.terminal_waiting_raised', $order, [
                'production_order_operation_id' => $operation->id,
                'kind' => $kind->value,
                'device_id' => $device->id,
            ], $device->company_id));

            return $flag;
        });
    }

    /** Clears the operation's open flag; false when there was none. */
    public function clear(WorkshopDevice $device, int $operationId): bool
    {
        return DB::transaction(function () use ($device, $operationId): bool {
            $cleared = $this->clearForOperation($operationId, $device);

            if ($cleared > 0) {
                Audit::withoutRequest(fn () => Audit::record('manufacturing.terminal_waiting_cleared', null, [
                    'production_order_operation_id' => $operationId,
                    'device_id' => $device->id,
                ], $device->company_id));
            }

            return $cleared > 0;
        });
    }

    /** Used by the time report too: work has started, so the operation no longer waits. */
    public function clearForOperation(int $operationId, WorkshopDevice $device): int
    {
        return WorkshopWaitingFlag::query()->open()->where('production_order_operation_id', $operationId)
            ->update(['cleared_at' => CarbonImmutable::now(), 'cleared_by_device_id' => $device->id]);
    }
}
