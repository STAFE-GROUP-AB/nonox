<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Löneunderlag: :file', ['file' => $import->original_name])" :lead="__('Kontrollera raderna och godkänn dem. Godkända rader läggs in i :run, som sedan beräknas igen.', ['run' => $run->title()])">
            <x-slot:action>
                <a href="{{ route('payroll.runs.show', $run) }}" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Tillbaka till :run', ['run' => $run->title()]) }}</a>
            </x-slot:action>
        </x-ui.page-heading>
        <livewire:payroll.file-import :run="$run" :import="$import" />
    </div>
</x-app-layout>
