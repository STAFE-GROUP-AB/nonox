<x-app-layout>
    <div class="w-full space-y-6 px-4 pb-28 pt-6 sm:px-6 sm:pb-6 lg:px-10">
        <p class="text-sm"><a href="{{ route('crm.people') }}" class="inline-flex min-h-11 items-center font-medium text-accent-600 hover:text-accent-700 sm:min-h-0">← {{ __('Personer') }}</a></p>
        <livewire:crm.person-card :person-id="$personId" />
    </div>
</x-app-layout>
