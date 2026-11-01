<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Actions\Accounting\CorrectVoucher;
use App\Actions\Accounting\DeleteDraftVoucher;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Models\Voucher;
use App\Models\VoucherSeries;
use App\Modules\Connect\Http\Resources\ApiPresenter;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Connect\Support\ConnectAccess;
use App\Modules\Connect\Support\ConnectCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Vouchers over the API (ADR-032), through the same Actions as the UI:
 * drafts can be created, changed and deleted; a draft is posted with
 * PostVoucher (gapless number, hash chain, audit log); a posted voucher is
 * never changed, only corrected with CorrectVoucher.
 */
final class VoucherController
{
    private const array RELATIONS = ['rows.account', 'rows.costCenter', 'rows.project', 'series', 'attachments'];

    public function index(Request $request): JsonResponse
    {
        $filters = Validator::make($request->query(), [
            'status' => ['nullable', 'in:DRAFT,POSTED'],
            'series' => ['nullable', 'string', 'max:3'],
            'fiscal_year_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();

        $query = Voucher::query()->with(self::RELATIONS);

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['series'] ?? null)) {
            $query->whereIn('series_id', VoucherSeries::query()->where('code', strtoupper((string) $filters['series']))->select('id'));
        }

        if (filled($filters['fiscal_year_id'] ?? null)) {
            $query->where('fiscal_year_id', (int) $filters['fiscal_year_id']);
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->where('voucher_date', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->where('voucher_date', '<=', $filters['date_to']);
        }

        return CursorList::respond($request, $query, fn (Voucher $voucher): array => ApiPresenter::voucher($voucher));
    }

    public function show(Voucher $voucher): JsonResponse
    {
        Gate::forUser($this->credential()->user)->authorize('view', $voucher);

        return CursorList::one(ApiPresenter::voucher($voucher->load(self::RELATIONS)));
    }

    public function store(Request $request, SaveDraftVoucher $save): JsonResponse
    {
        $credential = $this->credential();
        $voucher = $save($credential->user, $credential->company, $this->voucherInput($request));

        return CursorList::one(ApiPresenter::voucher($voucher->fresh(self::RELATIONS) ?? $voucher), 201)
            ->header('Location', route('connect.api.vouchers.show', $voucher));
    }

    public function update(Request $request, Voucher $voucher, SaveDraftVoucher $save): JsonResponse
    {
        $credential = $this->credential();
        $current = ApiPresenter::voucher($voucher->load(self::RELATIONS));
        $input = [...['voucher_date' => $current['voucher_date'], 'description' => $current['description'], 'series' => $current['series'], 'rows' => $current['rows']], ...$this->voucherInput($request)];

        $saved = $save($credential->user, $credential->company, $input, $voucher);

        return CursorList::one(ApiPresenter::voucher($saved->fresh(self::RELATIONS) ?? $saved));
    }

    public function destroy(Voucher $voucher, DeleteDraftVoucher $delete): Response
    {
        $delete($this->credential()->user, $voucher);

        return response()->noContent();
    }

    public function post(Voucher $voucher, PostVoucher $post): JsonResponse
    {
        $posted = $post($this->credential()->user, $voucher);

        return CursorList::one(ApiPresenter::voucher($posted->fresh(self::RELATIONS) ?? $posted));
    }

    public function correct(Request $request, Voucher $voucher, CorrectVoucher $correct): JsonResponse
    {
        $input = Validator::make($request->json()->all(), [
            'reason' => ['required', 'string', 'max:255'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'rows' => ['nullable', 'array'],
            'rows.*' => ['array'],
        ], [], ['reason' => __('anledning')])->validate();

        $correction = $correct(
            $this->credential()->user,
            $voucher,
            (string) $input['reason'],
            isset($input['date']) ? (string) $input['date'] : null,
            array_values(array_map(fn (array $row): array => self::row($row), (array) ($input['rows'] ?? []))),
        );

        return CursorList::one(ApiPresenter::voucher($correction->fresh(self::RELATIONS) ?? $correction), 201)
            ->header('Location', route('connect.api.vouchers.show', $correction));
    }

    /** @return array<string, mixed> */
    private function voucherInput(Request $request): array
    {
        $body = $request->json()->all();
        $input = array_intersect_key($body, array_flip(['voucher_date', 'description', 'series']));

        if (array_key_exists('rows', $body)) {
            $input['rows'] = is_array($body['rows']) ? array_values(array_map(fn (mixed $row): array => is_array($row) ? self::row($row) : [], $body['rows'])) : $body['rows'];
        }

        return $input;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        return array_intersect_key($row, array_flip(['account', 'debit_ore', 'credit_ore', 'description', 'cost_center', 'project']));
    }

    private function credential(): ConnectCredential
    {
        return ConnectAccess::current() ?? abort(401);
    }
}
