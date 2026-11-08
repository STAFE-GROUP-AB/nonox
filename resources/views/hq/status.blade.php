<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('HQ – driftstatus')" :lead="__('Händelserna på den publika statussidan: störningar, avbrott och planerat underhåll, med historik. Resten av sidan, som jobb och kopplingar, räknas fram av systemet.')" />
        <p class="text-sm"><a class="text-accent-700 underline" href="{{ route('status') }}">{{ __('Visa den publika sidan') }}</a></p>
        <livewire:hq.status-incidents />
    </div>
</x-app-layout>
