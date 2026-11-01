<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\StaffLedger\Actions\OpenControlVisit;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Support\Export\ControlVisitReport;
use App\Modules\StaffLedger\Support\Export\LedgerXmlFormat;
use App\Modules\StaffLedger\Support\Export\SkatteverketTransfer;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "Skatteverket är här": one tap from the staff ledger's navigation. Who is
 * checked in now, the ledger for a chosen period, searchable, with personal
 * numbers in full; exports to PDF, CSV and XML, and electronic transfer
 * once Skatteverket offers it. Opening it is written to the audit log.
 */
class ControlVisitPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    #[Url(as: 'fran')]
    public string $from = '';

    #[Url(as: 'till')]
    public string $to = '';

    #[Url(as: 'lokal')]
    public string $location = '';

    #[Url(as: 'sok')]
    public string $search = '';

    public function mount(OpenControlVisit $open): void
    {
        $open->inApp($this->user(), $this->company());

        $this->from = $this->from ?: CarbonImmutable::today()->toDateString();
        $this->to = $this->to ?: CarbonImmutable::today()->toDateString();
    }

    public function transfer(SkatteverketTransfer $transfer, LedgerXmlFormat $xml): void
    {
        abort_unless($this->allowed('staff-ledger:control-visit'), 403);

        $report = $this->report();
        $result = $transfer->send($this->company(), $xml->render($report), $xml->version());

        Audit::record('staff_ledger.transfer_attempted', null, ['ok' => $result['ok'], 'reference' => $result['reference'], 'format' => $xml->version()], $this->company(), $this->user());
        $this->toast($result['ok'] ? __('Liggaren är överförd till Skatteverket. Referens: :reference', ['reference' => (string) $result['reference']]) : $result['message'], $result['ok'] ? 'success' : 'error');
    }

    public function render(SkatteverketTransfer $transfer): View
    {
        $report = $this->report();
        $query = array_filter(['fran' => $this->from, 'till' => $this->to, 'lokal' => $this->location, 'sok' => $this->search]);

        return view('staff-ledger::livewire.control-visit', [
            'report' => $report,
            'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
            'exportQuery' => $query,
            'transferAvailable' => $transfer->isAvailable(),
        ]);
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['from', 'to'], true)) {
            return;
        }

        [$from, $to] = $this->period();

        if (! ControlVisitReport::tooLong($from, $to)) {
            return;
        }

        $this->from = $to->subMonthsNoOverflow(ControlVisitReport::MAX_MONTHS)->toDateString();
        $this->to = $to->toDateString();
        $this->toast(ControlVisitReport::tooLongMessage(), 'error');
    }

    private function report(): ControlVisitReport
    {
        [$from, $to] = $this->period();

        if (ControlVisitReport::tooLong($from, $to)) {
            $from = $to->subMonthsNoOverflow(ControlVisitReport::MAX_MONTHS)->startOfDay();
        }

        return ControlVisitReport::build($this->company(), $this->location === '' ? null : (int) $this->location, $from, $to, $this->search);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(): array
    {
        $from = (CarbonImmutable::createFromFormat('Y-m-d', $this->from) ?: CarbonImmutable::today())->startOfDay();
        $to = (CarbonImmutable::createFromFormat('Y-m-d', $this->to) ?: CarbonImmutable::today())->endOfDay();

        return [min($from, $to->startOfDay()), max($to, $from->endOfDay())];
    }
}
