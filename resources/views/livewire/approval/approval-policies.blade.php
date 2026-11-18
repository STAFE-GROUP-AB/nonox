@php
    use App\Domain\Approval\ApprovalDimension;
    use App\Domain\Approval\ApprovalSubjectType;
    use App\Domain\Approval\ApproverKind;
    use App\Domain\Approval\SnapshotStep;
    use App\Domain\Approval\StepDescription;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $formType = ApprovalSubjectType::from($form['subject_type']);
    $delegationHelp = __('En ersättare kan godkänna de steg som pekar ut dig som person eller som ägare, medan du är borta. Beslutet sparas med "på uppdrag av". Steg som gäller alla med en behörighet kan inte lämnas över.');
    $ownerHelp = __('Ägaren kan godkänna steg av typen "Ägaren av kostnadsstället eller projektet" för underlag som bokas på det.');
@endphp

<div class="space-y-6">
    @foreach($types as $type)
        <x-ui.card :title="$type->label()" wire:key="type-{{ $type->value }}">
            @if($canManage)
                <x-slot:cta>
                    <x-button type="button" wire:click="create('{{ $type->value }}')">{{ match ($type) {
                        ApprovalSubjectType::SupplierInvoice => __('Nytt flöde för leverantörsfakturor'),
                        ApprovalSubjectType::PurchaseOrder => __('Nytt flöde för inköpsorder'),
                        ApprovalSubjectType::PurchaseRequisition => __('Nytt flöde för inköpsanmodan'),
                        ApprovalSubjectType::ManualVoucher => __('Nytt flöde för manuella verifikationer'),
                        ApprovalSubjectType::CreditInvoice => __('Nytt flöde för kreditfakturor'),
                        default => __('Nytt flöde för betalfiler'),
                    } }}</x-button>
                </x-slot:cta>
            @endif

            <ol class="divide-y divide-gray-100 text-sm">
                @foreach($policies->get($type->value, collect()) as $policy)
                    <li wire:key="policy-{{ $policy->id }}" class="py-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <span class="font-medium text-gray-900">{{ $policy->name }}
                                <span class="font-normal text-gray-500">· {{ __('ordning :priority · version :version', ['priority' => $policy->priority, 'version' => $policy->version]) }}</span>
                            </span>
                            <span class="flex items-center gap-3">
                                @unless($policy->is_active)
                                    <x-ui.badge tone="neutral">{{ __('Avstängt') }}</x-ui.badge>
                                @endunless
                                @if($canManage)
                                    <button type="button" class="font-medium text-accent-600 hover:text-accent-700" wire:click="edit({{ $policy->id }})">{{ __('Ändra') }}</button>
                                    <span x-data="{ confirming: false }" class="flex items-center gap-2">
                                        <button type="button" class="text-gray-600 underline" x-show="! confirming" x-on:click="confirming = true">{{ __('Ta bort') }}</button>
                                        <span x-show="confirming" x-cloak class="flex flex-wrap items-center gap-2">
                                            <span class="text-gray-700">{{ __('Attester som redan pågår följer flödet till slut. Nya följer nästa flöde som gäller, eller standardattesten.') }}</span>
                                            <button type="button" class="font-medium text-accent-700 underline" wire:click="delete({{ $policy->id }})">{{ __('Ja, ta bort') }}</button>
                                            <button type="button" class="text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                                        </span>
                                    </span>
                                @endif
                            </span>
                        </div>
                        <ol class="mt-2 list-decimal space-y-1 pl-6 text-gray-700">
                            @foreach($policy->steps as $step)
                                @php
                                    $snapshot = SnapshotStep::fromStep($step);
                                @endphp
                                <li>
                                    {{ __('Godkänns av :who', ['who' => StepDescription::approvers($snapshot, $memberNames)]) }}
                                    <span class="text-gray-500">· {{ __('gäller :when', ['when' => StepDescription::conditions($snapshot->conditions, $costCenterNames, $projectNames, $supplierNames)]) }}</span>
                                    @if($snapshot->excludePreparer || $company->separate_supplier_approval)
                                        <span class="text-gray-500">· {{ __('inte den som förberedde underlaget') }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </li>
                @endforeach
                <li class="py-3">
                    <span class="font-medium text-gray-900">{{ __('Standardattest') }}</span>
                    <p class="mt-1 text-gray-700">
                        {{ match ($type) {
                            ApprovalSubjectType::SupplierInvoice => __('Gäller när inget flöde ovan gäller: en person med attesträtt för leverantörsfakturor attesterar fakturan.'),
                            ApprovalSubjectType::PurchaseOrder => __('Gäller när inget flöde ovan gäller: en order över attestgränsen under Lager skickas av en person med rätt att skicka inköpsorder, och en order under gränsen av den som lägger den.'),
                            ApprovalSubjectType::PurchaseRequisition => __('Gäller när inget flöde ovan gäller: en person med rätt att skicka inköpsorder attesterar anmodan.'),
                            ApprovalSubjectType::ManualVoucher => __('Gäller när inget flöde ovan gäller: verifikationen behöver ingen attest och bokförs direkt av den som har rätt att bokföra. Med ett flöde förblir verifikationen ett utkast utan nummer tills den är attesterad, och får sitt nummer först när den bokförs.'),
                            ApprovalSubjectType::CreditInvoice => __('Gäller när inget flöde ovan gäller: kreditfakturan behöver ingen attest och skapas direkt av den som har rätt att kreditera. Med ett flöde förblir kreditfakturan ett utkast utan nummer tills den är attesterad.'),
                            default => __('Gäller när inget flöde ovan gäller: en person med rätt att godkänna betalningar godkänner filen.'),
                        } }}
                        @if($type->hasDefaultApproval())
                            {{ $type === ApprovalSubjectType::PurchaseOrder
                                ? ($company->separate_supplier_approval ? __('Den som skapade ordern får inte skicka den över attestgränsen.') : __('Den som skapade ordern får också skicka den över attestgränsen.'))
                                : ($company->separate_supplier_approval ? __('Den som förberedde underlaget får inte godkänna det.') : __('Den som förberedde underlaget får också godkänna det.')) }}
                        @endif
                    </p>
                </li>
            </ol>
        </x-ui.card>
    @endforeach

    @if($editingId !== null && $canManage)
        <x-ui.card :title="$editingId === 0 ? __('Nytt attestflöde för :type', ['type' => mb_strtolower($formType->label())]) : __('Ändra attestflöde för :type', ['type' => mb_strtolower($formType->label())])"
                   :description="__('Flödet gäller ett underlag när minst ett av stegen gäller det. Steg vars villkor inte stämmer hoppas över. Flöden prövas i ordning, lägst nummer först.')">
            <form wire:submit="save" class="space-y-6">
                <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
                    <x-ui.field for="policy_name" :label="__('Namn')" error="name">
                        <input id="policy_name" type="text" class="{{ $input }} mt-1" wire:model="form.name" maxlength="120" placeholder="{{ __('Till exempel: Fakturor över 50 000 kr') }}">
                    </x-ui.field>
                    <x-ui.field for="policy_priority" :label="__('Ordning')" error="priority">
                        <input id="policy_priority" type="text" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="form.priority">
                    </x-ui.field>
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="form.is_active">
                    {{ __('Flödet används') }}
                </label>

                @foreach($form['steps'] as $index => $step)
                    @php
                        $kind = ApproverKind::tryFrom((string) $step['approver_kind']) ?? ApproverKind::Permission;
                    @endphp
                    <fieldset wire:key="step-{{ $index }}" class="rounded-2xl border border-stone-200 p-4 sm:p-5">
                        <legend class="px-1 text-sm font-semibold text-gray-900">{{ __('Steg :number', ['number' => $index + 1]) }}</legend>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.field for="step_{{ $index }}_kind" :label="__('Vem godkänner')" error="steps.{{ $index }}.approver_kind">
                                <select id="step_{{ $index }}_kind" class="{{ $input }} mt-1" wire:model.live="form.steps.{{ $index }}.approver_kind">
                                    @foreach($formType->approverKinds() as $option)
                                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                            @if($kind === ApproverKind::Permission)
                                <x-ui.field for="step_{{ $index }}_permission" :label="__('Behörighet')" error="steps.{{ $index }}.approver_permission">
                                    <select id="step_{{ $index }}_permission" class="{{ $input }} mt-1" wire:model="form.steps.{{ $index }}.approver_permission">
                                        @foreach($formType->stepPermissions() as $permission)
                                            <option value="{{ $permission }}">{{ ucfirst(StepDescription::permission($permission)) }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                            @elseif($kind === ApproverKind::DimensionOwner)
                                <x-ui.field for="step_{{ $index }}_dimension" :label="__('Ägare av')" error="steps.{{ $index }}.approver_dimension" :hint="__('Saknar underlaget en ägare godkänner den som har attesträtt.')">
                                    <select id="step_{{ $index }}_dimension" class="{{ $input }} mt-1" wire:model="form.steps.{{ $index }}.approver_dimension">
                                        @foreach(ApprovalDimension::cases() as $dimension)
                                            <option value="{{ $dimension->value }}">{{ $dimension->label() }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                            @endif
                            @if($kind === ApproverKind::Users)
                                <fieldset class="sm:col-span-2">
                                    <legend class="text-sm font-medium text-gray-700">{{ __('Personer') }}</legend>
                                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                        @foreach($members as $member)
                                            <label class="flex items-center gap-2 text-sm text-gray-700" wire:key="step-{{ $index }}-user-{{ $member->id }}">
                                                <input type="checkbox" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="form.steps.{{ $index }}.approver_user_ids.{{ $member->id }}">
                                                {{ $member->name }}
                                            </label>
                                        @endforeach
                                    </div>
                                    <x-input-error for="steps.{{ $index }}.approver_user_ids" class="mt-2" />
                                </fieldset>
                            @endif
                            <x-ui.field for="step_{{ $index }}_count" :label="__('Antal som ska godkänna')" error="steps.{{ $index }}.required_count">
                                <input id="step_{{ $index }}_count" type="text" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="form.steps.{{ $index }}.required_count">
                            </x-ui.field>
                            <div class="sm:pt-7">
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="form.steps.{{ $index }}.exclude_preparer" @disabled($company->separate_supplier_approval)>
                                    {{ __('Den som förberedde underlaget får inte godkänna') }}
                                </label>
                                @if($company->separate_supplier_approval)
                                    <p class="mt-1 text-sm text-gray-500">{{ __('Gäller alltid, eftersom företaget kräver att någon annan attesterar.') }}</p>
                                @endif
                            </div>
                        </div>

                        <h3 class="mt-5 text-sm font-semibold text-gray-900">{{ __('Villkor (lämna tomt för alltid)') }}</h3>
                        <div class="mt-2 grid gap-4 sm:grid-cols-2">
                            <x-ui.field for="step_{{ $index }}_min" :label="__('Belopp från och med')" error="steps.{{ $index }}.min_amount">
                                <input id="step_{{ $index }}_min" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="form.steps.{{ $index }}.min_amount">
                            </x-ui.field>
                            <x-ui.field for="step_{{ $index }}_max" :label="__('Belopp till och med')" error="steps.{{ $index }}.max_amount">
                                <input id="step_{{ $index }}_max" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="form.steps.{{ $index }}.max_amount">
                            </x-ui.field>
                            @if($formType !== ApprovalSubjectType::PaymentBatch)
                                <x-ui.field for="step_{{ $index }}_cost_center" :label="__('Kostnadsställe')" error="steps.{{ $index }}.cost_center_id">
                                    <select id="step_{{ $index }}_cost_center" class="{{ $input }} mt-1" wire:model="form.steps.{{ $index }}.cost_center_id">
                                        <option value="">{{ __('Alla') }}</option>
                                        @foreach($costCenters as $costCenter)
                                            <option value="{{ $costCenter->id }}">{{ $costCenter->label() }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                                <x-ui.field for="step_{{ $index }}_project" :label="__('Projekt')" error="steps.{{ $index }}.project_id">
                                    <select id="step_{{ $index }}_project" class="{{ $input }} mt-1" wire:model="form.steps.{{ $index }}.project_id">
                                        <option value="">{{ __('Alla') }}</option>
                                        @foreach($projects as $project)
                                            <option value="{{ $project->id }}">{{ $project->label() }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                                @if(in_array('supplier_ids', $formType->conditionKeys(), true))
                                    <x-ui.field for="step_{{ $index }}_supplier" :label="__('Leverantör')" error="steps.{{ $index }}.supplier_id">
                                        <select id="step_{{ $index }}_supplier" class="{{ $input }} mt-1" wire:model="form.steps.{{ $index }}.supplier_id">
                                            <option value="">{{ __('Alla') }}</option>
                                            @foreach($suppliers as $supplier)
                                                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                            @endforeach
                                        </select>
                                    </x-ui.field>
                                @endif
                                @if($formType === ApprovalSubjectType::ManualVoucher)
                                    <p class="text-sm text-gray-500 sm:col-span-2">{{ __('Beloppet är verifikationens summa debet. Flödet gäller verifikationer som någon för in för hand, i verifikationsregistreringen, inkorgen eller API:t, och en rättelse som bokar om. Det gäller inte de som faktureringen, lönen, momsen eller skattekontot bokför, eller en rättelse som bara vänder en verifikation.') }}</p>
                                @elseif($formType === ApprovalSubjectType::CreditInvoice)
                                    <p class="text-sm text-gray-500 sm:col-span-2">{{ __('Beloppet är kreditfakturans summa med moms, utan minustecken.') }}</p>
                                @endif
                                @if($formType === ApprovalSubjectType::PurchaseOrder)
                                    <p class="text-sm text-gray-500 sm:col-span-2">{{ __('Villkor för kostnadsställe, projekt och konto gäller bara rader utan artikel. Rader med en lagerartikel har inget konto och uppfyller aldrig ett kontointervall.') }}</p>
                                @endif
                                @if($formType === ApprovalSubjectType::PurchaseRequisition)
                                    <p class="text-sm text-gray-500 sm:col-span-2">{{ __('Beloppet är den uppskattade summan exkl. moms. Har någon rad inget pris vet ingen vad anmodan kostar, så en nedre gräns (Belopp från och med) anses uppnådd och en övre gräns (Belopp till och med) anses inte uppfylld. Leverantörsvillkoret gäller bara när alla rader har samma leverantör.') }}</p>
                                @endif
                                <div class="grid grid-cols-2 gap-4">
                                    <x-ui.field for="step_{{ $index }}_account_from" :label="__('Konto från')" error="steps.{{ $index }}.account_from">
                                        <input id="step_{{ $index }}_account_from" type="text" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="form.steps.{{ $index }}.account_from" maxlength="4">
                                    </x-ui.field>
                                    <x-ui.field for="step_{{ $index }}_account_to" :label="__('Konto till')" error="steps.{{ $index }}.account_to">
                                        <input id="step_{{ $index }}_account_to" type="text" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="form.steps.{{ $index }}.account_to" maxlength="4">
                                    </x-ui.field>
                                </div>
                            @endif
                        </div>
                        @if(count($form['steps']) > 1)
                            <button type="button" class="mt-4 text-sm text-gray-600 underline" wire:click="removeStep({{ $index }})">{{ __('Ta bort steget') }}</button>
                        @endif
                    </fieldset>
                @endforeach

                <x-input-error for="steps" class="mt-2" />
                <div class="flex flex-wrap items-center gap-3">
                    <x-button wire:loading.attr="disabled">{{ __('Spara flödet') }}</x-button>
                    <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="addStep">{{ __('Lägg till steg') }}</button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Ersättare')" :description="$delegationHelp">
        @if($delegations->isNotEmpty())
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($delegations as $item)
                    <li wire:key="delegation-{{ $item->id }}" class="flex flex-wrap items-center justify-between gap-3 py-2">
                        <span>{{ __(':user ersätts av :substitute från :from till och med :to', ['user' => $memberNames[$item->user_id] ?? __('tidigare medlem'), 'substitute' => $memberNames[$item->substitute_user_id] ?? __('tidigare medlem'), 'from' => $item->starts_on->toDateString(), 'to' => $item->ends_on->toDateString()]) }}</span>
                        @if($canManage || $item->user_id === $userId)
                            <button type="button" class="text-gray-600 underline" wire:click="revokeDelegation({{ $item->id }})">{{ __('Återkalla') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <form wire:submit="addDelegation" @class(['grid gap-4 sm:grid-cols-2 lg:grid-cols-4', 'mt-4' => $delegations->isNotEmpty()])>
            @if($canManage)
                <x-ui.field for="delegation_user" :label="__('Vem ska ersättas')" error="user_id">
                    <select id="delegation_user" class="{{ $input }} mt-1" wire:model="delegation.user_id">
                        @foreach($members as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
            @endif
            <x-ui.field for="delegation_substitute" :label="__('Ersättare')" error="substitute_user_id">
                <select id="delegation_substitute" class="{{ $input }} mt-1" wire:model="delegation.substitute_user_id">
                    <option value="">{{ __('Välj person…') }}</option>
                    @foreach($members as $member)
                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="delegation_from" :label="__('Från och med')" error="starts_on">
                <input id="delegation_from" type="date" class="{{ $input }} mt-1" wire:model="delegation.starts_on">
            </x-ui.field>
            <x-ui.field for="delegation_to" :label="__('Till och med')" error="ends_on">
                <input id="delegation_to" type="date" class="{{ $input }} mt-1" wire:model="delegation.ends_on">
            </x-ui.field>
            <div class="sm:col-span-2 lg:col-span-4"><x-button wire:loading.attr="disabled">{{ __('Lägg till ersättare') }}</x-button></div>
        </form>
    </x-ui.card>

    @if($canManage && ($costCenters->isNotEmpty() || $projects->isNotEmpty()))
        <x-ui.card :title="__('Ägare av kostnadsställen och projekt')" :description="$ownerHelp">
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach([[ApprovalDimension::CostCenter, $costCenters], [ApprovalDimension::Project, $projects]] as [$dimension, $objects])
                    @foreach($objects as $object)
                        <li wire:key="owner-{{ $dimension->value }}-{{ $object->id }}" class="flex flex-wrap items-center justify-between gap-3 py-2">
                            <label for="owner_{{ $dimension->value }}_{{ $object->id }}" class="min-w-0">
                                <span class="text-gray-500">{{ $dimension->label() }}</span> <span class="font-medium text-gray-900">{{ $object->label() }}</span>
                                @unless($object->is_active)
                                    <span class="text-gray-500">({{ __('inaktivt') }})</span>
                                @endunless
                            </label>
                            <select id="owner_{{ $dimension->value }}_{{ $object->id }}" class="{{ $input }} max-w-xs" wire:change="setOwner('{{ $dimension->value }}', {{ $object->id }}, $event.target.value)">
                                <option value="">{{ __('Ingen ägare') }}</option>
                                @foreach($members as $member)
                                    <option value="{{ $member->id }}" @selected($object->owner_user_id === $member->id)>{{ $member->name }}</option>
                                @endforeach
                            </select>
                        </li>
                    @endforeach
                @endforeach
            </ul>
            <x-input-error for="owner" class="mt-2" />
        </x-ui.card>
    @endif
</div>
