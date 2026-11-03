{{-- With CRM on (ADR-043), Kunder is the CRM's list for those who may see it; otherwise the plain register. --}}
@php($crm = app(App\Modules\ModuleManager::class)->isEnabled(App\Modules\Crm\CrmModule::KEY) && auth()->user()->hasTeamPermission(auth()->user()->currentCompany(), 'crm:view'))
<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        @if($crm)
            <x-ui.page-heading :title="__('Kunder')" :lead="__('Kunder och prospekt, med nästa steg och vad de är värda i bokföringen.')" />
            <livewire:crm.accounts />
        @else
            <x-ui.page-heading :title="__('Kunder')" :lead="__('Dem du fakturerar.')" />
            <livewire:invoicing.customers />
        @endif
    </div>
</x-app-layout>
