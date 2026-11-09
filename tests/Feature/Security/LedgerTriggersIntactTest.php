<?php

declare(strict_types=1);

use Tests\Support\LedgerTriggers;

/*
 * The immutability triggers on the ledger and their functions are in place,
 * enabled and unchanged. TriggerBypassGapTest disables, drops and replaces
 * them inside rolled-back transactions and checks the committed state after
 * each of its tests; this file checks it once more on its own.
 */

it('keeps the immutability triggers enabled and their functions intact', function () {
    expect(LedgerTriggers::state())->toBe(LedgerTriggers::expected());
});

it('reads the same committed state on a second connection', function () {
    expect(LedgerTriggers::committedState())->toBe(LedgerTriggers::expected());
});
