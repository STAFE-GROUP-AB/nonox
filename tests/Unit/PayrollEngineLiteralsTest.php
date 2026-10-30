<?php

declare(strict_types=1);

/*
 * PAYROLL_MODULE.md B.17: the calculation engine holds no rates or amounts.
 * Every number in it other than 0, 1 and 100 must come from rules or units.
 */
it('has no numeric literals other than 0, 1 and 100 in the payroll engine', function () {
    $found = [];
    $directory = new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app/Modules/Payroll/Domain/Engine');

    foreach (new RecursiveIteratorIterator($directory) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_LNUMBER, T_DNUMBER], true) && ! in_array($token[1], ['0', '1', '100'], true)) {
                $found[] = basename($file->getPathname()).":{$token[2]} {$token[1]}";
            }
        }
    }

    expect($found)->toBe([]);
});
