<nav class="flex flex-wrap gap-2 print:hidden" aria-label="{{ __('Lön') }}">
    @php($pendingRequests = App\Modules\Payroll\Models\Absence::query()->where('status', 'REQUESTED')->count() + App\Modules\Payroll\Models\Expense::query()->where('status', 'SUBMITTED')->count())
    @foreach(['payroll.runs.index' => __('Lönekörningar'), 'payroll.requests' => $pendingRequests > 0 ? __('Ansökningar (:count)', ['count' => $pendingRequests]) : __('Ansökningar'), 'payroll.employees' => __('Anställda'), 'payroll.vacationYear' => __('Semesterår'), 'payroll.vacationLiability' => __('Semesterskuld'), 'payroll.rules' => __('Regler'), 'payroll.index' => __('Kom igång')] as $route => $label)
        @php($active = request()->routeIs($route === 'payroll.runs.index' ? 'payroll.runs.*' : $route))
        <a href="{{ route($route) }}" @class([
            'rounded-full px-4 py-1.5 text-sm font-medium',
            'bg-accent-600 text-white' => $active,
            'bg-white border border-gray-200 text-gray-700 hover:border-gray-300' => ! $active,
        ]) @if($active) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
