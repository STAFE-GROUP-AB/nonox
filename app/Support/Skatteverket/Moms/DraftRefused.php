<?php

declare(strict_types=1);

namespace App\Support\Skatteverket\Moms;

use RuntimeException;

/**
 * Skatteverket did not save the draft because its check found errors that
 * stop it. Carries the check, so the user sees what each error means
 * (Allmänna villkor API Momsdeklaration 5 b).
 */
final class DraftRefused extends RuntimeException
{
    /**
     * @param  array{status: string, resultat: list<array{status: string, kod: string, beskrivning: string}>}  $check
     */
    public function __construct(public readonly array $check)
    {
        parent::__construct('Skatteverket did not save the VAT draft.');
    }
}
