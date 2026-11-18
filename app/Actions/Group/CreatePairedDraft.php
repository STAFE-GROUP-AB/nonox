<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Company\OrganisationNumber;
use App\Domain\Firm\Memberships;
use App\Domain\Group\GroupCounterparts;
use App\Domain\Group\IntercompanyKind;
use App\Domain\Group\IntercompanyKindRule;
use App\Domain\Group\IntercompanyMirror;
use App\Models\Company;
use App\Models\IntercompanyPair;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Policies\IntercompanyDeliveryPolicy;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\DimensionScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Skapa motsvarande utkast" (ADR-081 gap 1, E27): from a posted voucher in
 * company A for an intra-group transaction without an invoice (a loan,
 * interest, a group contribution, a dividend decided), a DRAFT voucher in
 * group company B with the mirrored rows. It is never posted here: B's own
 * people post it under B's rules (open fiscal year, unlocked period, gapless
 * numbering at posting, PostVoucher unchanged).
 *
 * - Only for a user with vouchers:create in both companies, neither limited
 *   to cost centres or projects, while A and B are active members of the
 *   same corporate group (GroupCounterparts).
 * - The draft is written inside CurrentCompany::run($b) through
 *   SaveDraftVoucher, which rebuilds every row from B's chart by account
 *   number and B's cost centres and projects by code, and refuses a row when
 *   B lacks them. No id of A (account, cost centre, project, voucher) is
 *   carried into B.
 * - Each company gets its own intercompany_pairs row naming the other side
 *   by organisation number and voucher reference as text, never by id. A's
 *   row has no reference yet (B's draft has no number until it is posted);
 *   a person enters it.
 */
class CreatePairedDraft
{
    public function __construct(private readonly SaveDraftVoucher $saveDraft) {}

    /**
     * The companies the user may make a paired draft in for this voucher:
     * active group companies of the voucher's company where the user holds
     * vouchers:create and is not limited, by name.
     *
     * @return Collection<int, Company>
     */
    public static function counterparts(User $user, Company $company): Collection
    {
        $memberships = Memberships::of($user, active: true);
        $shared = GroupCounterparts::of($company->id, CarbonImmutable::today());

        return $memberships->companies
            ->filter(fn (Company $other): bool => isset($shared[$other->id]) && $memberships->may($other->id, 'vouchers:create'))
            ->sortBy(fn (Company $other): string => mb_strtolower($other->name))
            ->values();
    }

    /**
     * The proposal shown before anything is made: debit and credit swapped,
     * accounts mirrored (IntercompanyMirror), texts and codes kept, the kind
     * suggested from the first group account among the rows.
     *
     * @return array{kind: ?IntercompanyKind, voucher_date: string, description: string, rows: list<array{account: string, debit_ore: int, credit_ore: int, description: string, cost_center: string, project: string, check: bool}>}
     */
    public function propose(User $user, Voucher $voucher): array
    {
        $this->authorizeSource($user, $voucher);
        $voucher->loadMissing(['rows.account', 'rows.costCenter', 'rows.project']);
        $kind = null;

        foreach ($voucher->rows as $row) {
            $kind ??= $row->account === null ? null : IntercompanyKindRule::suggest($row->account->number, $row->account->name);
        }

        return [
            'kind' => $kind,
            'voucher_date' => $voucher->voucher_date->toDateString(),
            'description' => $voucher->description,
            'rows' => array_values($voucher->rows->map(fn (VoucherRow $row): array => [
                'account' => (string) IntercompanyMirror::account((int) $row->account?->number),
                'debit_ore' => $row->credit_ore->ore,
                'credit_ore' => $row->debit_ore->ore,
                'description' => (string) $row->description,
                'cost_center' => (string) $row->costCenter?->code,
                'project' => (string) $row->project?->code,
                'check' => IntercompanyMirror::needsCheck((int) $row->account?->number),
            ])->all()),
        ];
    }

    /**
     * @param  array<string, mixed>  $input  kind (A's side), voucher_date, description, series (B's code, default A),
     *                                       rows (account, debit_ore, credit_ore, description, cost_center, project)
     * @return IntercompanyPair A's own pair row
     *
     * @throws AuthorizationException|ValidationException
     */
    public function __invoke(User $user, Voucher $voucher, int $counterpartId, array $input): IntercompanyPair
    {
        $this->authorizeSource($user, $voucher);
        $company = Company::query()->findOrFail($voucher->company_id);

        $counterpart = self::counterparts($user, $company)->firstWhere('id', $counterpartId);

        if (! $counterpart instanceof Company || DimensionScope::limits($user, $counterpart)) {
            throw ValidationException::withMessages(['counterpart' => __('Välj ett koncernföretag där du får skapa verifikationer.')]);
        }

        $validated = validator($input, [
            'kind' => ['required', Rule::in(array_map(fn (IntercompanyKind $kind): string => $kind->value, IntercompanyMirror::PAIRED_KINDS))],
        ], ['kind.required' => __('Välj vad det är för mellanhavande.'), 'kind.in' => __('Välj vad det är för mellanhavande.')])->validate();

        $kind = IntercompanyKind::from((string) $validated['kind']);
        $ownNumber = OrganisationNumber::tryFrom((string) $company->org_number)?->formatted();
        $theirNumber = OrganisationNumber::tryFrom((string) $counterpart->org_number)?->formatted();

        if ($ownNumber === null || $theirNumber === null) {
            throw ValidationException::withMessages(['counterpart' => __('Företagets organisationsnummer saknas eller är fel.')]);
        }

        $reference = self::reference($voucher);
        $typed = array_values((array) ($input['rows'] ?? []));
        $proposed = $this->propose($user, $voucher)['rows'];

        // The rows, their number and their amounts are the posted voucher's, mirrored; only the account,
        // the text and the codes may come from the person (a tampered amount or an extra row is ignored).
        $rows = array_map(function (array $row, int $index) use ($typed): array {
            $given = is_array($typed[$index] ?? null) ? $typed[$index] : [];

            return [
                'account' => (int) trim((string) ($given['account'] ?? $row['account'])),
                'debit_ore' => $row['debit_ore'],
                'credit_ore' => $row['credit_ore'],
                'description' => trim((string) ($given['description'] ?? $row['description'])),
                'cost_center' => trim((string) ($given['cost_center'] ?? $row['cost_center'])),
                'project' => trim((string) ($given['project'] ?? $row['project'])),
            ];
        }, $proposed, array_keys($proposed));

        return CurrentCompany::run($company, fn (): IntercompanyPair => DB::transaction(function () use ($user, $voucher, $company, $counterpart, $kind, $ownNumber, $theirNumber, $reference, $rows, $input): IntercompanyPair {
            // One paired draft per source voucher and counterpart at a time.
            Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            $draft = CurrentCompany::run($counterpart, function () use ($user, $counterpart, $kind, $ownNumber, $reference, $rows, $input): Voucher {
                $exists = IntercompanyPair::query()
                    ->where('counterparty_org_number', $ownNumber)
                    ->where('counterparty_voucher_ref', $reference)
                    ->exists();

                if ($exists) {
                    throw ValidationException::withMessages(['counterpart' => __('Det finns redan ett motsvarande utkast i :company för verifikation :reference.', ['company' => $counterpart->name, 'reference' => $reference])]);
                }

                $draft = ($this->saveDraft)($user, $counterpart, [
                    'voucher_date' => (string) ($input['voucher_date'] ?? ''),
                    'description' => (string) ($input['description'] ?? ''),
                    'series' => (string) (($input['series'] ?? '') ?: 'A'),
                    'rows' => $rows,
                ]);

                $pair = IntercompanyPair::create([
                    'voucher_id' => $draft->id,
                    'counterparty_org_number' => $ownNumber,
                    'counterparty_voucher_ref' => $reference,
                    'kind' => IntercompanyMirror::kind($kind),
                    'created_by' => $user->id,
                ]);

                Audit::record('intercompany.paired_draft_created', $draft, ['pair_id' => $pair->id, 'kind' => $pair->kind->value, 'rows' => count($rows)], $counterpart, $user);

                return $draft;
            });

            $pair = IntercompanyPair::query()->where('voucher_id', $voucher->id)->where('counterparty_org_number', $theirNumber)->first()
                ?? IntercompanyPair::create([
                    'voucher_id' => $voucher->id,
                    'counterparty_org_number' => $theirNumber,
                    'counterparty_voucher_ref' => null,
                    'kind' => $kind,
                    'created_by' => $user->id,
                ]);

            Audit::record('intercompany.pair_created', $pair, ['pair_id' => $pair->id, 'voucher_id' => $voucher->id, 'kind' => $pair->kind->value, 'rows' => count($draft->rows)], $company, $user);

            return $pair;
        }));
    }

    /**
     * The source voucher as the counterpart's pair row names it: series and
     * number with the fiscal year, since numbers start again every year
     * ("A12 (2026)" for a calendar year, "A12 (20250701–20260630)" for any
     * other year, so two shortened years in one calendar year differ).
     */
    public static function reference(Voucher $voucher): string
    {
        $voucher->loadMissing('fiscalYear');
        $year = $voucher->fiscalYear;
        $label = match (true) {
            $year === null => $voucher->voucher_date->format('Y'),
            $year->start_date->format('m-d') === '01-01' && $year->end_date->format('m-d') === '12-31' && $year->start_date->year === $year->end_date->year => (string) $year->start_date->year,
            // A broken or shortened year by its dates, so two short years in one calendar year never share a label.
            default => $year->start_date->format('Ymd').'–'.$year->end_date->format('Ymd'),
        };

        return "{$voucher->reference()} ({$label})";
    }

    /** @throws AuthorizationException */
    private function authorizeSource(User $user, Voucher $voucher): void
    {
        $company = Company::query()->findOrFail($voucher->company_id);

        if (! $voucher->isPosted() || ! IntercompanyDeliveryPolicy::mayPair($user, $company) || ! Gate::forUser($user)->allows('view', $voucher)) {
            throw new AuthorizationException(__('Ett motsvarande utkast kan bara göras från en bokförd verifikation, av den som får skapa verifikationer.'));
        }
    }
}
