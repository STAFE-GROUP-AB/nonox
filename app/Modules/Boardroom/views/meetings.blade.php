<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Styrelserum')" :lead="__('Årsstämma, extra bolagsstämma och styrelsemöten med dagordning, kallelse och protokoll. NONOX påminner om det som ska anmälas till Bolagsverket, men skickar ingenting dit.')" />
        <livewire:boardroom.meetings />
    </div>
</x-app-layout>
