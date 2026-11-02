@php($planner = auth()->user())
@php($company = $planner->currentCompany())
<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Inställningar för planering')" :lead="__('Avdelningar och kompetenser att planera i, arbetstidsregler, aviseringar, attest och vilka konton som räknas som försäljning.')" />
        <livewire:planning.setup />
        @if($planner->hasTeamPermission($company, 'planning:manage'))
            <x-ui.card :title="__('Regler, aviseringar och lön')" :description="__('Gäller hela företaget. Att slå på eller av Planering gör ägaren under Moduler.')">
                <livewire:planning.settings-form :company="$company" />
            </x-ui.card>
        @endif
    </div>
</x-app-layout>
