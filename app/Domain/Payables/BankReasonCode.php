<?php

declare(strict_types=1);

namespace App\Domain\Payables;

/**
 * Why the bank rejected or returned a payment, in plain Swedish. The codes
 * are ISO 20022's external code sets ExternalStatusReason1Code (pain.002)
 * and ExternalReturnReason1Code (camt.054 returns), published by ISO 20022
 * Registration Authority (iso20022.org, "External Code Sets", read
 * 2026-11-02); the wording is our own. A code not listed here is shown with
 * the bank's own text.
 */
final class BankReasonCode
{
    private const array REASONS = [
        'AC01' => 'Kontonumret är fel.',
        'AC02' => 'Företagets eget konto i filen är fel.',
        'AC03' => 'Mottagarens konto är fel.',
        'AC04' => 'Mottagarens konto är avslutat.',
        'AC06' => 'Kontot är spärrat.',
        'AG01' => 'Betalningen är inte tillåten på kontot.',
        'AG02' => 'Betalningstypen är fel.',
        'AM01' => 'Beloppet är noll.',
        'AM02' => 'Beloppet är inte tillåtet.',
        'AM04' => 'Det finns inte tillräckligt med pengar på kontot.',
        'AM05' => 'Betalningen är en dubblett.',
        'AM09' => 'Beloppet är fel.',
        'BE05' => 'Banken känner inte igen avsändaren av filen.',
        'CUST' => 'Betalningen stoppades på företagets begäran.',
        'DT01' => 'Datumet är fel.',
        'DUPL' => 'Betalningen är en dubblett.',
        'FF01' => 'Filen har fel format.',
        'MS02' => 'Mottagaren gav ingen orsak.',
        'MS03' => 'Banken gav ingen orsak.',
        'NARR' => 'Se bankens förklaring.',
        'RC01' => 'Bankens identitet (BIC eller clearingnummer) är fel.',
        'RF01' => 'Betalningens referens är inte unik.',
        'TM01' => 'Filen kom in efter bankens sista tid för dagen.',
    ];

    /** "Mottagarens konto är fel. (AC03: Beneficiary account number is incorrect)" */
    public static function describe(?string $code, ?string $bankText): string
    {
        $code = $code === null ? null : strtoupper(trim($code));
        $known = $code === null ? null : (self::REASONS[$code] ?? null);
        $detail = trim(implode(': ', array_filter([$code, $bankText === null ? null : trim($bankText)])));

        if ($known === null) {
            return $detail === '' ? __('Banken gav ingen orsak.') : __('Bankens orsak: :detail', ['detail' => $detail]);
        }

        $text = __($known);

        return (is_string($text) ? $text : $known).' ('.$detail.')';
    }
}
