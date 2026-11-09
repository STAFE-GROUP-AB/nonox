<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\AnnualReport\BookK3Transition;
use App\Actions\AnnualReport\K3TransitionItems;
use App\Domain\AnnualReport\K3TransitionProposal;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\DeferredTaxItem;
use App\Models\FiscalYear;
use App\Models\K3TransitionItem;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bokslut → Övergång till K3 (ADR-059 A.2.8, S1-P12): the adjustments of the
 * opening balance sheet of the first K3 year, the reconciliation sheet, the
 * component check, the disclosures, and the person's confirmation that books
 * the transition once. Everyone with reports:view may look; changing and
 * booking need fiscal-years:manage, checked again by the actions. Beta, like
 * the other K3 screens.
 */
class K3TransitionPage extends Component
{
    use ShowsToasts;

    #[Url(as: 'ar', except: 0)]
    public int $year = 0;

    public ?int $editing = null;

    public string $kind = K3TransitionItem::DEFERRED_TAX;

    public string $description = '';

    public string $deferredTaxKind = DeferredTaxItem::OTHER_ASSET;

    public string $accountFrom = '';

    public string $accountTo = '';

    public string $carrying = '';

    public string $taxValue = '';

    public string $recognised = '';

    public string $recognitionReason = '';

    public bool $relief = false;

    public string $reliefReason = '';

    public string $account = '';

    public string $amount = '';

    public string $direction = K3TransitionItems::DEBIT;

    public string $k3Reference = '';

    /** A provision for dismantling or restoration (K3 35.29), which the transition cannot handle yet. */
    public bool $dismantling = false;

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'reports:view'), 403);

        if (FiscalYear::query()->whereKey($this->year)->doesntExist()) {
            $this->year = (int) (FiscalYear::query()->where('reporting_framework', 'K3')->orderBy('start_date')->value('id')
                ?? FiscalYear::query()->orderByDesc('start_date')->value('id'));
        }
    }

    public function save(K3TransitionItems $items): void
    {
        $this->attempt(function () use ($items): void {
            $items->save($this->user(), $this->fiscalYear(), [
                'id' => $this->editing,
                'kind' => $this->kind,
                'description' => $this->description,
                'deferred_tax_kind' => $this->deferredTaxKind,
                'account_from' => $this->accountFrom,
                'account_to' => $this->accountTo,
                'carrying' => $this->carrying,
                'tax_value' => $this->taxValue,
                'recognised' => $this->recognised,
                'recognition_reason' => $this->recognitionReason,
                'relief' => $this->relief,
                'relief_reason' => $this->reliefReason,
                'account' => $this->account,
                'amount' => $this->amount,
                'direction' => $this->direction,
                'k3_reference' => $this->k3Reference,
                'dismantling' => $this->dismantling,
            ]);
            $this->toast($this->editing === null ? __('Posten är tillagd.') : __('Posten är ändrad.'));
            $this->resetForm();
        });
    }

    public function edit(int $id): void
    {
        $item = K3TransitionItem::query()->where('fiscal_year_id', $this->fiscalYear()->id)->whereNull('k3_transition_id')->whereKey($id)->firstOrFail();

        $this->editing = $item->id;
        $this->kind = $item->kind;
        $this->description = $item->description;
        $this->deferredTaxKind = $item->deferred_tax_kind ?? DeferredTaxItem::OTHER_ASSET;
        $this->accountFrom = $item->account_from === null ? '' : (string) $item->account_from;
        $this->accountTo = $item->account_to === null ? '' : (string) $item->account_to;
        $this->carrying = $item->carrying_ore === null ? '' : $item->carrying_ore->format(false);
        $this->taxValue = $item->tax_value_ore === null ? '' : $item->tax_value_ore->format(false);
        $this->recognised = $item->recognised_ore->isZero() ? '' : $item->recognised_ore->format(false);
        $this->recognitionReason = (string) $item->recognition_reason;
        $this->relief = $item->relief_35_27;
        $this->reliefReason = (string) $item->relief_reason;
        $this->account = $item->account === null ? '' : (string) $item->account;
        $amount = $item->amount_ore->ore ?? 0;
        $this->amount = $amount === 0 ? '' : Money::ofOre(abs($amount))->format(false);
        $this->direction = $amount < 0 ? K3TransitionItems::CREDIT : K3TransitionItems::DEBIT;
        $this->k3Reference = (string) $item->k3_reference;
    }

    /** Records whether the right of set-off (K3 29.32) is confirmed; it changes only the presentation. */
    public function setOff(K3TransitionItems $items, bool $confirmed): void
    {
        $this->attempt(function () use ($items, $confirmed): void {
            $items->setOff($this->user(), $this->fiscalYear(), $confirmed);
            $this->toast($confirmed
                ? __('Kvittningsrätten är bekräftad. Uppskjuten skatt visas netto i ingångsbalansräkningen.')
                : __('Kvittningsrätten är inte bekräftad. Uppskjuten skatt visas brutto.'));
        });
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function remove(K3TransitionItems $items, int $id): void
    {
        $this->attempt(function () use ($items, $id): void {
            $items->remove($this->user(), $this->fiscalYear(), $id);
            $this->toast(__('Posten är borttagen.'));

            if ($this->editing === $id) {
                $this->resetForm();
            }
        });
    }

    /** Books the reviewed transition; the fingerprint is what the person saw. */
    public function confirm(BookK3Transition $book, string $fingerprint): void
    {
        $this->attempt(function () use ($book, $fingerprint): void {
            $transition = $book($this->user(), $this->fiscalYear(), $fingerprint);
            $this->toast($transition->voucher_id === null
                ? __('Övergången till K3 är bekräftad. Inget behövde bokföras.')
                : __('Övergången till K3 är bokförd. Eget kapital ändrades med :amount.', ['amount' => $transition->equity_effect_ore->format()]));
        });
    }

    public function render(): View
    {
        $fiscalYear = $this->fiscalYear();
        $company = $this->company();
        $refusals = K3TransitionProposal::refusals($company, $fiscalYear);

        return view('livewire.accounting.k3-transition', [
            'years' => FiscalYear::query()->orderByDesc('start_date')->get(),
            'fiscalYear' => $fiscalYear,
            'refusals' => $refusals,
            'proposal' => $refusals === [] ? K3TransitionProposal::for($company, $fiscalYear) : null,
            'canManage' => $this->user()->hasTeamPermission($company, 'fiscal-years:manage'),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'kind', 'description', 'deferredTaxKind', 'accountFrom', 'accountTo', 'carrying', 'taxValue', 'recognised', 'recognitionReason', 'relief', 'reliefReason', 'account', 'amount', 'direction', 'k3Reference', 'dismantling');
    }

    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    private function fiscalYear(): FiscalYear
    {
        return FiscalYear::query()->findOrFail($this->year);
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
