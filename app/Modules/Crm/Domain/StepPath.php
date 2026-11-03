<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\PipelineStage;
use Carbon\CarbonImmutable;

/**
 * The steps the stepper on the account card and the person page shows
 * (ADR-043): an open deal's pipeline (done before its stage, current,
 * later after it, with each stage's probability), or an account's status
 * path Prospekt → Aktiv kund → Inaktiv → Förlorad with the date a step was
 * reached where it is known. One query for a deal's stages, none for the
 * status path.
 */
final class StepPath
{
    /** @return list<array{label: string, state: string, meta: ?string}> */
    public static function forDeal(Deal $deal): array
    {
        $stages = PipelineStage::query()->where('pipeline_id', $deal->pipeline_id)->orderBy('sort')->orderBy('id')->get(['id', 'name', 'probability'])->values();
        $index = $stages->search(fn (PipelineStage $stage): bool => $stage->id === $deal->stage_id);
        $steps = [];

        foreach ($stages as $i => $stage) {
            $steps[] = [
                'label' => $stage->name,
                'state' => $index === false ? 'todo' : ($i < $index ? 'done' : ($i === $index ? 'current' : 'todo')),
                'meta' => $stage->probability.' %',
            ];
        }

        return $steps;
    }

    /**
     * @param  ?string  $firstInvoice  the date of the customer's first issued invoice, when known
     * @return list<array{label: string, state: string, meta: ?string}>
     */
    public static function forAccount(?Account $account, ?string $firstInvoice = null): array
    {
        $order = [AccountStatus::Prospect, AccountStatus::Active, AccountStatus::Inactive, AccountStatus::Lost];
        $index = $account === null ? -1 : (int) array_search($account->status, $order, true);
        $date = fn (?CarbonImmutable $at): ?string => $at?->setTimezone('Europe/Stockholm')->translatedFormat('j M Y');
        $known = [];

        if ($account !== null) {
            // An account made for an existing customer when the CRM was turned on is younger than its first invoice: no prospect date then.
            $created = $account->created_at;
            $known[AccountStatus::Prospect->value] = $firstInvoice !== null && $created !== null && $created->toDateString() > $firstInvoice ? null : $date($created);
            $known[AccountStatus::Active->value] = $firstInvoice === null ? null : $date(CarbonImmutable::parse($firstInvoice));

            if ($account->status_set_manually_at !== null) {
                $known[$account->status->value] = $date($account->status_set_manually_at);
            }
        }

        $steps = [];

        foreach ($order as $i => $step) {
            $steps[] = [
                'label' => $step->label(),
                // A lost prospect skipped the steps between: they were not reached, so they are not ticked.
                'state' => match (true) {
                    $i === $index => 'current',
                    $i < $index && ($account->status !== AccountStatus::Lost || ($known[$step->value] ?? null) !== null) => 'done',
                    default => 'todo',
                },
                'meta' => $i <= $index ? ($known[$step->value] ?? null) : null,
            ];
        }

        return $steps;
    }
}
