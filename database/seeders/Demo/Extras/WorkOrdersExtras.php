<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\SigningPdfs;
use App\Models\Customer;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Actions\ChangeWorkOrderStatus;
use App\Modules\WorkOrders\Actions\CreateInvoiceBasis;
use App\Modules\WorkOrders\Actions\PaymentPlans;
use App\Modules\WorkOrders\Actions\RecordMaterial;
use App\Modules\WorkOrders\Actions\RecordTime;
use App\Modules\WorkOrders\Actions\SaveChangeOrder;
use App\Modules\WorkOrders\Actions\SaveWorkOrder;
use App\Modules\WorkOrders\Actions\SignChangeOrder;
use App\Modules\WorkOrders\Domain\WorkOrderStatus;
use App\Modules\WorkOrders\Models\LabourKind;
use App\Modules\WorkOrders\Models\PaymentInstalment;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Support\Demo\DemoMode;
use App\Support\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Database\Seeders\Demo\DemoIndustries;

/**
 * Arbetsorder (ADR-074) in the joinery, for the manual's work order pages:
 * Theo Björk, the apprentice, reports as Utförare (a made-up login); a
 * bathroom renovation with ROT, time and material from both of them, one
 * ÄTA the customer signed at the link and one still out for signing; a
 * fixed-price deck with a payment plan; and finished framing work for a
 * construction company under reverse charge, with its invoice basis as a
 * draft invoice. Every name, address and property is invented. Never in a
 * visitor's demo.
 */
final class WorkOrdersExtras extends DemoExtra
{
    public const string FIELD_WORKER = 'theo.bjork@example.test';

    public function industries(): array
    {
        return ['bokforing-for-hantverkare'];
    }

    public static function fieldWorker(): ?User
    {
        return User::query()->where('email', self::FIELD_WORKER)->first();
    }

    public function afterAll(DemoContext $c): void
    {
        if (DemoMode::onDemoDatabase()) {
            return;
        }

        config(['mail.default' => 'log']);
        $previous = Carbon::getTestNow();
        // The work order policy asks for the user's current company, as in a request: the owner works in the joinery.
        $team = $c->user->current_team_id;
        $c->user->forceFill(['current_team_id' => $c->company->id])->save();

        try {
            $this->atomically($c, 'Arbetsorder', fn () => CurrentCompany::run($c->company, function () use ($c): void {
                app(ModuleManager::class)->enable(WorkOrdersModule::KEY, $c->company, $c->user);
                $theo = $this->theo($c);
                $this->bathroom($c, $theo);
                $this->deck($c);
                $this->framing($c, $theo);
            }));
        } finally {
            Carbon::setTestNow($previous);
            $c->user->forceFill(['current_team_id' => $team])->save();
        }
    }

    /** The apprentice as Utförare: he sees and reports on the work orders he is put on, nothing else. */
    private function theo(DemoContext $c): User
    {
        $theo = User::query()->firstOrCreate(['email' => self::FIELD_WORKER], ['name' => 'Theo Björk', 'email_verified_at' => now()]);
        $c->company->users()->syncWithoutDetaching([$theo->id => ['role' => WorkOrdersModule::FIELD_WORKER]]);
        $c->company->unsetRelation('users');
        CurrentCompany::run($c->company, fn (): bool => $theo->switchCompany($c->company));

        return $theo;
    }

