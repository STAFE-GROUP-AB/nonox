<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Boardroom\Support\BoardroomPdf;
use App\Modules\Boardroom\Support\ShareRegister;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Boardroom;
use Tests\Support\Bookkeeping;

/*
 * The register as a document (ADR-070 §6 and addendum, rule sheet AB-R14):
 * the full version for inspection at the company (ABL 5 kap. 10 §), and a
 * voluntary courtesy copy without personal numbers, labelled as such and
 * never presented as the legal fulfilment. Every print is logged.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    Boardroom::enable($this->company, $this->owner);
    $this->a = Boardroom::person($this->owner, $this->company, 'Anna Andersson', '19800101123');
    $this->holding = Boardroom::entity($this->owner, $this->company);
    Boardroom::formation($this->owner, $this->company, $this->a, 500, 1_250_000, '2026-03-02');
    Boardroom::record($this->owner, $this->company, ['kind' => 'FORMATION', 'effective_date' => '2026-03-02', 'share_from' => 501, 'share_to' => 1000, 'to_holder_id' => $this->holding->id, 'capital_change_ore' => 1_250_000]);
    Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2027-03-31', 'share_from' => 401, 'share_to' => 500, 'from_holder_id' => $this->a->id, 'to_holder_id' => $this->holding->id]);
});

function registerHtml(bool $masked, string $date = '2027-03-31'): string
{
    $day = CarbonImmutable::parse($date);

    return BoardroomPdf::registerHtml(test()->company, ShareRegister::at($day), $day, $masked, test()->owner);
}

it('prints the full register at a date with personal numbers, in number order', function () {
    $html = registerHtml(false);

    expect($html)->toContain('Aktiebok – fullständig version för granskning hos bolaget')
        ->toContain('19800101-'.substr(Boardroom::personalNumber('19800101123'), 8))
        ->toContain('556036-0793')
        ->toContain('Aktieboken per 2027-03-31')
        ->and(strpos($html, '1–400'))->toBeLessThan(strpos($html, '401–1000'));
});

it('leaves every personal number out of the courtesy copy, keeps organisation numbers and says it is not the register for inspection', function () {
    $html = registerHtml(true);
    $text = strip_tags($html);

    // Organisation numbers (the company's and the holding company's) are not personal data; nothing else may look like a personal number.
    $withoutOrgNumbers = str_replace(['556036-0793', (string) $this->company->org_number], '', $text);
    $pnr = Boardroom::personalNumber('19800101123');

    expect(preg_match('/\b\d{6,8}[-+]?\d{4}\b/', $withoutOrgNumbers))->toBe(0)
        ->and($text)->not->toContain($pnr)->not->toContain(substr($pnr, 2))
        ->and($text)->toContain('1980-01-01')
        ->and($text)->toContain('556036-0793')
        ->and($text)->toContain('Frivillig kopia utan personnummer – inte aktieboken för granskning')
        ->and($text)->toContain('uppfyller inte skyldigheten att hålla aktieboken tillgänglig');
});

it('prints the register as it stood on the date', function () {
    expect(registerHtml(false, '2027-03-30'))->toContain('1–500')->not->toContain('401–1000');
});

it('serves both versions as PDF and logs who printed which', function () {
    $this->actingAs($this->owner)->get('/aktiebok/utskrift?datum=2027-03-31&version=fullstandig')
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->owner)->get('/aktiebok/utskrift?datum=2027-03-31&version=utan-personnummer')
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $logged = AuditLog::forCompany($this->company)->where('action', 'share_register.printed')->orderBy('id')->get();

    expect($logged->pluck('payload.version')->all())->toBe(['full', 'without_personal_numbers'])
        ->and($logged->pluck('user_id')->unique()->all())->toBe([$this->owner->id]);
});

it('refuses the print to a member without share-register:view', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    $this->actingAs($bookkeeper)->get('/aktiebok/utskrift')->assertForbidden();
});
