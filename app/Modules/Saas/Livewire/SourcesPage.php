<?php

declare(strict_types=1);

namespace App\Modules\Saas\Livewire;

use App\Models\Customer;
use App\Modules\Saas\Domain\SourceType;
use App\Modules\Saas\Livewire\Concerns\InSaas;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\Support\SaasReport;
use App\Modules\Saas\Support\SourceLabels;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Återkommande intäkter → Källor (screen 9): what could not be counted and
 * why — agreements without a customer, CRM agreements that cannot be spread
 * per month or while CRM is off, possible double counting, customers whose
 * lines add up to less than zero, and currencies without the month's rate —
 * next to the notes that explain what is left out on purpose. A fixed number
 * of queries.
 */
class SourcesPage extends Component
{
    use InSaas;

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function render(): View
    {
        $latest = SaasReport::months()->last();
        $uncounted = SaasReport::uncounted();
        $doubles = SaasReport::possibleDoubles(CarbonImmutable::today());
        $sources = $uncounted->map(fn (SaasContractVersion $version): array => [$version->source_type->value, $version->source_id])->all();

        foreach ($doubles as $pair) {
            foreach ($pair['crm'] as $id) {
                $sources[] = [SourceType::CrmAgreement->value, $id];
            }

            foreach ($pair['recurring'] as $id) {
                $sources[] = [SourceType::RecurringInvoice->value, $id];
            }
        }

        return view('saas::livewire.sources', [
            'uncounted' => $uncounted,
            'doubles' => $doubles,
            'customers' => Customer::query()->whereIn('id', array_keys($doubles))->get(['id', 'number', 'name'])->keyBy('id'),
            'negative' => $latest === null ? collect() : SaasReport::negative($latest->key()),
            'labels' => SourceLabels::for($sources),
            'missingRates' => $latest === null ? [] : (array) $latest->missing_rates,
            'latest' => $latest,
        ]);
    }
}
