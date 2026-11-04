<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <p class="text-sm"><a href="{{ route('crm.call-lists') }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Alla ringlistor') }}</a></p>
        <livewire:crm.call-list :call-list-id="$callListId" />
    </div>
</x-app-layout>
