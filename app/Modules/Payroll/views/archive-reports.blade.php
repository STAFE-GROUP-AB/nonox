<x-app-layout>
    <div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Lönearkiv')" :lead="__('Lönekörningar, lönebesked och arbetsgivardeklarationer, att läsa. Arkivet finns kvar även när lönemodulen är avstängd.')" />
        <nav class="flex flex-wrap gap-2" aria-label="{{ __('Lönearkiv') }}">
            @foreach(['payroll.archive' => __('Körningar och deklarationer'), 'payroll.archive.reports' => __('Rapporter')] as $route => $label)
                <a href="{{ route($route) }}" @class(['rounded-full px-4 py-1.5 text-sm font-medium', 'bg-accent-600 text-white' => request()->routeIs($route), 'bg-white border border-gray-200 text-gray-700 hover:border-gray-300' => ! request()->routeIs($route)])>{{ $label }}</a>
            @endforeach
        </nav>
        <livewire:payroll.reports />
    </div>
</x-app-layout>
