{{--
    What the company register says about a company (ADR-047): status, form,
    industry, registration and size, with the opt-outs and the source. Give
    either the register row (company) or an organisation number; nothing is
    shown when the company is not in the register. One query at most.
--}}
@props(['company' => null, 'orgNumber' => null])

@php
    $company ??= $orgNumber ? App\Models\RegistryCompany::query()->where('org_number', App\Models\RegistryCompany::digits($orgNumber) ?? '')->first() : null;
    $codes = App\Support\CompanyRegistry\RegistryCodes::class;
    $status = match (true) {
        $company === null => null,
        $company->proceedings !== null => trim($codes::proceedings($company->proceedings).' '.($company->proceedings_since ? __('sedan :date', ['date' => $company->proceedings_since->translatedFormat('j M Y')]) : '')),
        $company->deregistered_on !== null => __('Avregistrerat :date', ['date' => $company->deregistered_on->translatedFormat('j M Y')]),
        $company->bolagsverket_status !== null && $company->bolagsverket_status !== 0 => $codes::bolagsverketStatus($company->bolagsverket_status),
        $company->gone_at !== null => __('Finns inte längre i registret'),
        $company->scb_status === 1 => __('I drift'),
        default => __('Inte i drift (inte registrerat för moms, F-skatt eller som arbetsgivare)'),
    };
@endphp

@if($company)
    <x-ui.card :title="__('I företagsregistret')" data-testid="registry-facts">
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt class="text-gray-600">{{ __('Status') }}</dt>
            <dd @class(['font-medium', 'text-accent-800' => ! $company->isInBusiness(), 'text-ink' => $company->isInBusiness()])>{{ $status }}</dd>
            <dt class="text-gray-600">{{ __('Bolagsform') }}</dt>
            <dd class="text-ink">{{ $codes::legalForm($company->legal_form) ?? '–' }}</dd>
            <dt class="text-gray-600">{{ __('Bransch') }}</dt>
            <dd class="text-ink">{{ App\Support\CompanyRegistry\Sni::label($company->sni_code) ?? '–' }}</dd>
            @if($company->registered_on)
                <dt class="text-gray-600">{{ __('Registrerat') }}</dt>
                <dd class="text-ink">{{ $company->registered_on->translatedFormat('j M Y') }}</dd>
            @endif
            @if($company->employee_class !== null)
                <dt class="text-gray-600">{{ __('Anställda') }}</dt>
                <dd class="text-ink">{{ $codes::employees($company->employee_class) }}</dd>
            @endif
            @if($company->turnover_class !== null)
                <dt class="text-gray-600">{{ __('Omsättning') }}</dt>
                <dd class="text-ink">{{ $codes::turnover($company->turnover_class) }}@if($company->turnover_year) ({{ $company->turnover_year }})@endif</dd>
            @endif
            @if($company->county_code)
                <dt class="text-gray-600">{{ __('Län') }}</dt>
                <dd class="text-ink">{{ $codes::county($company->county_code) }}</dd>
            @endif
        </dl>
        @if($company->advertising_block || $company->hasPhoneBlock() || $company->email_block)
            <p class="mt-3 flex flex-wrap gap-2">
                @if($company->advertising_block)<x-ui.badge>{{ __('Reklamspärr') }}</x-ui.badge>@endif
                @if($company->hasPhoneBlock())<x-ui.badge>{{ __('Spärr mot telefonförsäljning') }}</x-ui.badge>@endif
                @if($company->email_block)<x-ui.badge>{{ __('Spärr mot reklam via e-post') }}</x-ui.badge>@endif
            </p>
        @endif
        <p class="mt-3 text-xs text-gray-500">{{ __('Källa: SCB:s företagsregister och Bolagsverket, öppna data, hämtat :date. Betalningsanmärkningar, kreditbetyg och styrelse kräver en kreditupplysningstjänst och visas inte här.', ['date' => ($company->bolagsverket_fetched_at ?? $company->fetched_at)?->setTimezone('Europe/Stockholm')->translatedFormat('j M Y') ?? '–']) }}</p>
    </x-ui.card>
@endif
