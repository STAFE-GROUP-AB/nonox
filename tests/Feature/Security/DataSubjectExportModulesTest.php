<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Company;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\Models\InboxAttachment;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Actions\CorrectEntry;
use App\Modules\StaffLedger\Actions\RecordManualEntry;
use App\Modules\StaffLedger\Domain\CorrectionKind;
use App\Modules\StaffLedger\Domain\Direction;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\Bookkeeping;
use Tests\Support\Inbox;
use Tests\Support\StaffLedger;

/*
 * Registerutdrag (GDPR art. 15 and 20) for a person in the staff ledger and
 * for a sender to the inbox: same permission and logging pattern as the
 * customer, supplier and employee extracts, and never another company's data.
 */

function inboxMessage(Company $company, string $from, string $subject, array $attachments = []): InboxMessage
{
    return CurrentCompany::run($company, function () use ($from, $subject, $attachments): InboxMessage {
        $message = InboxMessage::query()->create([
            'channel' => Channel::Mailgun,
            'recipient' => 'exempel-abc12345@in.nonox.app',
            'from_email' => $from,
            'from_name' => 'Avsändare Test',
            'subject' => $subject,
            'received_at' => CarbonImmutable::parse('2026-10-30 09:00'),
            'eml_path' => 'inbox/test/'.Str::uuid().'.eml',
            'eml_sha256' => hash('sha256', (string) Str::uuid()),
            'eml_size' => 100,
            'body_text' => 'Hemlig text',
            'status' => MessageStatus::NeedsReview,
        ]);

        foreach ($attachments as $position => $filename) {
            InboxAttachment::query()->create([
                'inbox_message_id' => $message->id,
                'position' => $position,
                'filename' => $filename,
                'mime' => 'application/pdf',
                'size' => 2048,
                'sha256' => str_repeat('b', 64),
                'path' => 'inbox/test/'.$filename,
            ]);
        }

        return $message;
    });
}

describe('staff ledger person', function () {
    beforeEach(function () {
        [$this->owner, $this->company] = Bookkeeping::company();
        CurrentCompany::set($this->company);
        $this->actingAs($this->owner);
        StaffLedger::enable($this->company, $this->owner);
        $this->location = StaffLedger::location($this->owner, $this->company);
        $this->person = StaffLedger::person($this->owner, $this->company);
    });

    it('exports the person with every ledger entry and correction, but never the PIN or QR key', function () {
        $in = app(RecordManualEntry::class)($this->owner, $this->company, $this->person, $this->location, Direction::In, now()->toImmutable()->subHours(3), 'Glömde checka in');
        $correction = app(CorrectEntry::class)($this->owner, $this->company, $in, CorrectionKind::Time, now()->toImmutable()->subHours(4), 'Rätt tid');

        $response = $this->get(route('privacy.export.staffLedgerPerson', $this->person))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="registerutdrag-personal-'.$this->person->id.'.json"');
        $json = $response->json();

        expect($json['person']['personnummer'])->toBe($this->person->personal_number)
            ->and($json['person']['har_pinkod'])->toBeTrue()
            ->and($json['foto']['sparat'])->toBeFalse()
            ->and($json['registreringar'])->toHaveCount(2)
            ->and(collect($json['registreringar'])->firstWhere('id', $correction->uuid)['rattar_registrering'])->toBe($in->uuid)
            ->and($json['registreringar'][0]['lokal'])->toBe('Restaurang Testgatan');

        $raw = (string) $response->getContent();

        foreach (['pin_verifier', 'pin_salt', 'qr_secret_hash', 'qr_nonce', 'control_key', 'control_salt'] as $secret) {
            expect($raw)->not->toContain($secret);
        }

        expect(AuditLog::query()->where('action', 'privacy.exported')->where('payload->subject', 'staff_ledger_person')->exists())->toBeTrue();
    });

    it('is refused to those who may not see full personal numbers in the ledger', function () {
        $this->actingAs(Bookkeeping::member($this->company, 'auditor'))
            ->get(route('privacy.export.staffLedgerPerson', $this->person))
            ->assertForbidden();
    });

    it('still works with the module off, as long as the ledger is kept', function () {
        app(ModuleManager::class)->disable(StaffLedgerModule::KEY, $this->company, $this->owner, confirmed: true);

        $this->get(route('privacy.export.staffLedgerPerson', $this->person))->assertOk();
    });

    it("answers 404 for another company's person", function () {
        [$otherOwner, $other] = Bookkeeping::company();
        StaffLedger::enable($other, $otherOwner);
        $foreign = CurrentCompany::run($other, fn () => StaffLedger::person($otherOwner, $other, '19850505123'));

        CurrentCompany::set($this->company);

        $this->actingAs($this->owner)->get(route('privacy.export.staffLedgerPerson', $foreign))->assertNotFound();
    });
});

