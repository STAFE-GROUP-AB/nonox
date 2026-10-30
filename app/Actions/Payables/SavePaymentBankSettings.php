<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Domain\Payables\PaymentBank;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The bank that receives the company's payment files: which bank, the
 * account to pay from (IBAN) and the agreement id the bank gave for file
 * transfer.
 */
class SavePaymentBankSettings
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input): void
    {
        Gate::forUser($user)->authorize('update', $company);

        $input['iban'] = strtoupper(str_replace(' ', '', (string) ($input['iban'] ?? '')));

        $validated = Validator::make($input, [
            'payment_bank' => ['required', Rule::enum(PaymentBank::class)],
            'iban' => ['required', 'regex:/^SE\d{22}$/'],
            'bank_agreement_id' => ['required', 'string', 'max:35', 'regex:/^[A-Za-z0-9\-]+$/'],
        ], [
            'payment_bank.required' => __('Välj bank.'),
            'iban.required' => __('Skriv företagskontots IBAN. Den finns i internetbanken.'),
            'iban.regex' => __('Ett svenskt IBAN är SE följt av 22 siffror.'),
            'bank_agreement_id.required' => __('Skriv avtals-id:t för filöverföring. Banken ger dig det.'),
            'bank_agreement_id.regex' => __('Avtals-id:t får bara innehålla bokstäver, siffror och bindestreck.'),
        ])->validate();

        $bank = PaymentBank::from((string) $validated['payment_bank']);

        if ($bank->debtorAccountIsBankgiro() && blank($company->bankgiro)) {
            throw ValidationException::withMessages(['payment_bank' => __(':bank betalar från företagets bankgiro. Fyll i bankgiro under företagets uppgifter först.', ['bank' => $bank->label()])]);
        }

        $company->forceFill([
            'payment_bank' => $bank,
            'iban' => $validated['iban'],
            'bic' => $bank->bic(),
            'bank_agreement_id' => $validated['bank_agreement_id'],
        ])->save();

        Audit::record('company.payment_bank_changed', $company, [
            'payment_bank' => $bank->value,
            'iban' => $validated['iban'],
        ], $company, $user);
    }
}
