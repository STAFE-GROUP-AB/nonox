<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('HQ – inställningar')" :lead="__('Installationens egna inställningar: vilket företag som driver tjänsten, var kunderna och köanmälningarna hamnar, och om kön och självregistreringen är öppna.')" />
        <livewire:hq.settings />
    </div>
    @include('auth.partials.passkey-script')
</x-app-layout>
