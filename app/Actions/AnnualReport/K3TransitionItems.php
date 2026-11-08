<?php

declare(strict_types=1);

namespace App\Actions\AnnualReport;

use App\Domain\AnnualReport\K3ForbiddenRestatement;
use App\Domain\AnnualReport\K3TransitionProposal;
use App\Domain\Closing\DeferredTaxProposal;
use App\Models\Account;
use App\Models\Company;
use App\Models\DeferredTaxItem;
use App\Models\FiscalYear;
use App\Models\K3Transition;
use App\Models\K3TransitionItem;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The adjustments of the opening balance sheet at the K2-to-K3 transition
 * (ADR-059 A.2.8, S1-P12): a person adds, changes and removes them until the
 * transition is booked. Needs fiscal-years:manage, as the rest of the
 * closing. The audit log keeps ids, enums, accounts and amounts, never the
 * description or the reason texts.
 *
 * Refused here: the restatements K3 35.15, 35.16, 35.18 and 35.20 forbid
 * (K3ForbiddenRestatement, S1-R7.2), untaxed reserves as a deferred tax item
 * (K3 29.37), the 35.27 relief without a reason (S1-R7.3), and a recognised
 * deferred tax asset without a reason or above the computed asset (H6).
 */
class K3TransitionItems
{
    /** Debit or credit for an OTHER item. */
    public const string DEBIT = 'debit';

    public const string CREDIT = 'credit';

    /**
     * Creates an item, or changes the one with `id`.
     *
     * @param  array<string, mixed>  $input  kind, description, deferred_tax_kind, account_from, account_to, carrying, tax_value, recognised, recognition_reason, relief, relief_reason, account, amount, direction, k3_reference, id
     */
    public function save(User $user, FiscalYear $fiscalYear, array $input): K3TransitionItem
    {
        $company = $this->prepare($user, $fiscalYear);
        $kind = (string) ($input['kind'] ?? '');
        $forbidden = K3ForbiddenRestatement::tryFrom($kind);

        if ($forbidden !== null) {
            throw ValidationException::withMessages(['kind' => $forbidden->message()]);
        }

        if (! in_array($kind, K3TransitionItem::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => __('Välj vilken sorts post det är.')]);
        }

        $description = trim((string) ($input['description'] ?? ''));

        if ($description === '') {
            throw ValidationException::withMessages(['description' => __('Skriv vad posten avser.')]);
        }

        if (mb_strlen($description) > 120) {
            throw ValidationException::withMessages(['description' => __('Beskrivningen får vara högst 120 tecken.')]);
        }

        $attributes = match ($kind) {
            K3TransitionItem::DEFERRED_TAX => $this->deferredTax($input),
            K3TransitionItem::PROVISION => $this->provision($input),
            default => $this->other($input),
        };
        $attributes = [...$attributes, 'kind' => $kind, 'description' => $description];

