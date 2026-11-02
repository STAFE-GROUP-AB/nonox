<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('HQ – kön')" :lead="__('Anmälningarna till den öppna betan. Godkänner du någon skapas ett konto och personen får ett mejl om att logga in.')" />
        <livewire:hq.waitlist-queue />
    </div>
    @include('auth.partials.passkey-script')
</x-app-layout>
