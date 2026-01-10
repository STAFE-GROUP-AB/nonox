<?php

namespace App\Services;

/**
 * Service för validering av bokföringsverifikationer.
 * Ansvarar för att säkerställa att dubbel bokföring (debit = credit) följs.
 */
class VoucherValidationService
{
    /**
     * Validera att en verifikation följer dubbel bokföring.
     * Debit måste vara lika med credit.
     *
     * @param array $lines Array av verifikationsrader med 'debit' och 'credit'
     * @return bool
     */
    public function validateDoubleEntry(array $lines): bool
    {
        $debitSum = 0;
        $creditSum = 0;

        foreach ($lines as $line) {
            $debitSum += $line['debit'] ?? 0;
            $creditSum += $line['credit'] ?? 0;
        }

        // Använd round för att hantera flyttalsavrundning
        return round($debitSum, 2) === round($creditSum, 2);
    }

    /**
     * Beräkna differensen mellan debit och credit.
     * Returnerar 0 om verifikationen är balanserad.
     *
     * @param array $lines Array av verifikationsrader
     * @return float
     */
    public function calculateDifference(array $lines): float
    {
        $debitSum = 0;
        $creditSum = 0;

        foreach ($lines as $line) {
            $debitSum += $line['debit'] ?? 0;
            $creditSum += $line['credit'] ?? 0;
        }

        return round($debitSum - $creditSum, 2);
    }
}
