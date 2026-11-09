<?php

declare(strict_types=1);

namespace App\Modules\Saas\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Customer;
use App\Modules\Saas\Actions\MarkSeparateAgreements;
use App\Modules\Saas\Domain\SourceType;
use App\Modules\Saas\Jobs\RecomputeSaas;
use App\Modules\Saas\Livewire\Concerns\InSaas;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\Support\SaasReport;
use App\Modules\Saas\Support\SourceLabels;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Återkommande intäkter → Källor (screen 9): what could not be counted and
 * why — agreements without a customer, CRM agreements that cannot be spread
 * per month or while CRM is off, possible double counting (which a person
 * can mark as separate agreements), customers whose lines add up to less
 * than zero, and currencies without the month's rate — next to the notes
 * that explain what is left out on purpose. A fixed number of queries.
 */
class SourcesPage extends Component
{
    use InSaas;
    use ShowsToasts;

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function markSeparate(int $crmAgreementId, string $recurringInvoiceId, MarkSeparateAgreements $mark): void
    {
        try {
            $mark->mark($this->user(), $this->company(), $crmAgreementId, $recurringInvoiceId);
        } catch (AuthorizationException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        RecomputeSaas::dispatch($this->company()->id);
        $this->toast(__('Avtalen är markerade som separata och visas inte längre som möjlig dubbelräkning.'));
    }

    public function undoSeparate(int $pairId, MarkSeparateAgreements $mark): void
    {
        try {
            $mark->undo($this->user(), $this->company(), $pairId);
        } catch (AuthorizationException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        RecomputeSaas::dispatch($this->company()->id);
        $this->toast(__('Markeringen är borttagen.'));
    }

    public function render(): View
    {
        $latest = SaasReport::months()->last();
        $uncounted = SaasReport::uncounted();
        $doubles = SaasReport::possibleDoubles(CarbonImmutable::today());
        $separate = SaasReport::separatePairs();
        $sources = $uncounted->map(fn (SaasContractVersion $version): array => [$version->source_type->value, $version->source_id])->all();

        foreach ($doubles as $pairs) {
            foreach ($pairs as $pair) {
                $sources[] = [SourceType::CrmAgreement->value, $pair['crm']];
                $sources[] = [SourceType::RecurringInvoice->value, $pair['recurring']];
            }
        }

        foreach ($separate as $pair) {
            $sources[] = [SourceType::CrmAgreement->value, (string) $pair->crm_agreement_id];
            $sources[] = [SourceType::RecurringInvoice->value, $pair->recurring_invoice_id];
        }

        return view('saas::livewire.sources', [
            'uncounted' => $uncounted,
            'doubles' => $doubles,
            'separate' => $separate,
            'canManage' => $this->canManage(),
            'customers' => Customer::query()->whereIn('id', array_keys($doubles))->get(['id', 'number', 'name'])->keyBy('id'),
            'negative' => $latest === null ? collect() : SaasReport::negative($latest->key()),
            'labels' => SourceLabels::for($sources),
            'missingRates' => $latest === null ? [] : (array) $latest->missing_rates,
            'latest' => $latest,
        ]);
    }
}
