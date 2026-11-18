{{--
    The default cost centre and project of an article or a customer (ADR-081
    gap 6), by code. Shown only when the company has cost centres or
    projects. Expects $dimensionChoices (ChoosesDimensions), $model (the
    Livewire array, e.g. "form"), $values (its current values), $idPrefix
    and optionally $fieldClass (the grid span).
--}}
@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $costCenters = $dimensionChoices['costCenters'];
    $projects = $dimensionChoices['projects'];
    $currentCostCenter = (string) ($values['default_cost_center'] ?? '');
    $currentProject = (string) ($values['default_project'] ?? '');
@endphp

@if($costCenters->isNotEmpty() || $projects->isNotEmpty() || $currentCostCenter !== '' || $currentProject !== '')
    <x-ui.field :for="$idPrefix.'_default_cost_center'" :label="__('Kostnadsställe på nya rader')" error="default_cost_center" class="{{ $fieldClass ?? '' }}">
        <select id="{{ $idPrefix }}_default_cost_center" class="{{ $input }} mt-1" wire:model="{{ $model }}.default_cost_center">
            <option value="">{{ __('Inget') }}</option>
            @if($currentCostCenter !== '' && ! $costCenters->contains('code', $currentCostCenter))
                <option value="{{ $currentCostCenter }}">{{ $currentCostCenter }} ({{ __('inaktivt') }})</option>
            @endif
            @foreach($costCenters as $costCenter)
                <option value="{{ $costCenter->code }}">{{ $costCenter->label() }}</option>
            @endforeach
        </select>
    </x-ui.field>
    <x-ui.field :for="$idPrefix.'_default_project'" :label="__('Projekt på nya rader')" error="default_project" class="{{ $fieldClass ?? '' }}">
        <select id="{{ $idPrefix }}_default_project" class="{{ $input }} mt-1" wire:model="{{ $model }}.default_project">
            <option value="">{{ __('Inget') }}</option>
            @if($currentProject !== '' && ! $projects->contains('code', $currentProject))
                <option value="{{ $currentProject }}">{{ $currentProject }} ({{ __('inaktivt') }})</option>
            @endif
            @foreach($projects as $project)
                <option value="{{ $project->code }}">{{ $project->label() }}</option>
            @endforeach
        </select>
    </x-ui.field>
@endif
