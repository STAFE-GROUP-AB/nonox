<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Modules\Connect\Http\Controllers\Api\V1\Concerns\ActsAsCredential;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Crm\Actions\LoseDeal;
use App\Modules\Crm\Actions\SaveDeal;
use App\Modules\Crm\Actions\SetWinBack;
use App\Modules\Crm\Actions\WinDeal;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\LossReason;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CRM stage 2 over the API (ADR-043, ADR-032): deals, read with crm:view
 * and created, changed, moved, won and lost with crm:manage through the
 * same Actions as the screens; pipelines, stages and loss reasons are read
 * only. A new deal needs its next_step, as on the screen. Both scopes are
 * false while the module is off; another company's record answers 404.
 */
final class CrmDealController
{
    use ActsAsCredential;

    private const array DEAL_FIELDS = ['account_id', 'person_id', 'pipeline_id', 'stage_id', 'title', 'value_ore', 'expected_close_date', 'owner_id', 'next_step'];

    public function deals(Request $request): JsonResponse
    {
        $query = Deal::query();

        foreach (['pipeline_id', 'stage_id', 'owner_id', 'account_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->integer($filter));
            }
        }

        if ($request->filled('status')) {
            $query->where('status', strtoupper((string) $request->query('status')));
        }

        return CursorList::respond($request, $query, fn (Deal $deal): array => self::presentDeal($deal));
    }

    public function deal(int $deal): JsonResponse
    {
        return CursorList::one(self::presentDeal(Deal::query()->findOrFail($deal)));
    }

    public function storeDeal(Request $request, SaveDeal $save): JsonResponse
    {
        $deal = $save($this->user(), $this->company(), array_intersect_key($request->json()->all(), array_flip(self::DEAL_FIELDS)));

        return CursorList::one(self::presentDeal($deal->refresh()), 201)
            ->header('Location', route('connect.api.crm.deals.show', $deal->id));
    }

    /**
     * Changes the given fields of an open deal (stage_id moves it); status
     * WON or LOST closes it, with the fields of WinDeal or LoseDeal; on a
     * lost deal, win_back_at alone changes or clears the win-back date.
     */
    public function updateDeal(Request $request, int $deal, SaveDeal $save, WinDeal $win, LoseDeal $lose, SetWinBack $winBack): JsonResponse
    {
        $model = Deal::query()->findOrFail($deal);
        $body = $request->json()->all();
        $changes = array_intersect_key($body, array_flip(array_diff(self::DEAL_FIELDS, ['account_id', 'next_step'])));
        $status = isset($body['status']) ? strtoupper((string) $body['status']) : null;

        $saved = DB::transaction(function () use ($model, $body, $changes, $status, $save, $win, $lose, $winBack): Deal {
            $deal = $model;

            if ($changes !== []) {
                $deal = $save($this->user(), $this->company(), $changes, $deal);
            }

            if ($status === DealStatus::Won->value) {
                return $win($this->user(), $this->company(), $deal, array_intersect_key($body, array_flip(['value_ore', 'quote_id', 'order_id', 'invoice_id', 'make_customer'])));
            }

            if ($status === DealStatus::Lost->value) {
                return $lose($this->user(), $this->company(), $deal, array_intersect_key($body, array_flip(['loss_reason_id', 'loss_note', 'win_back_at'])));
            }

            if ($status !== null && $status !== $deal->status->value) {
                throw ValidationException::withMessages(['status' => __('En affär kan bara markeras som vunnen (WON) eller förlorad (LOST).')]);
            }

            if (array_key_exists('win_back_at', $body)) {
                // SetWinBack changes the deal it is given.
                $winBack($this->user(), $this->company(), $deal, $body['win_back_at'] === null ? null : (string) $body['win_back_at']);
            }

            return $deal;
        });

        return CursorList::one(self::presentDeal($saved->refresh()));
    }

    public function pipelines(Request $request): JsonResponse
    {
        $query = Pipeline::query()->with('stages');

        if (! $request->boolean('archived')) {
            $query->whereNull('archived_at');
        }

        return CursorList::respond($request, $query, fn (Pipeline $pipeline): array => self::presentPipeline($pipeline));
    }

    public function pipeline(int $pipeline): JsonResponse
    {
        return CursorList::one(self::presentPipeline(Pipeline::query()->with('stages')->findOrFail($pipeline)));
    }

    public function stages(Request $request): JsonResponse
    {
        $query = PipelineStage::query();

        if ($request->filled('pipeline_id')) {
            $query->where('pipeline_id', $request->integer('pipeline_id'));
        }

        return CursorList::respond($request, $query, fn (PipelineStage $stage): array => self::presentStage($stage));
    }

    public function lossReasons(Request $request): JsonResponse
    {
        $query = LossReason::query();

        if (! $request->boolean('archived')) {
            $query->whereNull('archived_at');
        }

        return CursorList::respond($request, $query, fn (LossReason $reason): array => [
            'id' => $reason->id,
            'name' => $reason->name,
            'archived' => $reason->archived_at !== null,
            'created_at' => self::time($reason->created_at),
            'updated_at' => self::time($reason->updated_at),
        ]);
    }

    /** @return array<string, mixed> */
    public static function presentDeal(Deal $deal): array
    {
        return [
            'id' => $deal->id,
            'account_id' => $deal->account_id,
            'person_id' => $deal->person_id,
            'pipeline_id' => $deal->pipeline_id,
            'stage_id' => $deal->stage_id,
            'title' => $deal->title,
            'value_ore' => $deal->value_ore,
            'expected_close_date' => $deal->expected_close_date?->toDateString(),
            'owner_id' => $deal->owner_id,
            'status' => $deal->status->value,
            'won_at' => self::time($deal->won_at),
            'lost_at' => self::time($deal->lost_at),
            'loss_reason_id' => $deal->loss_reason_id,
            'loss_note' => $deal->loss_note,
            'quote_id' => $deal->quote_id,
            'order_id' => $deal->order_id,
            'invoice_id' => $deal->invoice_id,
            'win_back_at' => $deal->win_back_at?->toDateString(),
            'created_by' => $deal->created_by,
            'created_at' => self::time($deal->created_at),
            'updated_at' => self::time($deal->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function presentPipeline(Pipeline $pipeline): array
    {
        return [
            'id' => $pipeline->id,
            'name' => $pipeline->name,
            'is_default' => $pipeline->is_default,
            'archived' => $pipeline->isArchived(),
            'stages' => $pipeline->stages->map(fn (PipelineStage $stage): array => self::presentStage($stage))->values()->all(),
            'created_at' => self::time($pipeline->created_at),
            'updated_at' => self::time($pipeline->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function presentStage(PipelineStage $stage): array
    {
        return [
            'id' => $stage->id,
            'pipeline_id' => $stage->pipeline_id,
            'name' => $stage->name,
            'sort' => $stage->sort,
            'probability' => $stage->probability,
            'created_at' => self::time($stage->created_at),
            'updated_at' => self::time($stage->updated_at),
        ];
    }

    private static function time(?DateTimeInterface $time): ?string
    {
        return $time?->format(DATE_ATOM);
    }
}
