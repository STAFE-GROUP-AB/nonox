<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Models\TeamInvitation;
use App\Models\User;
use App\Modules\Payroll\Actions\AbsenceRequests;
use App\Modules\Payroll\Actions\ExpenseClaims;
use App\Modules\Payroll\Actions\InviteEmployeeToPortal;
use App\Modules\Payroll\Models\Expense;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Illuminate\Http\UploadedFile;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use RuntimeException;

/**
 * Lina Berg, waitress at the restaurant, on Min sida: invited in January
 * and signed in with her own (synthetic) login, so the portal shows her
 * payslips. She has an outlay that was approved and repaid with September's
 * salary, one waiting for approval, a sick report from today and a vacation
 * request for Christmas. Her receipts are images drawn here, not real ones.
 */
final class PortalExtras extends DemoExtra
{
    public const string EMPLOYEE = 'Lina';

    public const string EMAIL = 'lina.berg@example.test';

    private const string FONT = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSansMono.ttf';

    public function __construct(private readonly PayrollExtras $payroll) {}

    public function industries(): array
    {
        return ['bokforing-for-restauranger'];
    }

    public static function user(): ?User
    {
        return User::query()->where('email', self::EMAIL)->first();
    }

    public function schedule(DemoContext $context): void
    {
        config(['mail.default' => 'log']);

        // After the move from the earlier payroll system, which gives every employee an e-mail address.
        $context->on($context->start->addDay(), 8, fn () => $this->invite($context));
        $context->on(CarbonImmutable::parse('2026-09-08'), 5, fn () => $this->outlay($context, '2026-09-07', 'Engångsartiklar till cateringen hos Kontorshuset', '486.25', '97.25', 'Exempelbutiken Demo AB'));
        $context->on(CarbonImmutable::parse('2026-09-10'), 5, function () use ($context): void {
            $expense = Expense::query()->where('status', 'SUBMITTED')->latest('id')->firstOrFail();
            app(ExpenseClaims::class)->approve($context->user, $expense, 5460);
        });
    }

    /** What is waiting from her today: a sick report, a vacation request and an outlay. */
    public function afterAll(DemoContext $context): void
    {
        config(['mail.default' => 'log']);
        $lina = self::user() ?? throw new RuntimeException('Lina Berg has no login.');
        $requests = app(AbsenceRequests::class);
        $today = $context->today;

        $requests->request($lina, $context->company, ['type' => 'SICK', 'date_from' => $today->toDateString(), 'date_to' => $today->toDateString(), 'note' => 'Feber, stannar hemma i dag.']);
        $requests->request($lina, $context->company, ['type' => 'VACATION', 'date_from' => '2026-12-21', 'date_to' => '2026-12-30', 'note' => 'Julledigt, som vi pratade om.']);
        $this->outlay($context, $today->subDays(3)->toDateString(), 'Blommor till borden, helgens bröllop', '349.00', '69.80', 'Blomsterhandeln Demo');
    }

    /**
     * The flow the app uses: the employer invites her from her employee
     * card, she signs in with the address and accepts, and the login is
     * linked to her employee record (LinkEmployeeLogin).
     */
    private function invite(DemoContext $context): void
    {
        $employee = $this->payroll->employeeOrFail($context, self::EMPLOYEE);

        if ($employee->email !== self::EMAIL) {
            $employee = $this->payroll->saveEmployee($context, $employee, ['email' => self::EMAIL]);
        }

        app(InviteEmployeeToPortal::class)($context->user, $context->company, $employee);

        $lina = User::query()->firstOrCreate(['email' => self::EMAIL], ['name' => "{$employee->first_name} {$employee->last_name}", 'email_verified_at' => now()]);
        $invitation = TeamInvitation::query()->where('team_id', $context->company->id)->where('email', self::EMAIL)->firstOrFail();

        app(AddsTeamMembers::class)->add($context->user, $context->company, $invitation->email, $invitation->role);
        $invitation->delete();
        $context->company->unsetRelation('users');
        // Switching company forgets the current company so a web request resolves it again; here the seeder's must stay.
        CurrentCompany::run($context->company, fn (): bool => $lina->switchCompany($context->company));
    }

    /** An outlay as she sends it from her phone: the details and a photo of the receipt. */
    private function outlay(DemoContext $context, string $date, string $description, string $amount, string $vat, string $shop): Expense
    {
        $lina = self::user() ?? throw new RuntimeException('Lina Berg has no login.');
        $path = self::receipt($shop, $date, $description, $amount, $vat);

        try {
            // Fresh, so the company's members include her, as on her own request.
            return app(ExpenseClaims::class)->submit($lina, $context->company->fresh() ?? $context->company, [
                'date' => $date,
                'description' => $description,
                'amount' => $amount,
                'vat' => $vat,
            ], new UploadedFile($path, 'kvitto.png', 'image/png', null, true));
        } finally {
            @unlink($path);
        }
    }

    /** A synthetic receipt as a PNG in a temporary file. */
    private static function receipt(string $shop, string $date, string $description, string $amount, string $vat): string
    {
        $image = imagecreatetruecolor(480, 640) ?: throw new RuntimeException('GD cannot draw the receipt.');
        $paper = (int) imagecolorallocate($image, 252, 251, 247);
        $ink = (int) imagecolorallocate($image, 40, 40, 40);
        imagefill($image, 0, 0, $paper);

        $lines = [
            $shop,
            'Exempelgatan 1, Göteborg',
            'Org.nr 559999-0000 (demo)',
            '',
            "Datum {$date}",
            '',
            ...mb_str_split(mb_substr($description, 0, 96), 32),
            '',
            sprintf('%-18s %10s', 'Totalt SEK', str_replace('.', ',', $amount)),
            sprintf('%-18s %10s', 'varav moms 25 %', str_replace('.', ',', $vat)),
            '',
            'Betalt med kort ****1234',
            '',
            'DEMOKVITTO – INTE ETT RIKTIGT KVITTO',
        ];
        $font = base_path(self::FONT);

        foreach ($lines as $index => $line) {
            if (is_file($font)) {
                imagettftext($image, 14, 0, 32, 60 + $index * 30, $ink, $font, $line);

                continue;
            }

            imagestring($image, 5, 32, 45 + $index * 30, $line, $ink);
        }

        $path = tempnam(sys_get_temp_dir(), 'kvitto') ?: throw new RuntimeException('No temporary file for the receipt.');
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }
}
