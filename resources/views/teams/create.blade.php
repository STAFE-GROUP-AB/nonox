<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Nytt företag') }}
        </h2>
    </x-slot>

    <div class="max-w-3xl py-10 px-4 sm:px-6 lg:px-10">
        <livewire:company.create-company-form />
    </div>
</x-app-layout>
