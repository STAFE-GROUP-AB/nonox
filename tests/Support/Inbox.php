<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\Ai\AiModule;
use App\Modules\Inbox\Actions\AcceptInboundMail;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Models\AllowedSender;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Helpers for the inbox tests: a company with the inbox on, synthetic mail
 * built from the fixtures in tests/Fixtures/inbox, and receiving it.
 */
final class Inbox
{
    public const string PASS = 'mx.in.nonox.example; spf=pass smtp.mailfrom=%1$s; dkim=pass header.d=%1$s; dmarc=pass header.from=%1$s';

    public const string DMARC_FAIL = 'mx.in.nonox.example; spf=fail smtp.mailfrom=%1$s; dkim=none; dmarc=fail header.from=%1$s';

    /**
     * An owner with a company that has a chart of accounts and the inbox on.
     *
     * @return array{0: User, 1: Company}
     */
    public static function company(): array
    {
        [$owner, $company] = Bookkeeping::company();
        app(ModuleManager::class)->enable(InboxModule::KEY, $company, $owner);

        return [$owner, $company];
    }

    public static function allow(Company $company, string $pattern): void
    {
        CurrentCompany::run($company, fn () => AllowedSender::query()->firstOrCreate(['pattern' => $pattern]));
    }

    public static function enableAi(Company $company, User $owner): void
    {
        $modules = app(ModuleManager::class);
        $modules->enable(AiModule::KEY, $company, $owner);
        $modules->updateSettings(AiModule::KEY, $company, $owner, ['provider' => 'OPENAI', 'api_keys' => ['OPENAI' => Crypt::encryptString('sk-test-abcdefghijklmnopqrstuvwxyz0123456789ABCD')]]);
    }

    public static function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/inbox/{$name}"));
    }

    /**
     * A one-page PDF with the lines as its text layer, like the PDF printout
     * of a cash register. Built here so no real report is committed.
     *
     * @param  list<string>  $lines
     */
    public static function pdf(array $lines): string
    {
        $text = '';

        foreach ($lines as $line) {
            $latin = (string) mb_convert_encoding($line, 'Windows-1252', 'UTF-8');
            $text .= '('.addcslashes($latin, '()\\').") Tj 0 -14 Td\n";
        }

        $stream = "BT /F1 10 Tf 50 800 Td\n{$text}ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /**
     * A raw message.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $attachments  filename, contents, MIME type
     */
    public static function mail(string $from, string $subject = 'Underlag', string $body = 'Se bilagan.', array $attachments = [], string $auth = self::PASS, array $headers = []): string
    {
        $domain = substr($from, (int) strrpos($from, '@') + 1);
        $email = (new Email)
            ->from(new Address($from))
            ->to('exempel-abc12345@in.nonox.app')
            ->subject($subject)
            ->text($body);

        $email->getHeaders()->addTextHeader('Authentication-Results', sprintf($auth, $domain));

        foreach ($headers as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }

        foreach ($attachments as [$filename, $contents, $mime]) {
            $email->attach($contents, $filename, $mime);
        }

        return $email->toString();
    }

    /**
     * Takes a raw message into the company's inbox, as from the cloud
     * address.
     */
    public static function receive(Company $company, string $raw, ?string $alias = null): ?InboxMessage
    {
        return app(AcceptInboundMail::class)($company, $raw, Channel::Mailgun, 'exempel-abc12345@in.nonox.app', $alias);
    }

    public static function fresh(InboxMessage $message): InboxMessage
    {
        return CurrentCompany::run($message->company_id, fn (): InboxMessage => InboxMessage::query()->with('attachments')->findOrFail($message->id));
    }
}
