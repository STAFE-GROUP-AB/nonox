<nav class="flex flex-wrap gap-2 print:hidden" aria-label="{{ __('Lön') }}">
    @foreach(['payroll.runs.index' => __('Lönekörningar'), 'payroll.employees' => __('Anställda'), 'payroll.rules' => __('Regler'), 'payroll.index' => __('Kom igång')] as $route => $label)
        @php($active = request()->routeIs($route === 'payroll.runs.index' ? 'payroll.runs.*' : $route))
        <a href="{{ route($route) }}" @class([
            'rounded-full px-4 py-1.5 text-sm font-medium',
            'bg-accent-600 text-white' => $active,
            'bg-white border border-gray-200 text-gray-700 hover:border-gray-300' => ! $active,
        ]) @if($active) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