        return CurrentCompany::run($company, fn (): K3TransitionItem => DB::transaction(function () use ($user, $company, $fiscalYear, $input, $attributes): K3TransitionItem {
            $this->lockAndEnsureNotBooked($fiscalYear);

            if ($attributes['account'] !== null && Account::query()->where('number', $attributes['account'])->where('is_active', true)->doesntExist()) {
                throw ValidationException::withMessages(['account' => __('Konto :account finns inte i kontoplanen. Lägg till det först.', ['account' => $attributes['account']])]);
            }

            $id = $input['id'] ?? null;

            if ($id !== null && $id !== '') {
                $item = K3TransitionItem::query()->where('fiscal_year_id', $fiscalYear->id)->whereKey((int) $id)->firstOrFail();
                $item->fill($attributes)->save();
                $action = 'k3_transition.item_updated';
            } else {
                $item = K3TransitionItem::create([...$attributes, 'fiscal_year_id' => $fiscalYear->id, 'created_by' => $user->id]);
                $action = 'k3_transition.item_created';
            }

            // H6: a recognised deferred tax asset never exceeds the asset computed for the item.
            if ($item->recognised_ore->ore > 0) {
                $line = collect(K3TransitionProposal::for($company, $fiscalYear)->lines)->first(fn (array $line): bool => $line['item']->id === $item->id);

                if ($line === null || $line['nature'] !== DeferredTaxProposal::DEDUCTIBLE) {
                    throw ValidationException::withMessages(['recognised' => __('Posten ger ingen uppskjuten skattefordran, så inget belopp kan tas upp.')]);
                }

                if ($item->recognised_ore->ore > $line['gross_ore']) {
                    throw ValidationException::withMessages(['recognised' => __('Fordran kan vara högst :amount, skillnaden gånger skattesatsen.', ['amount' => Money::ofOre($line['gross_ore'])->format()])]);
                }
            }

            Audit::record($action, $item, [
                'fiscal_year_id' => $fiscalYear->id,
                'kind' => $item->kind,
                'deferred_tax_kind' => $item->deferred_tax_kind,
                'account_from' => $item->account_from,
                'account_to' => $item->account_to,
                'carrying_ore' => $item->carrying_ore?->ore,
                'tax_value_ore' => $item->tax_value_ore?->ore,
                'recognised_ore' => $item->recognised_ore->ore,
                'relief_35_27' => $item->relief_35_27,
                'account' => $item->account,
                'amount_ore' => $item->amount_ore?->ore,
            ], $company, $user);

            return $item;
        }));
    }

    public function remove(User $user, FiscalYear $fiscalYear, int $itemId): void
    {
        $company = $this->prepare($user, $fiscalYear);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $fiscalYear, $itemId): void {
            $this->lockAndEnsureNotBooked($fiscalYear);
            $item = K3TransitionItem::query()->where('fiscal_year_id', $fiscalYear->id)->whereKey($itemId)->firstOrFail();
            $item->delete();
            Audit::record('k3_transition.item_removed', $item, ['fiscal_year_id' => $fiscalYear->id, 'kind' => $item->kind], $company, $user);
        }));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function deferredTax(array $input): array
    {
        $deferredKind = (string) ($input['deferred_tax_kind'] ?? '');

        if (! in_array($deferredKind, [DeferredTaxItem::OTHER_ASSET, DeferredTaxItem::OTHER_LIABILITY, DeferredTaxItem::LOSS_CARRY_FORWARD], true)) {
            throw ValidationException::withMessages(['deferred_tax_kind' => __('Välj om skillnaden gäller en tillgång, en skuld eller ett underskottsavdrag.')]);
        }

        $loss = $deferredKind === DeferredTaxItem::LOSS_CARRY_FORWARD;
        [$from, $to] = $loss ? [null, null] : $this->accounts($deferredKind, $input['account_from'] ?? '', $input['account_to'] ?? '');
        $carrying = ! $loss && $from === null ? $this->amount($input['carrying'] ?? '', 'carrying', required: true) : null;
        $relief = (bool) ($input['relief'] ?? false);
        $reliefReason = trim((string) ($input['relief_reason'] ?? ''));

        if ($relief) {
            if ($reliefReason === '') {
                throw ValidationException::withMessages(['relief_reason' => __('Skriv varför uppgifterna inte kan tas fram utan oskälig kostnad. Lättnadsregeln i K3 punkt 35.27 får bara användas då, och den redovisas i årsredovisningen.')]);
            }

            if (mb_strlen($reliefReason) > 1000) {
                throw ValidationException::withMessages(['relief_reason' => __('Skälet får vara högst 1 000 tecken.')]);
            }

            return [
                'deferred_tax_kind' => $deferredKind,
                'account_from' => $from,
                'account_to' => $to,
                'carrying_ore' => $carrying,
                'tax_value_ore' => null,
                'recognised_ore' => 0,
                'recognition_reason' => null,
                'relief_35_27' => true,
                'relief_reason' => $reliefReason,
                'account' => null,
                'amount_ore' => null,
                'k3_reference' => null,
            ];
        }

        $taxValue = $this->amount($input['tax_value'] ?? '', 'tax_value', required: true);

        if ($loss && $taxValue === 0) {
            throw ValidationException::withMessages(['tax_value' => __('Skriv underskottet som får dras av mot framtida överskott.')]);
        }

        $recognised = $this->amount($input['recognised'] ?? '', 'recognised', required: false);
        $reason = trim((string) ($input['recognition_reason'] ?? ''));

        if ($recognised > 0 && $reason === '') {
            throw ValidationException::withMessages(['recognition_reason' => __('Skriv varför fordran tas upp: vad som övertygande talar för att det kommer skattepliktiga överskott att kvitta mot (K3 punkt 29.29).')]);
        }

        if (mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['recognition_reason' => __('Skälet får vara högst 1 000 tecken.')]);
        }

        return [
            'deferred_tax_kind' => $deferredKind,
            'account_from' => $from,
            'account_to' => $to,
            'carrying_ore' => $carrying,
            'tax_value_ore' => $taxValue,
            'recognised_ore' => $recognised,
            'recognition_reason' => $recognised > 0 ? $reason : null,
            'relief_35_27' => false,
            'relief_reason' => null,
            'account' => null,
            'amount_ore' => null,
            'k3_reference' => null,
        ];
    }

    /**
     * A provision K3 chapter 21 requires: credited on a 22xx account.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function provision(array $input): array
    {
        $account = $this->accountNumber($input['account'] ?? '');

        if ($account < 2200 || $account > 2299 || ($account >= 2240 && $account <= 2249)) {
            throw ValidationException::withMessages(['account' => __('En avsättning bokförs på ett avsättningskonto, 2200–2299 (utom 2240–2249, som är uppskjuten skatt).')]);
        }

        $amount = $this->amount($input['amount'] ?? '', 'amount', required: true);

        if ($amount === 0) {
            throw ValidationException::withMessages(['amount' => __('Skriv avsättningens belopp.')]);
        }

        return [
            ...self::noDeferredTax(),
            'account' => $account,
            'amount_ore' => -$amount,
            'k3_reference' => $this->reference($input['k3_reference'] ?? '', required: false) ?? 'K3 kapitel 21',
        ];
    }

    /**
     * Any other retroactive restatement, with its K3 reference. The fixed
     * asset accounts (1000–1299) are refused as forbidden restatements.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function other(array $input): array
    {
        $account = $this->accountNumber($input['account'] ?? '');
        $forbidden = K3ForbiddenRestatement::forAccount($account);

        if ($forbidden !== null) {
            throw ValidationException::withMessages(['account' => $forbidden->message()]);
        }

        if ($account < 1300 || $account > 2999) {
            throw ValidationException::withMessages(['account' => __('En justering vid övergången görs på ett balanskonto, 1300–2999.')]);
        }

        if (($account >= 1370 && $account <= 1379) || ($account >= 2240 && $account <= 2249)) {
            throw ValidationException::withMessages(['account' => __('Uppskjuten skatt läggs upp som en post av slaget Uppskjuten skatt, inte på kontot direkt.')]);
        }

        if ($account >= 2080 && $account <= 2099) {
            throw ValidationException::withMessages(['account' => __('Motkontot i eget kapital sätter NONOX själv. Ange kontot för tillgången eller skulden som justeras.')]);
        }

        if ($account >= 2100 && $account <= 2199) {
            throw ValidationException::withMessages(['account' => __('Obeskattade reserver justeras inte vid övergången. I juridisk person redovisas de brutto (K3 punkt 29.37).')]);
        }

        $amount = $this->amount($input['amount'] ?? '', 'amount', required: true);

        if ($amount === 0) {
            throw ValidationException::withMessages(['amount' => __('Skriv justeringens belopp.')]);
        }

        $direction = (string) ($input['direction'] ?? '');

        if (! in_array($direction, [self::DEBIT, self::CREDIT], true)) {
            throw ValidationException::withMessages(['direction' => __('Välj om kontot ska debiteras eller krediteras.')]);
        }

        return [
            ...self::noDeferredTax(),
            'account' => $account,
            'amount_ore' => $direction === self::DEBIT ? $amount : -$amount,
            'k3_reference' => $this->reference($input['k3_reference'] ?? '', required: true),
        ];
    }

    /** @return array<string, mixed> */
    private static function noDeferredTax(): array
    {
        return [
            'deferred_tax_kind' => null,
            'account_from' => null,
            'account_to' => null,
            'carrying_ore' => null,
            'tax_value_ore' => null,
            'recognised_ore' => 0,
            'recognition_reason' => null,
            'relief_35_27' => false,
            'relief_reason' => null,
        ];
    }

    private function reference(mixed $value, bool $required): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            if ($required) {
                throw ValidationException::withMessages(['k3_reference' => __('Ange vilken punkt i K3 justeringen följer av, till exempel K3 punkt 21.4.')]);
            }

            return null;
        }

        if (mb_strlen($value) > 60) {
            throw ValidationException::withMessages(['k3_reference' => __('Hänvisningen får vara högst 60 tecken.')]);
        }

        return $value;
    }

    private function accountNumber(mixed $value): int
    {
        $value = trim((string) $value);

        if (preg_match('/^\d{4}$/', $value) !== 1) {
            throw ValidationException::withMessages(['account' => __('Skriv kontot som ett fyrsiffrigt kontonummer.')]);
        }

        return (int) $value;
    }

    /**
     * The balance sheet accounts the carrying amount is read from at the
     * transition date, or none.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function accounts(string $kind, mixed $from, mixed $to): array
    {
        $from = trim((string) $from);
        $to = trim((string) $to);

        if ($from === '' && $to === '') {
            return [null, null];
        }

        if ($to === '') {
            $to = $from;
        }

        if (preg_match('/^\d{4}$/', $from) !== 1 || preg_match('/^\d{4}$/', $to) !== 1 || (int) $from > (int) $to) {
            throw ValidationException::withMessages(['account_from' => __('Skriv kontona som fyrsiffriga kontonummer, det lägsta först.')]);
        }

        $from = (int) $from;
        $to = (int) $to;

        if (DeferredTaxProposal::touchesUntaxedReserves($from, $to)) {
            throw ValidationException::withMessages(['account_from' => __('Obeskattade reserver (konto 2100–2199) kan inte vara en post. I juridisk person redovisas de brutto, utan uppskjuten skatt (K3 punkt 29.37).')]);
        }

        if (DeferredTaxProposal::overlaps($from, $to, DeferredTaxProposal::LIABILITY_RANGE) || DeferredTaxProposal::overlaps($from, $to, DeferredTaxProposal::ASSET_RANGE)) {
            throw ValidationException::withMessages(['account_from' => __('Kontona för uppskjuten skatt (1370–1379 och 2240–2249) kan inte vara en post.')]);
        }

        [$min, $max] = DeferredTaxItem::isAssetKind($kind) ? [1000, 1999] : [2200, 2999];

        if (DeferredTaxProposal::overlaps($from, $to, DeferredTaxProposal::EQUITY) || $from < $min || $to > $max) {
            throw ValidationException::withMessages(['account_from' => DeferredTaxItem::isAssetKind($kind)
                ? __('En tillgång läses från tillgångskonton, 1000–1999.')
                : __('En skuld läses från skuldkonton, 2200–2999.')]);
        }

        return [$from, $to];
    }

    private function amount(mixed $value, string $field, bool $required): int
    {
        $value = trim((string) $value);

        if ($value === '') {
            if ($required) {
                throw ValidationException::withMessages([$field => __('Skriv beloppet.')]);
            }

            return 0;
        }

        try {
            $ore = Money::parse($value)->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Beloppet går inte att läsa. Skriv till exempel 12 500 eller 12 500,50.')]);
        }

        if ($ore < 0) {
            throw ValidationException::withMessages([$field => __('Skriv beloppet utan minustecken.')]);
        }

        return $ore;
    }

    private function prepare(User $user, FiscalYear $fiscalYear): Company
    {
        $company = Company::query()->findOrFail($fiscalYear->company_id);

        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }

        if (! ($fiscalYear->fresh() ?? $fiscalYear)->isOpen()) {
            throw ValidationException::withMessages(['fiscal_year' => __('Räkenskapsåret :year är stängt.', ['year' => $fiscalYear->label()])]);
        }

        if (($refusal = K3TransitionProposal::refusals($company, $fiscalYear)[0] ?? null) !== null) {
            throw ValidationException::withMessages(['fiscal_year' => $refusal]);
        }

        return $company;
    }

    private function lockAndEnsureNotBooked(FiscalYear $fiscalYear): void
    {
        FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();

        if (K3Transition::query()->where('fiscal_year_id', $fiscalYear->id)->exists()) {
            throw ValidationException::withMessages(['item' => __('Övergången till K3 är redan bokförd. Rätta verifikationen om något blev fel.')]);
        }
    }
}
