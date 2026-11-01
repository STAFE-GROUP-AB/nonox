<x-start-layout :title="__('Anslut app')">
    <livewire:connect.consent-screen :auth-token="$authToken" />
    @include('auth.partials.passkey-script')
</x-start-layout>
