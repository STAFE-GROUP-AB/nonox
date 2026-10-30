<x-app-layout>
    <div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Lönearkiv')" :lead="__('Lönekörningar, lönebesked och arbetsgivardeklarationer, att läsa. Arkivet finns kvar även när lönemodulen är avstängd.')" />
        <nav class="flex flex-wrap gap-2" aria-label="{{ __('Lönearkiv') }}">
            @foreach(['payroll.archive' => __('Körningar och deklarationer'), 'payroll.archive.reports' => __('Rapporter')] as $route => $label)
                <a href="{{ route($route) }}" @class(['rounded-full px-4 py-2 text-sm font-semibold transition', 'bg-ink text-white' => request()->routeIs($route), 'bg-stone-200/60 text-gray-800 hover:bg-stone-200' => ! request()->routeIs($route)])>{{ $label }}</a>
            @endforeach
        </nav>
        <livewire:payroll.reports />
    </div>
</x-app-layout>
