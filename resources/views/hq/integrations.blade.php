<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('HQ – integrationer')" :lead="__('Hur bankkopplingen och Peppol mår på hela installationen: om de är inställda, om de är pausade och var något väntar. Bara antal, aldrig uppgifter om ett företag.')" />
        <livewire:hq.integrations />
    </div>
</x-app-layout>
