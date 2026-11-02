<?php

declare(strict_types=1);

namespace App\Livewire\Vat;

use App\Domain\Vat\EuSalesList;
use App\Domain\Vat\OssReport;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports for sales to other EU countries: the periodisk sammanställning
 * per month or quarter with its file for Skatteverket, and the basis for
 * the OSS return per quarter.
 */
class EuReports extends Component
{
    use ShowsToasts;

    #[Url(as: 'period', except: '')]
    public string $period = '';

    #[Url(as: 'oss', except: '')]
    public string $ossQuarter = '';

    public string $contactName = '';

    public string $phone = '';

    public string $email = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'vat:prepare'), 403);

        // The period the reports are for, so the select shows the period that is calculated, not its first option.
        $this->period = $this->selectedPeriod();
        $this->ossQuarter = $this->selectedOssQuarter();

        $this->contactName = $this->user()->name;
        $this->phone = (string) $this->company()->phone;
        $this->email = $this->user()->email;
    }

    public function download(): ?StreamedResponse
    {
        $this->resetErrorBag();
        $validated = $this->validate([
            'contactName' => ['required', 'string', 'max:35'],
            'phone' => ['required', 'string', 'max:17', 'regex:/^\+?[0-9]+(-[0-9]+)?$/'],
            'email' => ['nullable', 'email', 'max:255'],
        ], [
            'phone.regex' => __('Skriv telefonnumret med siffror och ett bindestreck efter riktnumret, till exempel 08-123456, eller med + för utlandsnummer.'),
        ], [
            'contactName' => __('namn'),
            'phone' => __('telefon'),
        ]);

        $company = $this->company();

        if (blank($company->vat_number)) {
            $this->addError('contactName', __('Fyll i företagets momsregistreringsnummer under Företagsuppgifter.'));

            return null;
        }

        [$start, $end] = self::bounds($this->selectedPeriod());
        $list = EuSalesList::for($start, $end);

        if ($list->rows === []) {
            $this->toast(__('Det finns ingen försäljning att redovisa för perioden.'));

            return null;
        }

        $contents = (string) mb_convert_encoding($list->file($company, $validated['contactName'], $validated['phone'], $validated['email'] ?: null), 'ISO-8859-1', 'UTF-8');

        Audit::record('vat.eu_sales_list_downloaded', $company, ['period' => $list->periodCode(), 'buyers' => count($list->rows)], $company, $this->user());

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, $list->filename($company), ['Content-Type' => 'text/plain; charset=ISO-8859-1']);
    }

    /** The OSS basis for the quarter as a CSV file, in kronor and euro. */
    public function downloadOss(): ?StreamedResponse
    {
        $company = $this->company();
        abort_unless($this->user()->hasTeamPermission($company, 'vat:prepare'), 403);
        [$start, $end] = self::bounds($this->selectedOssQuarter());
        $report = OssReport::for($start, $end);

        if ($report->rows === []) {
            $this->toast(__('Det finns ingen OSS-försäljning att redovisa för kvartalet.'));

            return null;
        }

        $contents = $report->csv();

        Audit::record('vat.oss_report_downloaded', $company, ['quarter' => $this->selectedOssQuarter(), 'rows' => count($report->rows), 'missing_rates' => $report->missingRates], $company, $this->user());

        $this->toast(__('Underlaget för OSS laddas ner.'));

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, "oss-{$this->selectedOssQuarter()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render(): View
    {
        [$start, $end] = self::bounds($this->selectedPeriod());
        [$ossStart, $ossEnd] = self::bounds($this->selectedOssQuarter());

        return view('livewire.vat.eu-reports', [
            'periods' => self::periods(),
            'quarters' => array_filter(self::periods(), fn (string $label, string $key): bool => str_contains($key, 'K'), ARRAY_FILTER_USE_BOTH),
            'selected' => $this->selectedPeriod(),
            'selectedOss' => $this->selectedOssQuarter(),
            'list' => EuSalesList::for($start, $end),
            'oss' => OssReport::for($ossStart, $ossEnd),
            'hasVatNumber' => filled($this->company()->vat_number),
        ]);
    }

    /**
     * The last 18 months and 6 quarters, newest first, as key => label
     * ("2026-10" => "oktober 2026", "2026-K3" => "kvartal 3 2026").
     *
     * @return array<string, string>
     */
    public static function periods(): array
    {
        $months = ['januari', 'februari', 'mars', 'april', 'maj', 'juni', 'juli', 'augusti', 'september', 'oktober', 'november', 'december'];
        $today = CarbonImmutable::today();
        $periods = [];

        for ($i = 0; $i < 18; $i++) {
            $month = $today->startOfMonth()->subMonthsNoOverflow($i);
            $periods[$month->format('Y-m')] = __($months[$month->month - 1]).' '.$month->year;
        }

        for ($i = 0; $i < 6; $i++) {
            $quarter = $today->firstOfQuarter()->subMonthsNoOverflow(3 * $i);
            $periods["{$quarter->year}-K{$quarter->quarter}"] = __('kvartal :quarter :year', ['quarter' => $quarter->quarter, 'year' => $quarter->year]);
        }

        return $periods;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function bounds(string $period): array
    {
        if (preg_match('/^(\d{4})-K([1-4])$/', $period, $match) === 1) {
            $start = CarbonImmutable::create((int) $match[1], 3 * (int) $match[2] - 2, 1) ?? CarbonImmutable::today();

            return [$start, $start->addMonthsNoOverflow(2)->endOfMonth()->startOfDay()];
        }

        $start = CarbonImmutable::createFromFormat('!Y-m', $period) ?: CarbonImmutable::today()->startOfMonth();

        return [$start, $start->endOfMonth()->startOfDay()];
    }

    private function selectedPeriod(): string
    {
        return array_key_exists($this->period, self::periods())
            ? $this->period
            : CarbonImmutable::today()->startOfMonth()->subMonthNoOverflow()->format('Y-m');
    }

    private function selectedOssQuarter(): string
    {
        $previous = CarbonImmutable::today()->firstOfQuarter()->subMonthsNoOverflow(3);

        return preg_match('/^\d{4}-K[1-4]$/', $this->ossQuarter) === 1 && array_key_exists($this->ossQuarter, self::periods())
            ? $this->ossQuarter
            : "{$previous->year}-K{$previous->quarter}";
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