describe('inbox sender', function () {
    beforeEach(function () {
        [$this->owner, $this->company] = Inbox::company();
        CurrentCompany::set($this->company);
        $this->actingAs($this->owner);
    });

    it('exports the metadata of every message from the address, as JSON and CSV', function () {
        inboxMessage($this->company, 'Kvitto@Example.test', 'Kvitto oktober', ['kvitto.pdf']);
        inboxMessage($this->company, 'kvitto@example.test', 'Kvitto november');
        inboxMessage($this->company, 'annan@example.test', 'Annat');

        $json = $this->get(route('privacy.export.inboxSender', ['avsandare' => 'kvitto@example.test']))->assertOk()->json();

        expect($json['avsandare'])->toBe('kvitto@example.test')
            ->and($json['meddelanden'])->toHaveCount(2)
            ->and(collect($json['meddelanden'])->pluck('amne')->sort()->values()->all())->toBe(['Kvitto november', 'Kvitto oktober'])
            ->and(collect($json['meddelanden'])->firstWhere('amne', 'Kvitto oktober')['bilagor'][0]['filnamn'])->toBe('kvitto.pdf')
            ->and(json_encode($json))->not->toContain('Hemlig text');

        $csv = $this->get(route('privacy.export.inboxSender', ['avsandare' => 'kvitto@example.test', 'format' => 'csv']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        expect($csv)->toContain('mottaget;avsandare;avsandarnamn;amne;status;dokumenttyp;bilagor')
            ->and($csv)->toContain('kvitto.pdf (application/pdf, 2048 byte)')
            ->and(substr_count(trim($csv), "\n"))->toBe(2);

        $audit = AuditLog::query()->where('action', 'privacy.exported')->where('payload->subject', 'inbox_sender')->latest('id')->firstOrFail();

        expect($audit->payload['messages'])->toBe(2)
            ->and(json_encode($audit->payload))->not->toContain('kvitto@example.test');
    });

    it('requires the right to handle the inbox and a valid address', function () {
        $this->get(route('privacy.export.inboxSender', ['avsandare' => 'inte-en-adress']))->assertSessionHasErrors('avsandare');

        $this->actingAs(Bookkeeping::member($this->company, 'auditor'))
            ->get(route('privacy.export.inboxSender', ['avsandare' => 'kvitto@example.test']))
            ->assertForbidden();
    });

    it("never includes another company's messages from the same address", function () {
        [, $other] = Inbox::company();
        inboxMessage($other, 'kvitto@example.test', 'Annat företags kvitto');
        inboxMessage($this->company, 'kvitto@example.test', 'Eget kvitto');

        CurrentCompany::set($this->company);

        $json = $this->get(route('privacy.export.inboxSender', ['avsandare' => 'kvitto@example.test']))->assertOk()->json();

        expect(collect($json['meddelanden'])->pluck('amne')->all())->toBe(['Eget kvitto']);
    });
});
