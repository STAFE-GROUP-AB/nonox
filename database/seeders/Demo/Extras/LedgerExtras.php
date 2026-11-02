<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\CorrectVoucher;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDimensionObject;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Company\UpdateCompanyBranding;
use App\Actions\Jetstream\AddTeamMember;
use App\Actions\Jetstream\InviteTeamMember;
use App\Domain\Accounting\Dimension;
use App\Models\User;
use App\Models\Voucher;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The bookkeeping pages of the manual, in the consultancy: cost centres and
 * projects used on rows, a posted voucher with a receipt as underlag, a
 * voucher booked on the wrong account and its correcting voucher, a draft,
 * the company's colours and logo, a bookkeeper and a pending invitation.
 * The receipt and the logo are drawn here and say that they are examples.
 */
final class LedgerExtras extends DemoExtra
{
    public const array COST_CENTERS = ['RADG' => 'Rådgivning', 'UTB' => 'Utbildning'];

    public const array PROJECTS = ['P-101' => 'Systembyte, Kunden Exempel AB', 'P-102' => 'Workshopserie, Mjukvaruhuset'];

    public const array BOOKKEEPER = ['name' => 'Bo Bokförare', 'email' => 'bo.bokforare@example.test'];

    public const string INVITED = 'ny.medarbetare@example.test';

    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function schedule(DemoContext $c): void
    {
        foreach ([[Dimension::CostCenter, self::COST_CENTERS], [Dimension::Project, self::PROJECTS]] as [$dimension, $objects]) {
            foreach ($objects as $code => $name) {
                app(SaveDimensionObject::class)($c->user, $c->company, $dimension, ['code' => $code, 'name' => $name]);
            }
        }

        $receiptDay = CarbonImmutable::parse('2026-10-08');
        $c->on($receiptDay, 20, function () use ($c, $receiptDay): void {
            $voucher = $this->post($c, $receiptDay, 'Kontorsmaterial, Exempelbutiken (kort)', [
                ['account' => 6110, 'debit_ore' => 35_000, 'cost_center' => 'RADG', 'project' => 'P-101', 'description' => 'Pärmar och block till projektet'],
                ['account' => 2641, 'debit_ore' => 8_750],
                ['account' => 1930, 'credit_ore' => 43_750],
            ]);
            $this->attachReceipt($c, $voucher, $receiptDay);
        });

        $courseDay = CarbonImmutable::parse('2026-10-22');
        $c->on($courseDay, 20, fn () => $this->post($c, $courseDay, 'Kurslitteratur till workshop', [
            ['account' => 6970, 'debit_ore' => 94_340, 'cost_center' => 'UTB', 'project' => 'P-102'],
            ['account' => 2641, 'debit_ore' => 5_660],
            ['account' => 1930, 'credit_ore' => 100_000],
        ]));

        // Booked on office supplies by mistake, then corrected to equipment of small value.
        $wrongDay = CarbonImmutable::parse('2026-10-14');
        $c->on($wrongDay, 20, function () use ($c, $wrongDay): void {
            $voucher = $this->post($c, $wrongDay, 'Hörlurar till kontoret', [
                ['account' => 6110, 'debit_ore' => 160_000],
                ['account' => 2641, 'debit_ore' => 40_000],
                ['account' => 1930, 'credit_ore' => 200_000],
            ]);

            $c->on($wrongDay->addDays(2), 20, fn () => app(CorrectVoucher::class)($c->user, $voucher->fresh() ?? $voucher, 'Fel konto, hörlurarna är förbrukningsinventarier.', $wrongDay->addDays(2)->toDateString(), [
                ['account' => 5410, 'debit_ore' => 160_000, 'credit_ore' => 0],
                ['account' => 2641, 'debit_ore' => 40_000, 'credit_ore' => 0],
                ['account' => 1930, 'debit_ore' => 0, 'credit_ore' => 200_000],
            ]));
        });
    }

