<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\BrandedMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a hand-written mail through the instance's own mailer, so it carries
 * the NONOX layout and goes out from MAIL_FROM_ADDRESS. Run it where the mail
 * credentials live (the production server):
 *
 *   php artisan mail:send --to=someone@example.se --subject='…' --heading='Hej!' \
 *       --reply-to=reply@example.se --body=- < body.md
 *
 * --render prints the finished HTML without sending, to review a mail first.
 * The reply-to address always gets a copy, so the person replies go to has
 * everything sent in their name; --no-copy turns that off.
 */
class SendBrandedMailCommand extends Command
{
    protected $signature = 'mail:send
                            {--to=* : Mottagare, kan anges flera gånger}
                            {--cc=* : Kopia, kan anges flera gånger}
                            {--subject= : Ämnesrad}
                            {--heading= : Rubriken överst i mejlet}
                            {--body= : Fil med brödtexten i Markdown, eller - för standard in}
                            {--reply-to= : Adressen svar ska gå till}
                            {--signature=Andreas Kviby : Namnet under "Med vänliga hälsningar"}
                            {--button-text= : Text på knappen}
                            {--button-url= : Adress knappen leder till}
                            {--no-copy : Skicka utan kopia till svarsadressen}
                            {--render : Skriv ut HTML:en utan att skicka}';

    protected $description = 'Skickar ett handskrivet mejl i NONOX layout.';

    public function handle(): int
    {
        /** @var list<string> $to */
        $to = array_values(array_filter((array) $this->option('to')));
        /** @var list<string> $cc */
        $cc = array_values(array_filter((array) $this->option('cc')));
        $subject = (string) $this->option('subject');
        $heading = (string) $this->option('heading');
        $replyTo = (string) $this->option('reply-to');
        $buttonText = (string) $this->option('button-text');
        $buttonUrl = (string) $this->option('button-url');

        foreach (['subject' => $subject, 'heading' => $heading, 'reply-to' => $replyTo] as $name => $value) {
            if (trim($value) === '') {
                $this->error("--{$name} är obligatoriskt.");

                return self::FAILURE;
            }
        }

        if ($to === [] && ! $this->option('render')) {
            $this->error('--to är obligatoriskt när något ska skickas.');

            return self::FAILURE;
        }

        if (($buttonText === '') !== ($buttonUrl === '')) {
            $this->error('--button-text och --button-url hänger ihop: ange båda eller ingen.');

            return self::FAILURE;
        }

        $body = $this->readBody();

        if ($body === null) {
            return self::FAILURE;
        }

        $mail = new BrandedMail($heading, $body, $subject, $replyTo, (string) $this->option('signature'), $buttonText, $buttonUrl);

        if ($this->option('render')) {
            $this->line($mail->render());

            return self::SUCCESS;
        }

        if (! $this->option('no-copy') && ! in_array($replyTo, [...$to, ...$cc], true)) {
            $cc[] = $replyTo;
        }

        $this->line('Avsändare: '.config('mail.from.address'));
        $this->line("Svar till: {$replyTo}");
        $this->line('Mottagare: '.implode(', ', $to));

        if ($cc !== []) {
            $this->line('Kopia: '.implode(', ', $cc));
        }

        $pending = Mail::to($to);

        if ($cc !== []) {
            $pending->cc($cc);
        }

        $pending->send($mail);

        $this->info('Mejlet är skickat.');

        return self::SUCCESS;
    }

    private function readBody(): ?string
    {
        $source = (string) $this->option('body');

        if ($source === '') {
            $this->error('--body är obligatoriskt: en fil med brödtexten, eller - för standard in.');

            return null;
        }

        if ($source !== '-' && ! is_file($source)) {
            $this->error("Hittar ingen fil på {$source}.");

            return null;
        }

        $body = trim((string) file_get_contents($source === '-' ? 'php://stdin' : $source));

        if ($body === '') {
            $this->error('Brödtexten är tom.');

            return null;
        }

        return $body;
    }
}
