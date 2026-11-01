<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="$import->original_name" :lead="__('Rapport över SIE-filen')" />
        <livewire:accounting.sie-import-report :sie-import="$import" />
        <a href="{{ route('accounting.sieImports.index') }}" class="inline-block text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Tillbaka till SIE-filer') }}</a>
    </div>
</x-app-layout>
