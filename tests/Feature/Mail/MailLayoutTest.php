<?php

declare(strict_types=1);

use App\Mail\LoginCodeMail;

it('shows the NONOX logo as PNG and a footer without the English default text', function () {
    $html = (new LoginCodeMail('12345678'))->render();

    expect($html)->toContain(asset('brand/nonox-mail.png'))
        ->toContain('alt="'.config('app.name').'"')
        ->not->toContain('All rights reserved')
        ->not->toContain('.svg')
        ->and(public_path('brand/nonox-mail.png'))->toBeFile();
});

it('uses the NONOX accent for buttons instead of the default near-black', function () {
    $css = (string) file_get_contents(resource_path('views/vendor/mail/html/themes/default.css'));

    expect($css)->toMatch('/\.button-primary \{[^}]*#d4330b/');
});