    private function bathroom(DemoContext $c, User $theo): void
    {
        $eva = app(SaveCustomer::class)($c->user, $c->company, [
            'name' => 'Eva Exempelsson', 'personal_number' => DemoIndustries::personalNumber('19810405', '952'),
            'email' => 'eva.exempelsson@example.test', 'address_line1' => 'Ekbacksvägen 3', 'postal_code' => '702 30', 'city' => 'Örebro', 'payment_terms_days' => 20,
        ]);

        $workOrder = $this->at($c->today->subDays(16), 8, fn (): WorkOrder => $this->start($c, $eva, [
            'title' => 'Badrumsrenovering',
            'description' => 'Riva och bygga om badrummet: nytt tätskikt, kakel på väggar och golv, ny dusch och kommod.',
            'site_address_line1' => 'Ekbacksvägen 3', 'site_postal_code' => '702 30', 'site_city' => 'Örebro',
            'property_designation' => 'Exempelby Ekbacken 3:14',
            'access_notes' => 'Nyckel i kodlåda vid dörren. Katten får inte gå ut.',
            'price_form' => 'TIME_AND_MATERIAL', 'material_markup' => '15', 'tax_reduction' => 'ROT',
            'standard_contract' => 'Hantverkarformuläret 17',
            'planned_start' => $c->today->subDays(14)->toDateString(), 'planned_end' => $c->today->addDays(10)->toDateString(),
            'user_ids' => [$theo->id],
        ]));

        foreach ([14, 13, 12, 9, 8, 7, 6, 2, 1] as $daysAgo) {
            $day = $c->today->subDays($daysAgo);

            if (! $day->isWeekday()) {
                continue;
            }

            $this->at($day, 16, function () use ($c, $theo, $workOrder, $day): void {
                $this->hours($c->user, $c, $workOrder, $day, '6', 'Rivning och tätskikt');
                $this->hours($theo, $c, $workOrder, $day, '7,5', null);
            });
        }

        $materials = [
            [14, 'Tätskiktssystem, sats', '1', 'st', '3 450', '3 970'],
            [12, 'Golvbrunn med klämring', '1', 'st', '1 180', '1 360'],
            [9, 'Kakel 20×40, vit', '14', 'm²', '389', '448'],
            [9, 'Klinker 30×30, grå', '5', 'm²', '445', '512'],
            [7, 'Fix och fog', '6', 'st', '219', '252'],
        ];

        foreach ($materials as [$daysAgo, $description, $quantity, $unit, $cost, $price]) {
            $day = $c->today->subDays($daysAgo);
            $this->at($day, 17, fn () => app(RecordMaterial::class)($theo, $c->company, $workOrder, [
                'source' => 'FREE', 'description' => $description, 'quantity' => $quantity, 'unit' => $unit,
                'unit_cost' => $cost, 'unit_price' => $price, 'work_date' => $day->toDateString(),
            ]));
        }

        // ÄTA 1: the old floor joists were rotten; Eva signed the price at the link before the work.
        $joists = $this->at($c->today->subDays(10), 9, function () use ($c, $workOrder) {
            $changeOrder = app(SaveChangeOrder::class)($c->user, $c->company, $workOrder, [
                'title' => 'Byte av rötskadade golvreglar', 'basis' => 'ADDITIONAL_WORK_8', 'price_form' => 'FIXED',
                'description' => 'Under det gamla golvet var två reglar rötskadade. De byts och golvet förstärks innan tätskiktet läggs.',
                'labour' => '6 400', 'material' => '1 850', 'time_effect' => 'Två dagar till',
            ]);
            $sent = app(SignChangeOrder::class)->send($c->user, $c->company, $changeOrder);
            $this->sign($sent, 'Eva Exempelsson');

            return $changeOrder->fresh() ?? $changeOrder;
        });

        $this->at($c->today->subDays(9), 17, fn () => $this->hours($c->user, $c, $workOrder, $c->today->subDays(9), '5', 'Nya golvreglar', $joists->id));

        // ÄTA 2: an extra socket by the mirror, out for signing since yesterday.
        $this->at($c->today->subDay(), 15, function () use ($c, $workOrder): void {
            $changeOrder = app(SaveChangeOrder::class)($c->user, $c->company, $workOrder, [
                'title' => 'Extra eluttag vid spegeln', 'basis' => 'CHANGE_ORDERED', 'price_form' => 'FIXED',
                'description' => 'Kunden vill ha ett jordat uttag till vid spegelskåpet. Installeras av vår underentreprenör.',
                'labour' => '1 900', 'material' => '450', 'expires_on' => $c->today->addDays(6)->toDateString(),
            ]);
            app(SignChangeOrder::class)->send($c->user, $c->company, $changeOrder);
        });
    }

    /** A fixed-price deck with ROT: two a conto parts by the plan agreed with the customer, the rest on the final invoice. */
    private function deck(DemoContext $c): void
    {
        $per = Customer::query()->where('name', 'Per Persson')->firstOrFail();

        $workOrder = $this->at($c->today->subDays(6), 9, fn (): WorkOrder => $this->start($c, $per, [
            'title' => 'Altan med trappa',
            'description' => 'Altan 24 m² i tryckimpregnerat virke med trappa och räcke, enligt ritning.',
            'site_address_line1' => 'Exempelgatan 18', 'site_postal_code' => '692 31', 'site_city' => 'Kumla',
            'property_designation' => 'Exempelby Lönnen 1:7',
            'price_form' => 'FIXED', 'contract_sum' => '96 000', 'contract_labour' => '58 000', 'tax_reduction' => 'ROT',
            'standard_contract' => 'Hantverkarformuläret 17',
            'planned_start' => $c->today->subDays(3)->toDateString(), 'planned_end' => $c->today->addDays(18)->toDateString(),
        ]));

        $this->at($c->today->subDays(6), 10, fn () => app(PaymentPlans::class)->save($c->user, $c->company, $workOrder, [
            ['label' => 'Vid start', 'amount' => '38 400', 'due_on' => $c->today->subDays(3)->toDateString()],
            ['label' => 'När stommen är klar', 'amount' => '38 400', 'due_on' => $c->today->addDays(9)->toDateString()],
        ], agreedWithCustomer: true));

        $first = PaymentInstalment::query()->where('work_order_id', $workOrder->id)->orderBy('due_on')->firstOrFail();
        $this->at($c->today->subDays(3), 9, fn () => app(PaymentPlans::class)->invoice($c->user, $c->company, $first, $c->today->subDays(3)));
        $this->at($c->today->subDays(2), 16, fn () => $this->hours($c->user, $c, $workOrder, $c->today->subDays(2), '8', 'Plintar och bärlinor'));
        $this->at($c->today->subDay(), 16, fn () => $this->hours($c->user, $c, $workOrder, $c->today->subDay(), '8', 'Bjälklag'));
    }

