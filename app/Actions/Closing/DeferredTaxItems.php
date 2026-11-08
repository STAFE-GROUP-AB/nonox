<?php

declare(strict_types=1);

namespace App\Actions\Closing;

use App\Domain\Closing\DeferredTax\BuildingCarryingAmounts;
use App\Domain\Closing\DeferredTaxProposal;
use App\Models\Company;
use App\Models\DeferredTaxItem;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\YearEndEntry;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The items behind a K3 company's deferred tax (ADR-059, A.2.6): a person
 * adds, changes and removes them until the year's deferred tax is booked.
 * Needs fiscal-years:manage, as the rest of the closing. The audit log keeps
 * ids, enums and amounts, never the description or the reason text.
 */
class DeferredTaxItems
{
    /**
     * Creates an item, or changes the one with `id`.
     *
     * @param  array<string, mixed>  $input  kind, description, accounts (from/to), carrying, tax_value, tax_value_source, recognised, recognition_reason, fixed_asset_id, id
     */
    public function save(User $user, FiscalYear $fiscalYear, array $input): DeferredTaxItem
    {
        $company = $this->prepare($user, $fiscalYear);

        $kind = (string) ($input['kind'] ?? '');

        if (! in_array($kind, DeferredTaxItem::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => __('Välj vilken sorts post det är.')]);
        }

        $description = trim((string) ($input['description'] ?? ''));

        if ($description === '') {
            throw ValidationException::withMessages(['description' => __('Skriv vad posten avser.')]);
        }

        if (mb_strlen($description) > 120) {
            throw ValidationException::withMessages(['description' => __('Beskrivningen får vara högst 120 tecken.')]);
        }

        $source = (string) ($input['tax_value_source'] ?? DeferredTaxItem::SOURCE_ENTERED);

        if (! in_array($source, [DeferredTaxItem::SOURCE_ENTERED, DeferredTaxItem::SOURCE_INK2], true)) {
            throw ValidationException::withMessages(['tax_value_source' => __('Välj var det skattemässiga värdet kommer ifrån.')]);
        }

        $taxValue = $this->amount($input['tax_value'] ?? '', 'tax_value', required: true);
        $fixedAssetId = is_numeric($input['fixed_asset_id'] ?? null) ? (int) $input['fixed_asset_id'] : null;
        [$from, $to] = $this->accounts($kind, $input['account_from'] ?? '', $input['account_to'] ?? '');
        $carrying = null;

        if ($kind === DeferredTaxItem::LOSS_CARRY_FORWARD) {
            if ($from !== null || $fixedAssetId !== null) {
                throw ValidationException::withMessages(['kind' => __('Ett underskottsavdrag har inga konton. Skriv bara underskottets storlek.')]);
            }

            if ($taxValue === 0) {
                throw ValidationException::withMessages(['tax_value' => __('Skriv underskottet som får dras av mot framtida överskott.')]);
            }
        } elseif ($fixedAssetId !== null) {
            if ($kind !== DeferredTaxItem::BUILDING_COMPONENTS || $from !== null) {
                throw ValidationException::withMessages(['fixed_asset_id' => __('Bara en byggnad kan hämtas ur anläggningsregistret, och då utan konton.')]);
            }

            if (! array_key_exists($fixedAssetId, app(BuildingCarryingAmounts::class)->forYear($fiscalYear))) {
                throw ValidationException::withMessages(['fixed_asset_id' => __('Byggnaden finns inte i anläggningsregistret.')]);
            }
        } elseif ($from === null) {
            $carrying = $this->amount($input['carrying'] ?? '', 'carrying', required: true);
        }

        $recognised = $this->amount($input['recognised'] ?? '', 'recognised', required: false);
        $reason = trim((string) ($input['recognition_reason'] ?? ''));

        if ($recognised > 0 && $reason === '') {
            throw ValidationException::withMessages(['recognition_reason' => __('Skriv varför fordran tas upp: vad som övertygande talar för att det kommer skattepliktiga överskott att kvitta mot (K3 punkt 29.29).')]);
        }

