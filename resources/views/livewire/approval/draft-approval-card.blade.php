@php
    use App\Domain\Approval\DraftApprovalStatus;
@endphp

<div data-draft-approval>
    @if($visible && $changed)
        <x-ui.card :title="__('Attest')">
            <p class="text-sm text-gray-700" role="status">{{ __('Utkastet har sparats sedan du öppnade sidan. Ladda om sidan för att se attesten för den nya versionen.') }}</p>
        </x-ui.card>
    @elseif($visible)
        @include('livewire.approval.partials.approval-flow', ['approval' => $approval, 'memberNames' => $memberNames, 'canResubmit' => $canResubmit])

        <x-ui.card :title="__('Attest')">
            <p class="text-sm text-gray-700">
                @switch($status)
                    @case(DraftApprovalStatus::Required)
                        {{ $isVoucher
                            ? __('Ett attestflöde gäller verifikationen. Den förblir ett utkast utan nummer tills den är attesterad, och får sitt nummer när den bokförs.')
                            : __('Ett attestflöde gäller kreditfakturan. Den förblir ett utkast utan nummer tills den är attesterad.') }}
                        @break
                    @case(DraftApprovalStatus::Pending)
                        {{ $isVoucher ? __('Verifikationen väntar på attest.') : __('Kreditfakturan väntar på attest.') }}
                        {{ __('Ändras utkastet behöver den nya versionen attesteras på nytt.') }}
                        @break
                    @case(DraftApprovalStatus::Approved)
                        {{ $isVoucher
                            ? __('Verifikationen är attesterad och kan bokföras. Ändras den behöver den attesteras på nytt.')
                            : __('Kreditfakturan är attesterad och kan skapas i redigeringen. Ändras den behöver den attesteras på nytt.') }}
                        @break
                    @case(DraftApprovalStatus::Rejected)
                        {{ $isVoucher ? __('Verifikationen avvisades. Ändra den och bokför den igen, eller skicka samma version till attest igen.') : __('Kreditfakturan avvisades. Ändra den och skapa den igen, eller skicka samma version till attest igen.') }}
                        @break
                @endswitch
            </p>

            @if($canApprove && $approval->eligibility->onBehalfOf)
                <p class="mt-2 text-sm text-gray-700">{{ __('Du godkänner på uppdrag av :name.', ['name' => $memberNames[$approval->eligibility->onBehalfOf] ?? __('tidigare medlem')]) }}</p>
            @endif

            <div class="mt-3 flex flex-wrap items-start gap-3">
                @if($canSubmit)
                    <x-button type="button" wire:click="submit" wire:loading.attr="disabled">{{ __('Skicka till attest') }}</x-button>
                @endif
                @if($canApprove)
                    <x-button type="button" wire:click="approve" wire:loading.attr="disabled">{{ $approval->stepCount() > 1 ? __('Godkänn steg :step av :steps', ['step' => $approval->currentStep(), 'steps' => $approval->stepCount()]) : ($isVoucher ? __('Attestera verifikationen') : __('Attestera kreditfakturan')) }}</x-button>
                @endif
                @if($canPost)
                    <span x-data="{ confirming: false }" class="flex flex-col gap-2">
                        <x-button type="button" x-show="! confirming" x-on:click="confirming = true">{{ __('Bokför') }}</x-button>
                        <span x-show="confirming" x-cloak class="flex flex-col gap-2">
                            <span class="text-sm text-gray-700">{{ __('Verifikationen får nästa nummer i serien och kan sedan inte ändras eller tas bort, bara rättas med en ny verifikation.') }}</span>
                            <span class="flex items-center gap-3">
                                <x-button type="button" wire:click="post" wire:loading.attr="disabled">{{ __('Ja, bokför') }}</x-button>
                                <button type="button" class="text-sm text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                            </span>
                        </span>
                    </span>
                @endif
                @if($canReject)
                    <span x-data="{ rejecting: false }" class="flex flex-col gap-2">
                        <button type="button" class="text-sm text-gray-600 underline" x-show="! rejecting" x-on:click="rejecting = true">{{ __('Avvisa') }}</button>
                        <span x-show="rejecting" x-cloak class="flex flex-col gap-2">
                            <label for="draft_reject_reason" class="text-sm font-medium text-gray-700">{{ __('Skäl (frivilligt)') }}</label>
                            <textarea id="draft_reject_reason" rows="2" maxlength="500" class="block w-full rounded-lg border-gray-300 shadow-xs focus:border-accent-500 focus:ring-accent-500" wire:model="rejectReason" aria-describedby="draft_reject_reason-hint"></textarea>
                            <span id="draft_reject_reason-hint" class="text-sm text-gray-500">{{ __('Skriv vad som är fel med underlaget, inte uppgifter om personer.') }}</span>
                            <span class="text-sm text-gray-700">{{ __('Utkastet går tillbaka till den som förberedde det. Inget bokförs och inget nummer tas.') }}</span>
                            <span class="flex items-center gap-3">
                                <button type="button" class="text-sm font-medium text-accent-700 underline" wire:click="reject">{{ __('Ja, avvisa') }}</button>
                                <button type="button" class="text-sm text-gray-600 underline" x-on:click="rejecting = false">{{ __('Avbryt') }}</button>
                            </span>
                        </span>
                    </span>
                @endif
            </div>
            <x-input-error for="approve" class="mt-2" />
            <x-input-error for="reason" class="mt-2" />
            <x-input-error for="voucher" class="mt-2" />
        </x-ui.card>
    @endif
</div>