    /** Framing work for a construction company: reverse charge, finished, its invoice basis a draft invoice. */
    private function framing(DemoContext $c, User $theo): void
    {
        $builder = Customer::query()->where('name', 'Byggbolaget Exempel AB')->firstOrFail();

        $workOrder = $this->at($c->today->subDays(22), 8, fn (): WorkOrder => $this->start($c, $builder, [
            'title' => 'Stomkomplettering, kvarteret Exemplet',
            'description' => 'Innerväggar och undertak i tre lägenheter, som underentreprenör.',
            'site_address_line1' => 'Byggvägen 4', 'site_postal_code' => '703 40', 'site_city' => 'Örebro',
            'price_form' => 'TIME_AND_MATERIAL', 'is_construction' => true,
            'user_ids' => [$theo->id],
        ]));

        foreach ([21, 20, 19, 16, 15] as $daysAgo) {
            $day = $c->today->subDays($daysAgo);

            if (! $day->isWeekday()) {
                continue;
            }

            $this->at($day, 16, function () use ($c, $theo, $workOrder, $day): void {
                $this->hours($c->user, $c, $workOrder, $day, '8', null);
                $this->hours($theo, $c, $workOrder, $day, '8', null);
            });
        }

        $this->at($c->today->subDays(15), 17, fn () => app(RecordMaterial::class)($c->user, $c->company, $workOrder, [
            'source' => 'FREE', 'description' => 'Reglar och skruv enligt följesedel', 'quantity' => '1', 'unit' => 'st',
            'unit_cost' => '4 820', 'unit_price' => '5 540', 'work_date' => $c->today->subDays(15)->toDateString(),
        ]));

        $this->at($c->today->subDays(14), 9, function () use ($c, $workOrder): void {
            $done = app(ChangeWorkOrderStatus::class)($c->user, $c->company, $workOrder->fresh() ?? $workOrder, WorkOrderStatus::Done);
            app(CreateInvoiceBasis::class)($c->user, $c->company, $done, null, $c->today->subDays(14));
        });
    }

    /** @param  array<string, mixed>  $input */
    private function start(DemoContext $c, Customer $customer, array $input): WorkOrder
    {
        $workOrder = app(SaveWorkOrder::class)($c->user, $c->company, ['customer_id' => $customer->id, ...$input]);

        return app(ChangeWorkOrderStatus::class)($c->user, $c->company, $workOrder, WorkOrderStatus::InProgress);
    }

    private function hours(User $user, DemoContext $c, WorkOrder $workOrder, CarbonImmutable $day, string $hours, ?string $note, ?int $changeOrderId = null): void
    {
        $kind = LabourKind::query()->where('code', 'ARBETE')->firstOrFail();

        app(RecordTime::class)($user, $c->company, $workOrder, array_filter([
            'labour_kind_id' => $kind->id, 'work_date' => $day->toDateString(), 'hours' => $hours,
            'note' => $note, 'change_order_id' => $changeOrderId, 'rate' => '595',
        ], fn (mixed $value): bool => $value !== null));
    }

    /** @param  array{request: SigningRequest, url: string}  $sent */
    private function sign(array $sent, string $name): void
    {
        $links = app(SignAtLink::class);
        $request = $links->find(basename((string) parse_url($sent['url'], PHP_URL_PATH))) ?? $sent['request'];

        $links->sign($request, [
            'name' => $name,
            'accept' => '1',
            'method' => SigningRequest::TYPED,
            'typed_signature' => $name,
            'document_sha256' => $request->document_sha256,
            ...SigningPdfs::acceptanceFields($request),
        ], '192.0.2.10', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Exempel');
    }

    /**
     * Runs $step as if it were $hour o'clock on $day.
     *
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    private function at(CarbonImmutable $day, int $hour, callable $step): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($day->setTime($hour, 0));

        try {
            return $step();
        } finally {
            Carbon::setTestNow($previous);
        }
    }
}