        if (mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['recognition_reason' => __('Skälet får vara högst 1 000 tecken.')]);
        }

        $attributes = [
            'kind' => $kind,
            'description' => $description,
            'fixed_asset_id' => $fixedAssetId,
            'account_from' => $from,
            'account_to' => $to,
            'carrying_ore' => $carrying,
            'tax_value_ore' => $taxValue,
            'tax_value_source' => $source,
            'recognised_ore' => $recognised,
            'recognition_reason' => $recognised > 0 ? $reason : null,
            // Saving is the person's check of the tax value, also of an item copied from last year.
            'tax_value_needs_review' => false,
        ];

        return CurrentCompany::run($company, fn (): DeferredTaxItem => DB::transaction(function () use ($user, $company, $fiscalYear, $input, $attributes, $recognised): DeferredTaxItem {
            $this->lockAndEnsureNotBooked($fiscalYear);

            $id = $input['id'] ?? null;

            if ($id !== null && $id !== '') {
                $item = DeferredTaxItem::query()->where('fiscal_year_id', $fiscalYear->id)->whereKey((int) $id)->firstOrFail();
                $item->fill($attributes)->save();
                $action = 'deferred_tax.item_updated';
            } else {
                $item = DeferredTaxItem::create([...$attributes, 'fiscal_year_id' => $fiscalYear->id, 'created_by' => $user->id]);
                $action = 'deferred_tax.item_created';
            }

            // H6: the recognised asset can never exceed the asset computed for the item.
            if ($recognised > 0) {
                $line = collect(DeferredTaxProposal::for($company, $fiscalYear)->lines)->first(fn (array $line): bool => $line['item']->id === $item->id);

                if ($line === null || $line['nature'] !== DeferredTaxProposal::DEDUCTIBLE) {
                    throw ValidationException::withMessages(['recognised' => __('Posten ger ingen uppskjuten skattefordran, så inget belopp kan tas upp.')]);
                }

                if ($recognised > $line['gross_ore']) {
                    throw ValidationException::withMessages(['recognised' => __('Fordran kan vara högst :amount, skillnaden gånger skattesatsen.', ['amount' => Money::ofOre($line['gross_ore'])->format()])]);
                }
            }

            Audit::record($action, $item, [
                'fiscal_year_id' => $fiscalYear->id,
                'kind' => $item->kind,
                'fixed_asset_id' => $item->fixed_asset_id,
                'account_from' => $item->account_from,
                'account_to' => $item->account_to,
                'carrying_ore' => $item->carrying_ore?->ore,
                'tax_value_ore' => $item->tax_value_ore->ore,
                'tax_value_source' => $item->tax_value_source,
                'recognised_ore' => $item->recognised_ore->ore,
            ], $company, $user);

            return $item;
        }));
    }

    /**
     * Copies the previous fiscal year's items into a year that has none
     * (S1-P5 review): the same kind, description, accounts or register asset
     * and tax value, unconfirmed and marked for review, since the tax value
     * at the new balance date must be checked by a person. Carrying amounts
     * read from accounts or the register are recomputed by the proposal; an
     * entered one is copied and checked with the tax value. A recognised
     * deferred tax asset is not copied: it is judged anew at every balance
     * date (K3 29.29, H6).
     *
     * @return int the number of items copied
     */
    public function copyFromPreviousYear(User $user, FiscalYear $fiscalYear): int
    {
        $company = $this->prepare($user, $fiscalYear);

        return CurrentCompany::run($company, fn (): int => DB::transaction(function () use ($user, $company, $fiscalYear): int {
            $this->lockAndEnsureNotBooked($fiscalYear);

            if (DeferredTaxItem::query()->where('fiscal_year_id', $fiscalYear->id)->exists()) {
                throw ValidationException::withMessages(['item' => __('Året har redan poster. Posterna från föregående år kan bara hämtas till ett år utan poster.')]);
            }

            $previous = self::previousYear($fiscalYear);
            $items = $previous === null ? null : DeferredTaxItem::query()->where('fiscal_year_id', $previous->id)->orderBy('id')->get();

            if ($items === null || $items->isEmpty()) {
                throw ValidationException::withMessages(['item' => __('Föregående räkenskapsår har inga poster att hämta.')]);
            }

            foreach ($items as $item) {
                DeferredTaxItem::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'kind' => $item->kind,
                    'description' => $item->description,
                    'fixed_asset_id' => $item->fixed_asset_id,
                    'account_from' => $item->account_from,
                    'account_to' => $item->account_to,
                    'carrying_ore' => $item->carrying_ore?->ore,
                    'tax_value_ore' => $item->tax_value_ore->ore,
                    'tax_value_source' => $item->tax_value_source,
                    'recognised_ore' => 0,
                    'copied_from_id' => $item->id,
                    'tax_value_needs_review' => true,
                    'created_by' => $user->id,
                ]);
            }

            Audit::record('deferred_tax.items_copied', $fiscalYear, ['from_fiscal_year_id' => $previous->id, 'items' => $items->modelKeys()], $company, $user);

            return $items->count();
        }));
    }

    /** The fiscal year that ends the day before this one begins, or null. */
    public static function previousYear(FiscalYear $fiscalYear): ?FiscalYear
    {
        return FiscalYear::query()->whereDate('end_date', $fiscalYear->start_date->subDay()->toDateString())->first();
    }

    public function remove(User $user, FiscalYear $fiscalYear, int $itemId): void
    {
        $company = $this->prepare($user, $fiscalYear);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $fiscalYear, $itemId): void {
            $this->lockAndEnsureNotBooked($fiscalYear);
            $item = DeferredTaxItem::query()->where('fiscal_year_id', $fiscalYear->id)->whereKey($itemId)->firstOrFail();
            $item->delete();
            Audit::record('deferred_tax.item_removed', $item, ['fiscal_year_id' => $fiscalYear->id, 'kind' => $item->kind], $company, $user);
        }));
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

        if (($refusal = DeferredTaxProposal::refusals($company, $fiscalYear)[0] ?? null) !== null) {
            throw ValidationException::withMessages(['fiscal_year' => $refusal]);
        }

        return $company;
    }

    private function lockAndEnsureNotBooked(FiscalYear $fiscalYear): void
    {
        FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();

        if (YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->where('type', YearEndEntry::DEFERRED_TAX)->exists()) {
            throw ValidationException::withMessages(['item' => __('Årets uppskjutna skatt är redan bokförd. Rätta verifikationen om den blev fel.')]);
        }
    }

    /**
     * The balance sheet accounts the carrying amount is read from, or none.
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
}
