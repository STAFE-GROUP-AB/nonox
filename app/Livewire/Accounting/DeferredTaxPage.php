<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Closing\DeferredTaxItems;
use App\Actions\Closing\YearEndClosing;
use App\Domain\Closing\DeferredTaxProposal;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\DeferredTaxItem;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\YearEndEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bokslut → Uppskjuten skatt (ADR-059, A.2.6): the temporary differences of
 * a K3 year, the proposed deferred tax, and the person's confirmation that
 * books it. Everyone with reports:view may look; changing and booking need
 * fiscal-years:manage, checked again by the actions.
 */
class DeferredTaxPage extends Component
{
    use ShowsToasts;

    #[Url(as: 'ar', except: 0)]
    public int $year = 0;

    public ?int $editing = null;

    public string $kind = DeferredTaxItem::BUILDING_COMPONENTS;

    public string $description = '';

    public string $accountFrom = '';

    public string $accountTo = '';

    public string $carrying = '';

    public string $taxValue = '';

    public string $taxValueSource = DeferredTaxItem::SOURCE_ENTERED;

    public string $recognised = '';

    public string $recognitionReason = '';

    /** The person confirms a legal right of set-off (K3 29.32); only then are liability and asset netted. */
    public bool $setOff = false;

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'reports:view'), 403);

        if (FiscalYear::query()->whereKey($this->year)->doesntExist()) {
            $this->year = (int) (FiscalYear::query()->where('status', 'OPEN')->whereDate('end_date', '<', now()->toDateString())->orderBy('start_date')->value('id')
                ?? FiscalYear::query()->orderByDesc('start_date')->value('id'));
        }
    }

    public function save(DeferredTaxItems $items): void
    {
        $this->attempt(function () use ($items): void {
            $items->save($this->user(), $this->fiscalYear(), [
                'id' => $this->editing,
                'kind' => $this->kind,
                'description' => $this->description,
                'account_from' => $this->accountFrom,
                'account_to' => $this->accountTo,
                'carrying' => $this->carrying,
                'tax_value' => $this->taxValue,
                'tax_value_source' => $this->taxValueSource,
                'recognised' => $this->recognised,
                'recognition_reason' => $this->recognitionReason,
            ]);
            $this->toast($this->editing === null ? __('Posten är tillagd.') : __('Posten är ändrad.'));
            $this->resetForm();
        });
    }

    public function edit(int $id): void
    {
        $item = DeferredTaxItem::query()->where('fiscal_year_id', $this->fiscalYear()->id)->whereKey($id)->firstOrFail();

        $this->editing = $item->id;
        $this->kind = $item->kind;
        $this->description = $item->description;
        $this->accountFrom = $item->account_from === null ? '' : (string) $item->account_from;
        $this->accountTo = $item->account_to === null ? '' : (string) $item->account_to;
        $this->carrying = $item->carrying_ore === null ? '' : $item->carrying_ore->format(false);
        $this->taxValue = $item->tax_value_ore->format(false);
        $this->taxValueSource = $item->tax_value_source;
        $this->recognised = $item->recognised_ore->isZero() ? '' : $item->recognised_ore->format(false);
        $this->recognitionReason = (string) $item->recognition_reason;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function remove(DeferredTaxItems $items, int $id): void
    {
        $this->attempt(function () use ($items, $id): void {
            $items->remove($this->user(), $this->fiscalYear(), $id);
            $this->toast(__('Posten är borttagen.'));

            if ($this->editing === $id) {
                $this->resetForm();
            }
        });
    }

    /** Books the reviewed proposal; the amount shown is the amount confirmed. */
    public function confirm(YearEndClosing $closing, int $expenseOre): void
    {
        $this->attempt(function () use ($closing, $expenseOre): void {
            $entry = $closing->confirmDeferredTax($this->user(), $this->fiscalYear(), $expenseOre, $this->setOff);
            $this->toast($entry->amount_ore->isZero()
                ? __('Den uppskjutna skatten är bekräftad. Inget behövde bokföras.')
                : __('Förändringen av uppskjuten skatt, :amount, är bokförd.', ['amount' => $entry->amount_ore->format()]));
        });
    }

    public function render(): View
    {
        $fiscalYear = $this->fiscalYear();
        $company = $this->company();
        $refusals = DeferredTaxProposal::refusals($company, $fiscalYear);

        return view('livewire.accounting.deferred-tax', [
            'years' => FiscalYear::query()->orderByDesc('start_date')->get(),
            'fiscalYear' => $fiscalYear,
            'refusals' => $refusals,
            'proposal' => $refusals === [] ? DeferredTaxProposal::for($company, $fiscalYear, $this->setOff) : null,
            'entry' => YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->where('type', YearEndEntry::DEFERRED_TAX)->first(),
            'canManage' => $this->user()->hasTeamPermission($company, 'fiscal-years:manage'),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'kind', 'description', 'accountFrom', 'accountTo', 'carrying', 'taxValue', 'taxValueSource', 'recognised', 'recognitionReason');
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
