<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\StaffLedger\Domain\CheckInMethod;
use App\Modules\StaffLedger\Domain\CorrectionKind;
use App\Modules\StaffLedger\Domain\EntryType;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Corrects an entry with a new entry that refers to it, with a reason, who
 * corrected and when. The original is never changed and is always shown
 * next to the correction. A correction cannot itself be corrected, and a
 * voided entry cannot be voided again.
 */
final class CorrectEntry
{
    public function __construct(private readonly AppendEntry $append) {}

    public function __invoke(User $user, Company $company, Entry $original, CorrectionKind $kind, ?CarbonImmutable $at, string $reason): Entry
    {
        Gate::forUser($user)->authorize('correct', [Entry::class, $company]);

        if ($original->type === EntryType::Correction) {
            throw ValidationException::withMessages(['entry' => __('En rättelse kan inte rättas. Rätta den ursprungliga registreringen.')]);
        }

        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => __('Skriv varför registreringen rättas.')]);
        }

        if ($kind === CorrectionKind::Time && $at === null) {
            throw ValidationException::withMessages(['at' => __('Ange rätt tid.')]);
        }

        if ($at !== null && $at->isAfter(CarbonImmutable::now()->addMinutes(5))) {
            throw ValidationException::withMessages(['at' => __('Tiden kan inte ligga i framtiden.')]);
        }

        $voided = Entry::query()->where('corrects_entry_id', $original->id)->where('correction', CorrectionKind::Void->value)->exists();

        if ($voided) {
            throw ValidationException::withMessages(['entry' => __('Registreringen är redan markerad som felaktig.')]);
        }

        $correction = ($this->append)(Location::query()->findOrFail($original->location_id), null, [
            'type' => EntryType::Correction,
            'correction' => $kind,
            'corrects_entry_id' => $original->id,
            'method' => CheckInMethod::Correction,
            'person_id' => $original->person_id,
            'person_name' => $original->person_name,
            'personal_number' => $original->personal_number,
            'employer_name' => $original->employer_name,
            'employer_org_number' => $original->employer_org_number,
            'occurred_at' => $kind === CorrectionKind::Time ? $at : CarbonImmutable::now(),
            'user_id' => $user->id,
            'reason' => trim($reason),
        ]);

        Audit::record('staff_ledger.entry_corrected', $correction, ['original' => $original->id, 'kind' => $kind->value, 'at' => $at?->toIso8601String(), 'reason' => trim($reason)], $company, $user);

        return $correction;
    }
}
