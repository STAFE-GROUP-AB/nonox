<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Actions\MovePayrollData;
use App\Modules\Payroll\Models\ImportedPayslip;
use App\Modules\Payroll\Models\PayrollMigration;
use App\Modules\Payroll\Models\VacationOpeningBalance;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Flytt av lönedata: the steps of moving payroll from an earlier system,
 * each read from a CSV file, the old payslips as PDF, and the check against
 * the bookkeeping that completes it (PAYROLL_MODULE.md B.16).
 */
class PayrollMigrationPage extends Component
{
    use ShowsToasts;
    use WithFileUploads;

    private const int MAX_KILOBYTES = 10240;

    public string $year = '';

    /** @var ?UploadedFile */
    public $employeesFile = null;

    /** @var ?UploadedFile */
    public $yearToDateFile = null;

    /** @var ?UploadedFile */
    public $vacationFile = null;

    /** @var list<UploadedFile> */
    public $payslipFiles = [];

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'payroll:manage'), 403);
        $this->year = (string) CarbonImmutable::today()->year;
    }

    public function start(MovePayrollData $move): void
    {
        $this->validate(['year' => ['required', 'integer', 'between:2000,2100']], attributes: ['year' => __('år')]);
        $move->start($this->user(), $this->company(), (int) $this->year);
        $this->toast(__('Flytten av lönedata är startad. Lönekörningar kan inte räknas förrän den är klar.'));
    }

    public function importEmployees(MovePayrollData $move): void
    {
        $count = $move->employees($this->user(), $this->company(), $this->read('employeesFile'));
        $this->reset('employeesFile');
        $this->toast(__(':count anställda är inlästa.', ['count' => $count]));
    }

    public function importYearToDate(MovePayrollData $move): void
    {
        $count = $move->yearToDate($this->user(), $this->company(), $this->read('yearToDateFile'));
        $this->reset('yearToDateFile');
        $this->toast(__('Beloppen hittills i år är inlästa för :count anställda.', ['count' => $count]));
    }

    public function importVacation(MovePayrollData $move): void
    {
        $count = $move->vacation($this->user(), $this->company(), $this->read('vacationFile'));
        $this->reset('vacationFile');
        $this->toast(__('Semestersaldona är inlästa för :count anställda.', ['count' => $count]));
    }

    public function importPayslips(MovePayrollData $move): void
    {
        $this->validate(['payslipFiles' => ['required', 'array'], 'payslipFiles.*' => ['file', 'mimes:pdf', 'max:'.self::MAX_KILOBYTES]], attributes: ['payslipFiles' => __('lönebesked')]);
        $count = $move->payslips($this->user(), $this->company(), $this->payslipFiles);
        $this->reset('payslipFiles');
        $this->toast(__(':count lönebesked är sparade.', ['count' => $count]));
    }

    public function complete(MovePayrollData $move): void
    {
        $move->complete($this->user(), $this->company());
        $this->toast(__('Flytten av lönedata är klar. Nu kan lönen räknas här.'));
    }

    public function render(MovePayrollData $move): View
    {
        $migration = PayrollMigration::query()->first();

        return view('payroll::livewire.migration', [
            'migration' => $migration,
            'reconciliation' => isset($migration?->report['year_to_date']) ? $move->reconciliation($this->company()) : null,
            'vacationCount' => VacationOpeningBalance::query()->count(),
            'payslipCount' => ImportedPayslip::query()->count(),
        ]);
    }

    private function read(string $property): string
    {
        $this->validate([$property => ['required', 'file', 'mimes:csv,txt', 'max:'.self::MAX_KILOBYTES]], attributes: [$property => __('fil')]);

        /** @var UploadedFile $file */
        $file = $this->{$property};

        return (string) file_get_contents($file->getRealPath());
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        return $this->user()->currentCompany() ?? abort(404);
    }
}
