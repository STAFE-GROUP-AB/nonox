<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Domain\Documents\LegalHoldReason;
use App\Models\Document;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sets or lifts the legal hold (bevarandespärr) on a document, for example
 * during a tax audit or a dispute. A document on hold cannot be deleted,
 * whatever its retention class; the database refuses the delete while the
 * hold is set, so it must be lifted first. The reason is a key
 * (LegalHoldReason), never free text. Needs documents:hold.
 */
class SetLegalHold
{
    public function __invoke(User $user, Document $document, bool $hold, ?LegalHoldReason $reason = null): Document
    {
        Gate::forUser($user)->authorize('hold', $document);

        if ($hold && $reason === null) {
            throw ValidationException::withMessages(['hold_reason' => __('Välj varför dokumentet ska ha bevarandespärr.')]);
        }

        return CurrentCompany::run($document->company_id, fn (): Document => DB::transaction(function () use ($user, $document, $hold, $reason): Document {
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($locked->legal_hold === $hold) {
                return $locked;
            }

            $locked->forceFill([
                'legal_hold' => $hold,
                'legal_hold_at' => $hold ? CarbonImmutable::now() : null,
                'legal_hold_by' => $hold ? $user->id : null,
                'legal_hold_reason' => $hold ? $reason : null,
            ])->save();

            Audit::record($hold ? 'document.legal_hold_set' : 'document.legal_hold_lifted', $locked, [
                'retention_class' => $locked->retention_class->value,
                'reason' => $reason?->value,
            ], $locked->company_id, $user);

            return $locked;
        }));
    }
}
