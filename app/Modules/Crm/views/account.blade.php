<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <p class="text-sm"><a href="{{ route('invoicing.customers') }}" class="inline-flex min-h-11 items-center font-medium text-accent-600 hover:text-accent-700 sm:min-h-0">← {{ __('Kunder') }}</a></p>
        <livewire:crm.account-card :account-id="$accountId" />
    </div>
</x-app-layout>