    public function afterAll(DemoContext $c): void
    {
        config(['mail.default' => 'log']);

        $this->attempt($c, fn () => app(SaveDraftVoucher::class)($c->user, $c->company, [
            'voucher_date' => $c->end->toDateString(),
            'description' => 'Taxi till kundmöte, utlägg Anna (kvitto kommer)',
            'rows' => [
                ['account' => 5800, 'debit_ore' => 28_302, 'project' => 'P-101'],
                ['account' => 2641, 'debit_ore' => 1_698],
                ['account' => 2890, 'credit_ore' => 30_000],
            ],
        ]));

        $this->attempt($c, fn () => app(UpdateCompanyBranding::class)(
            $c->user,
            $c->company,
            ['brand_color' => '#1F4E5F', 'brand_accent_color' => '#E07A3F'],
            self::logo('Konsultbolaget Exempel', [31, 78, 95], [224, 122, 63]),
        ));

        $this->attempt($c, function () use ($c): void {
            User::query()->firstOrCreate(['email' => self::BOOKKEEPER['email']], ['name' => self::BOOKKEEPER['name'], 'email_verified_at' => now()]);
            app(AddTeamMember::class)->add($c->user, $c->company, self::BOOKKEEPER['email'], 'bookkeeper');
        });

        $this->attempt($c, fn () => app(InviteTeamMember::class)->invite($c->user, $c->company, self::INVITED, 'approver'));
    }

    /**
     * A logo drawn with GD: a coloured mark with the initials and the name
     * beside it, on a transparent background, as a PNG upload.
     *
     * @param  array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}  $color
     * @param  array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}  $accent
     */
    public static function logo(string $name, array $color, array $accent): UploadedFile
    {
        $text = (string) iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        $small = imagecreatetruecolor(24 + 9 * strlen($text), 24) ?: throw new \RuntimeException('GD could not make an image.');
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, (int) imagecolorallocatealpha($small, 255, 255, 255, 127));
        imagefilledrectangle($small, 1, 3, 18, 20, (int) imagecolorallocate($small, ...$color));
        imagefilledrectangle($small, 12, 14, 18, 20, (int) imagecolorallocate($small, ...$accent));
        imagestring($small, 3, 4, 5, strtoupper(substr($text, 0, 1)), (int) imagecolorallocate($small, 255, 255, 255));
        imagestring($small, 5, 24, 4, $text, (int) imagecolorallocate($small, ...$color));

        $width = imagesx($small) * 4;
        $large = imagecreatetruecolor($width, 96) ?: throw new \RuntimeException('GD could not make an image.');
        imagealphablending($large, false);
        imagesavealpha($large, true);
        imagecopyresized($large, $small, 0, 0, 0, 0, $width, 96, imagesx($small), 24);

        $path = (string) tempnam(sys_get_temp_dir(), 'nonox-demo-logo');
        imagepng($large, $path);

        return new UploadedFile($path, 'logotyp.png', 'image/png', null, true);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function post(DemoContext $c, CarbonImmutable $date, string $description, array $rows): Voucher
    {
        $draft = app(SaveDraftVoucher::class)($c->user, $c->company, [
            'voucher_date' => $date->toDateString(),
            'description' => $description,
            'rows' => $rows,
        ]);

        return app(PostVoucher::class)($c->user, $draft);
    }

    private function attachReceipt(DemoContext $c, Voucher $voucher, CarbonImmutable $date): void
    {
        $pdf = self::receiptPdf([
            'EXEMPELKVITTO - inte ett riktigt kvitto',
            '',
            'Exempelbutiken Kontor',
            'Exempelgatan 1, 111 22 Stockholm',
            "Datum: {$date->toDateString()}",
            '',
            'Pärmar A4, 5 st              150,00',
            'Anteckningsblock, 10 st      200,00',
            '',
            'Summa exkl. moms             350,00',
            'Moms 25 %                     87,50',
            'Att betala                   437,50',
            '',
            'Betalt med kort',
            'Kund: '.$c->company->name,
        ]);

        $path = (string) tempnam(sys_get_temp_dir(), 'nonox-demo-receipt');
        file_put_contents($path, $pdf);

        app(AttachToVoucher::class)($c->user, $voucher, new UploadedFile($path, 'exempelkvitto-kontorsmaterial.pdf', 'application/pdf', null, true));
    }

    /**
     * A one-page PDF with the lines in Helvetica, in the shape of a till
     * receipt. Text is WinAnsi, so Swedish letters print.
     *
     * @param  list<string>  $lines
     */
    public static function receiptPdf(array $lines): string
    {
        $text = "BT /F1 9 Tf 14 TL 18 400 Td\n";

        foreach ($lines as $line) {
            $encoded = (string) iconv('UTF-8', 'Windows-1252//TRANSLIT', $line);
            $text .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded).") '\n";
        }

        $text .= 'ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 260 430] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($text)." >>\nstream\n{$text}\nendstream",
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

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function attempt(DemoContext $c, \Closure $step): void
    {
        try {
            $step();
        } catch (ValidationException $exception) {
            $c->warn(collect($exception->errors())->flatten()->implode(' '));
        }
    }
}
