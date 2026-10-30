<nav class="flex flex-wrap gap-2 print:hidden" aria-label="{{ __('Lön') }}">
    @php($pendingRequests = App\Modules\Payroll\Models\Absence::query()->where('status', 'REQUESTED')->count() + App\Modules\Payroll\Models\Expense::query()->where('status', 'SUBMITTED')->count())
    @foreach(['payroll.runs.index' => __('Lönekörningar'), 'payroll.requests' => $pendingRequests > 0 ? __('Ansökningar (:count)', ['count' => $pendingRequests]) : __('Ansökningar'), 'payroll.employees' => __('Anställda'), 'payroll.agi' => __('Arbetsgivardeklaration'), 'payroll.reports' => __('Rapporter'), 'payroll.vacationYear' => __('Semesterår'), 'payroll.vacationLiability' => __('Semesterskuld'), 'payroll.rules' => __('Regler'), 'payroll.migration' => __('Flytt av lönedata'), 'payroll.index' => __('Kom igång')] as $route => $label)
        @php($active = request()->routeIs($route === 'payroll.runs.index' ? 'payroll.runs.*' : $route))
        <a href="{{ route($route) }}" @class([
            'rounded-full px-4 py-2 text-sm font-semibold transition',
            'bg-ink text-white' => $active,
            'bg-stone-200/60 text-gray-800 hover:bg-stone-200' => ! $active,
        ]) @if($active) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
