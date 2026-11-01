<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Lönearkiv')" :lead="__('Lönekörningar, lönebesked och arbetsgivardeklarationer, att läsa. Arkivet finns kvar även när lönemodulen är avstängd.')" />
        <livewire:payroll.archive />
    </div>
</x-app-layout>
