<?php

declare(strict_types=1);

use App\Mail\BrandedMail;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->body = tempnam(sys_get_temp_dir(), 'mail');
    file_put_contents($this->body, "Etapp 2 är klar.\n\n- Mina datum");
});

afterEach(function () {
    @unlink($this->body);
});

it('sends the mail with the reply-to address as a copy', function () {
    $this->artisan('mail:send', [
        '--to' => ['anna@example.com'],
        '--subject' => 'Klart',
        '--heading' => 'Hej Anna!',
        '--reply-to' => 'svar@example.com',
        '--body' => $this->body,
    ])->assertSuccessful();

    Mail::assertSent(BrandedMail::class, fn (BrandedMail $mail): bool => $mail->hasTo('anna@example.com')
        && $mail->hasCc('svar@example.com')
        && $mail->hasReplyTo('svar@example.com')
        && $mail->subjectLine === 'Klart');
});

it('sends no copy with --no-copy, nor when the reply-to address already receives it', function () {
    $this->artisan('mail:send', ['--to' => ['svar@example.com'], '--subject' => 'A', '--heading' => 'H', '--reply-to' => 'svar@example.com', '--body' => $this->body])->assertSuccessful();
    $this->artisan('mail:send', ['--to' => ['anna@example.com'], '--subject' => 'B', '--heading' => 'H', '--reply-to' => 'svar@example.com', '--body' => $this->body, '--no-copy' => true])->assertSuccessful();

    Mail::assertSent(BrandedMail::class, fn (BrandedMail $mail): bool => $mail->cc === []);
    Mail::assertSentCount(2);
});

it('renders without sending, with the sender and reply line under the body', function () {
    config(['mail.from.address' => 'hej@example.com', 'app.name' => 'NONOX']);

    $this->artisan('mail:send', ['--subject' => 'A', '--heading' => 'Hej!', '--reply-to' => 'svar@example.com', '--body' => $this->body, '--render' => true, '--button-text' => 'Öppna', '--button-url' => 'https://example.com/x'])
        ->expectsOutputToContain('Svarar du på mejlet går svaret till svar@example.com')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();

    $html = (new BrandedMail('Hej!', 'Etapp 2 är klar.', 'A', 'svar@example.com', 'Andreas Kviby', 'Öppna', 'https://example.com/x'))->render();

    expect($html)->toContain('Med vänliga hälsningar')
        ->toContain('Andreas Kviby')
        ->toContain('https://example.com/x')
        ->toContain('Det här mejlet skickades från NONOX, hej@example.com.');
});

it('refuses a missing subject, recipient, body or half a button', function () {
    $base = ['--to' => ['anna@example.com'], '--subject' => 'A', '--heading' => 'H', '--reply-to' => 'svar@example.com', '--body' => $this->body];

    $this->artisan('mail:send', [...$base, '--subject' => ''])->assertFailed();
    $this->artisan('mail:send', [...$base, '--to' => []])->assertFailed();
    $this->artisan('mail:send', [...$base, '--body' => '/finns/inte.md'])->assertFailed();
    $this->artisan('mail:send', [...$base, '--button-text' => 'Öppna'])->assertFailed();

    Mail::assertNothingOutgoing();
});
