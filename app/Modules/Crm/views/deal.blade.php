<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <p class="text-sm"><a href="{{ route('crm.deals') }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Alla affärer') }}</a></p>
        <livewire:crm.deal-card :deal-id="$dealId" />
    </div>
</x-app-layout>
