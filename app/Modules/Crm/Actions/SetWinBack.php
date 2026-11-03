<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Domain\AccountStatus;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Domain\WinBackBlock;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Deal;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Changes or clears the win-back date of a lost deal or a lost account
 * (ADR-043 stage 2). A new date lets crm:win-back make a new "Vinn
 * tillbaka" task when it comes. A date is refused for someone who must not
 * be contacted (WinBackBlock); clearing one is always allowed.
 */
final class SetWinBack
{
    public function __invoke(User $user, Company $company, Deal|Account $record, ?string $date): Deal|Account
    {
        Gate::forUser($user)->authorize('update', $record);

        $lost = $record instanceof Deal ? $record->status === DealStatus::Lost : $record->status === AccountStatus::Lost;

        if (! $lost) {
            throw ValidationException::withMessages(['win_back_at' => __('Bara en förlorad affär eller kund kan få ett datum att vinna tillbaka den.')]);
        }

        $validated = Validator::make(['win_back_at' => $date], [
            'win_back_at' => ['nullable', 'date', 'after_or_equal:today', 'before:'.CarbonImmutable::now(LogActivity::ZONE)->addYears(3)->toDateString()],
        ], [
            'win_back_at.after_or_equal' => __('Datumet för att vinna tillbaka kan inte ha passerat.'),
            'win_back_at.before' => __('Datumet för att vinna tillbaka kan vara högst tre år fram.'),
        ], ['win_back_at' => __('datum')])->validate();
        $winBackAt = blank($validated['win_back_at']) ? null : CarbonImmutable::parse((string) $validated['win_back_at'])->toDateString();
        $block = $winBackAt === null ? null : CurrentCompany::run($company, fn (): ?WinBackBlock => WinBackBlock::for($record));

        if ($block !== null) {
            throw ValidationException::withMessages(['win_back_at' => $block->message()]);
        }

        return DB::transaction(fn (): Deal|Account => CurrentCompany::run($company, function () use ($user, $company, $record, $winBackAt): Deal|Account {
            $record->forceFill(['win_back_at' => $winBackAt, 'win_back_task_at' => null])->save();

            Audit::record('crm.win_back_set', $record, [$record instanceof Deal ? 'deal_id' : 'account_id' => $record->id, 'win_back_at' => $winBackAt], $company, $user);

            return $record;
        }));
    }
}
