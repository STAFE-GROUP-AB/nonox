<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div>
        <div class="w-full py-6 px-4 sm:px-6 lg:px-10">
            @if (Laravel\Fortify\Features::canUpdateProfileInformation())
                @livewire('profile.update-profile-information-form')

                <x-section-border />
            @endif

            @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::updatePasswords()))
                <div class="mt-10 sm:mt-0">
                    @livewire('profile.update-password-form')
                </div>

                <x-section-border />
            @endif

            @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                <div class="mt-10 sm:mt-0">
                    @livewire('profile.two-factor-authentication-form')
                </div>

                <x-section-border />
            @endif

            <div class="mt-10 sm:mt-0">
                <livewire:profile.sign-out-everywhere />
            </div>

            <x-section-border />

            <div class="mt-10 sm:mt-0">
                <x-action-section>
                    <x-slot name="title">{{ __('Mina uppgifter') }}</x-slot>
                    <x-slot name="description">{{ __('Ladda ner allt NONOX har om ditt konto, i ett maskinläsbart format.') }}</x-slot>
                    <x-slot name="content">
                        <p class="text-sm text-gray-600">{{ __('Utdraget innehåller ditt konto, dina företag och roller, din behandlingshistorik och, om du är anställd, dina löneuppgifter. Uppgifter som ett företag har om dig som kund eller leverantör begär du ut hos det företaget.') }} <a href="{{ route('legal.privacy') }}" class="underline">{{ __('Integritetspolicy') }}</a></p>
                        <a href="{{ route('privacy.export.me') }}" class="mt-4 inline-flex items-center rounded-md bg-gray-800 px-4 py-2 max-sm:min-h-11 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">{{ __('Ladda ner mina uppgifter') }}</a>
                    </x-slot>
                </x-action-section>
            </div>

            <x-section-border />

            <div class="mt-10 sm:mt-0">
                <livewire:profile.delete-account />
            </div>
        </div>
    </div>
</x-app-layout>
