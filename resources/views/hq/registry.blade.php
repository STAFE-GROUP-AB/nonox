<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('HQ – företagsregistret')" :lead="__('Inläsningarna av företagsregistret för prospektering i CRM, och undantag efter invändningar och begäran om radering.')" />
        <livewire:hq.registry />
    </div>
    @include('auth.partials.passkey-script')
</x-app-layout>
