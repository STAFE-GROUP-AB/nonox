<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Company\SaveClientProfile;
use App\Actions\Company\UpdateCompanyBranding;
use App\Actions\Tasks\SaveRecurrence;
use App\Actions\Tasks\SaveTask;
use App\Actions\Tasks\ToggleMonthCloseStep;
use App\Actions\Tasks\WorkOnTask;
use App\Domain\Company\VatPeriod;
use App\Domain\Tasks\MonthCloseStep;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Database\Seeders\IndustryDemoSeeder;
use Illuminate\Validation\ValidationException;

/**
 * The firm pages of the manual, for the accounting firm and its clients:
 * the firm's logo and colours, the responsible consultant and a note per
 * client, recurring tasks, tasks both ways between the firm and the client
 * (each assignee is notified in the app), and the month close checked off
 * for the month before last and partly for last month.
 */
final class FirmExtras extends DemoExtra
{
    private const array NOTES = [
        'Restaurang Ekbacken Demo AB' => 'Månadsmoms. Dagsrapporterna kommer från kassasystemet varje måndag. Lönerna körs i NONOX den 25:e.',
        'Café Hörnet Demo AB' => 'Kvartalsmoms. Ägaren vill ha en kort resultatrapport per mejl efter varje månadsstängning.',
        'Butik Lindgården Demo AB' => 'Kvartalsmoms. Säljer även till Danmark och Finland, se EU-rapporterna. Inventering i december.',
        'Snickeri Norrby Demo AB' => 'ROT-jobb och omvänd byggmoms. Kontrollera husarbete-utbetalningarna från Skatteverket varje månad.',
    ];

    /** What the client's owner asks the firm. */
    private const array QUESTIONS = [
        'Restaurang Ekbacken Demo AB' => ['Fråga: ska personalfesten bokföras som representation?', 'Vi bjöd personalen på middag i oktober, kvittot ligger i inkorgen.'],
        'Café Hörnet Demo AB' => ['Fråga: hur bokför vi den nya espressomaskinen?', 'Den kostade 38 000 kr exklusive moms. Ska den skrivas av?'],
        'Butik Lindgården Demo AB' => ['Fråga: momsen på försäljningen till Danmark', 'Kunden har skickat sitt momsnummer. Räcker det för att fakturera utan moms?'],
        'Snickeri Norrby Demo AB' => ['Fråga: hur bokför vi den nya leasingbilen?', 'Avtalet ligger som bilaga i mejlet från leasingbolaget.'],
    ];

    public function industries(): array
    {
        return ['for-redovisningsbyraer'];
    }

    public function afterAll(DemoContext $c): void
    {
        $consultant = $c->user;
        $owner = User::query()->where('email', IndustryDemoSeeder::OWNER['email'])->firstOrFail();
        // One query per client: a model from a larger collection may not lazy load the memberships the policies read.
        $clients = array_map(
            fn (int $id): Company => Company::query()->findOrFail($id),
            array_values(Company::query()->where('accounting_firm_id', $c->company->id)->orderBy('id')->pluck('id')->all()),
        );

        // Two months back is closed; last month is being closed, step by step.
        $closed = $c->today->subMonthsNoOverflow(2)->format('Y-m');
        $closing = $c->today->subMonthNoOverflow()->format('Y-m');

        $this->attempt($c, function () use ($c, $consultant): void {
            app(UpdateCompanyBranding::class)($consultant, $c->company, ['brand_color' => '#2E4A3F', 'brand_accent_color' => '#C9A227'], LedgerExtras::logo('Redovisningsbyrån Exempel', [46, 74, 63], [201, 162, 39]));

            app(SaveTask::class)($consultant, $c->company, [
                'title' => 'Planera bokslutssäsongen',
                'description' => 'Fördela årets bokslut mellan konsulterna och boka in avstämningsmöten med kunderna.',
                'type' => 'YEAR_END',
                'priority' => 'HIGH',
                'due_date' => $c->today->addWeeks(2)->toDateString(),
                'assignee_id' => $consultant->id,
                'checklist' => ['Lista kunder med brutet räkenskapsår', 'Boka möten i december', 'Skicka bokslutsinstruktion till kunderna'],
            ]);
        });

        foreach ($clients as $index => $client) {
            $this->attempt($c, function () use ($c, $client, $index, $consultant, $owner, $closed, $closing): void {
                $profiles = app(SaveClientProfile::class);
                $profiles->assign($consultant, $client, $consultant->id);
                $profiles->note($consultant, $client, self::NOTES[$client->name] ?? 'Löpande bokföring och moms.');

                $vatMonthly = $client->vat_period === VatPeriod::Monthly;
                app(SaveRecurrence::class)($consultant, $client, [
                    'title' => 'Momsdeklaration',
                    'type' => 'VAT',
                    'frequency' => $vatMonthly ? 'MONTHLY' : 'QUARTERLY',
                    'due_day' => 12,
                    'assignee_id' => $consultant->id,
                ]);
                app(SaveRecurrence::class)($consultant, $client, [
                    'title' => 'Bankavstämning',
                    'description' => 'Stäm av bankkontot mot kontoutdraget per sista dagen i månaden.',
                    'type' => 'BANK',
                    'frequency' => 'MONTHLY',
                    'due_day' => 5,
                    'assignee_id' => $consultant->id,
                ]);

                // The firm asks the client for something, and the client asks the firm.
                if ($index % 2 === 0) {
                    $task = app(SaveTask::class)($consultant, $client, [
                        'title' => 'Skicka kvitton för '.$c->today->subMonthNoOverflow()->translatedFormat('F'),
                        'description' => 'Tre korttransaktioner saknar underlag. Fota kvittona och skicka dem till inkorgen.',
                        'type' => 'DOCUMENTS',
                        'status' => 'WAITING_FOR_CLIENT',
                        'due_date' => $c->today->addDays(5)->toDateString(),
                        'assignee_id' => $owner->id,
                        'checklist' => ['Kvitto drivmedel', 'Kvitto kontorsmaterial', 'Faktura från telefonbolaget'],
                    ]);
                    app(WorkOnTask::class)->comment($consultant, $task, 'Hej Anna! Det gäller köpen den 3, 12 och 21 förra månaden. Hör av dig om något är oklart.');
                }

                [$title, $description] = self::QUESTIONS[$client->name] ?? ['Fråga om bokföringen', 'Hör av dig när du har tid.'];
                $question = app(SaveTask::class)($owner, $client, [
                    'title' => $title,
                    'description' => $description,
                    'type' => 'OTHER',
                    'due_date' => $c->today->addWeek()->toDateString(),
                    'assignee_id' => $consultant->id,
                ]);

                if ($index === 0) {
                    app(WorkOnTask::class)->comment($consultant, $question, 'Jag tittar på det i veckan och återkommer.');
                }

                foreach (MonthCloseStep::cases() as $step) {
                    app(ToggleMonthCloseStep::class)($consultant, $client, $closed, $step);
                }

                foreach (array_slice(MonthCloseStep::cases(), 0, $index + 2) as $step) {
                    app(ToggleMonthCloseStep::class)($consultant, $client, $closing, $step);
                }
            });
        }
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
