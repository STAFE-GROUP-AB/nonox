<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <a href="{{ route('hq.customers') }}" class="text-sm font-medium text-accent-600 hover:text-accent-700">← {{ __('Tillbaka till kunder') }}</a>
        <livewire:hq.customer-company :company-id="$companyId" />
    </div>
</x-app-layout>
