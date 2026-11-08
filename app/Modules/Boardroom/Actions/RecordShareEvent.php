<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Boardroom\Domain\EventHash;
use App\Modules\Boardroom\Domain\HolderKind;
use App\Modules\Boardroom\Domain\RegisterException;
use App\Modules\Boardroom\Domain\RegisterFold;
use App\Modules\Boardroom\Domain\ShareEventKind;
use App\Modules\Boardroom\Models\Register;
use App\Modules\Boardroom\Models\ShareClass;
use App\Modules\Boardroom\Models\ShareEvent;
use App\Modules\Boardroom\Models\Shareholder;
use App\Modules\Boardroom\Support\BoardroomAccess;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Records one entry in the share register (ADR-070 §2). The register row is
 * locked, the entry gets the next sequence number and its id before it is
 * written, the whole history is folded with the entry added (RegisterFold),
 * so an entry that would hold a share twice or reach outside the issued
 * ranges is refused, and the entry is hashed onto the chain. The
 * recipient's details are copied into the entry. All or nothing; every way
 * of writing to the register goes through here.
 */
final class RecordShareEvent
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input): ShareEvent
    {
        BoardroomAccess::authorizeEntry($user, $company);

        return CurrentCompany::run($company, function () use ($user, $company, $input): ShareEvent {
            $data = $this->validate($input);
            $kind = ShareEventKind::from($data['kind']);

            return DB::transaction(function () use ($user, $company, $kind, $data): ShareEvent {
                $register = Register::locked();
                $classId = $this->classId($kind, $data);
                $toHolderId = $data['to_holder_id'];

                if ($kind === ShareEventKind::ReverseSplit) {
                    $toHolderId = self::companyItself($company)->id;
                }

                $recipient = $toHolderId === null ? null : Shareholder::query()->findOrFail($toHolderId);
                $effective = $kind->effectiveOnRegistration() ? $data['registered_on'] : $data['effective_date'];

                $event = new ShareEvent([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'sequence' => $register->last_sequence + 1,
                    'kind' => $kind,
                    'effective_date' => CarbonImmutable::parse((string) $effective),
                    'recorded_at' => EventHash::storable(CarbonImmutable::now()),
                    'decided_on' => $data['decided_on'],
                    'registered_on' => $data['registered_on'],
                    'share_from' => $kind->needsRange() || $kind === ShareEventKind::BonusIssue ? $data['share_from'] : null,
                    'share_to' => $kind->needsRange() || $kind === ShareEventKind::BonusIssue ? $data['share_to'] : null,
                    'share_class_id' => $classId,
                    'from_holder_id' => $kind->needsSender() || $kind === ShareEventKind::Redemption ? $data['from_holder_id'] : null,
                    'to_holder_id' => $toHolderId,
                    'holder_snapshot' => $recipient?->snapshot(),
                    'consideration_ore' => $data['consideration_ore'],
                    'capital_change_ore' => $data['capital_change_ore'],
                    'certificate_issued' => $kind === ShareEventKind::Certificate || $kind->issuesShares() ? (bool) $data['certificate_issued'] : null,
                    'ratio' => $kind->needsRatio() ? $data['ratio'] : null,
                    'refers_to_event_id' => $kind->refersToEvent() ? $data['refers_to_event_id'] : null,
                    'note' => $data['note'],
                    'document_reference' => $data['document_reference'],
                    'reason' => $data['reason'],
                    'user_id' => $user->id,
                    'previous_hash' => $register->head_hash,
                ]);
                $event->id = (int) DB::scalar("select nextval(pg_get_serial_sequence('boardroom_share_events', 'id'))");

                try {
                    RegisterFold::at([...ShareEvent::query()->orderBy('sequence')->get()->all(), $event]);
                } catch (RegisterException $exception) {
                    throw ValidationException::withMessages(['event' => $exception->getMessage()]);
                }

                $event->hash = EventHash::of($event, $register->head_hash);
                $event->created_at = CarbonImmutable::now();
                $event->save();

                $register->forceFill(['head_hash' => $event->hash, 'last_sequence' => $event->sequence])->save();

                Audit::record('share_register.recorded', $event, [
                    'kind' => $kind->value,
                    'sequence' => $event->sequence,
                    'effective_date' => $event->effective_date->toDateString(),
                    'shares' => $event->rangeLabel(),
                    'refers_to' => $event->refers_to_event_id,
                    'firm_user' => BoardroomAccess::isFirmUser($user, $company),
                ], $company, $user);

                return $event;
            });
        });
    }

    /**
     * Changes a holder's name or address: the row gets the new details and the
     * register an ADDRESS_CHANGE entry that keeps them, so the earlier ones
     * stay in the history (ABL 5 kap. 3 och 9 §§).
     *
     * @param  array<string, mixed>  $details
     */
    public function changeDetails(User $user, Company $company, Shareholder $holder, array $details, string $effectiveDate): ShareEvent
    {
        BoardroomAccess::authorizeEntry($user, $company);

        return CurrentCompany::run($company, fn (): ShareEvent => DB::transaction(function () use ($user, $company, $holder, $details, $effectiveDate): ShareEvent {
            app(SaveShareholder::class)->apply($holder, $details);

            return $this($user, $company, ['kind' => ShareEventKind::AddressChange->value, 'effective_date' => $effectiveDate, 'to_holder_id' => $holder->id]);
        }));
    }

    /** The company as holder of its own shares (ABL 4 kap. 50 §, 19 kap.), created on first use. */
    public static function companyItself(Company $company): Shareholder
    {
        return CurrentCompany::run($company, fn (): Shareholder => Shareholder::query()->firstOrCreate(
            ['kind' => HolderKind::CompanyItself->value],
            [
                'name' => (string) ($company->getAttribute('legal_name') ?: $company->name),
                'org_number' => $company->org_number,
                'address_line1' => (string) ($company->getAttribute('address_line1') ?: '–'),
                'postal_code' => (string) ($company->getAttribute('postal_code') ?: '–'),
                'city' => (string) ($company->getAttribute('city') ?: '–'),
                'country' => (string) ($company->getAttribute('country') ?: 'SE'),
            ],
        ));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{kind: string, effective_date: ?string, decided_on: ?string, registered_on: ?string, share_from: ?int, share_to: ?int, share_class_id: ?int, from_holder_id: ?int, to_holder_id: ?int, consideration_ore: ?int, capital_change_ore: ?int, certificate_issued: bool, ratio: ?int, refers_to_event_id: ?int, note: ?string, document_reference: ?string, reason: ?string}
     */
    private function validate(array $input): array
    {
        $kind = ShareEventKind::tryFrom((string) ($input['kind'] ?? ''));
        $holders = Rule::exists('boardroom_shareholders', 'id')->where('company_id', CurrentCompany::id());

        $validated = Validator::make($input, [
            'kind' => ['required', Rule::enum(ShareEventKind::class)],
            'effective_date' => [$kind?->effectiveOnRegistration() ? 'nullable' : 'required', 'date'],
            'decided_on' => ['nullable', 'date'],
            'registered_on' => [$kind?->effectiveOnRegistration() ? 'required' : 'nullable', 'date'],
            'share_from' => [$kind?->needsRange() ? 'required' : 'nullable', 'integer', 'min:1'],
            'share_to' => [$kind?->needsRange() ? 'required' : 'nullable', 'integer', 'min:1', 'gte:share_from'],
            'share_class_id' => ['nullable', 'integer', Rule::exists('boardroom_share_classes', 'id')->where('company_id', CurrentCompany::id())],
            'from_holder_id' => [$kind?->needsSender() ? 'required' : 'nullable', 'integer', $holders],
            'to_holder_id' => [$kind?->needsRecipient() ? 'required' : 'nullable', 'integer', $holders],
            'consideration_ore' => ['nullable', 'integer', 'min:0'],
            'capital_change_ore' => [$kind?->issuesShares() || in_array($kind, [ShareEventKind::BonusIssue, ShareEventKind::Redemption], true) ? 'required' : 'nullable', 'integer'],
            'certificate_issued' => ['nullable', 'boolean'],
            'ratio' => [$kind?->needsRatio() ? 'required' : 'nullable', 'integer', 'min:2', 'max:1000000'],
            'refers_to_event_id' => [$kind?->refersToEvent() ? 'required' : 'nullable', 'integer', Rule::exists('boardroom_share_events', 'id')->where('company_id', CurrentCompany::id())],
            'note' => [$kind?->isNote() ? 'required' : 'nullable', 'string', 'max:2000'],
            'document_reference' => ['nullable', 'string', 'max:255'],
            'reason' => [$kind === ShareEventKind::Correction ? 'required' : 'nullable', 'string', 'min:3', 'max:2000'],
        ], [], [
            'kind' => __('Händelse'),
            'effective_date' => __('Gäller från'),
            'registered_on' => __('Registrerad hos Bolagsverket'),
            'share_from' => __('Från aktie nummer'),
            'share_to' => __('Till aktie nummer'),
            'from_holder_id' => __('Från ägare'),
            'to_holder_id' => __('Till ägare'),
            'capital_change_ore' => __('Ändring av aktiekapitalet'),
            'ratio' => __('Kvot'),
            'refers_to_event_id' => __('Gäller händelse'),
            'note' => __('Anteckning'),
            'reason' => __('Skäl'),
        ])->validate();

        if ($kind?->issuesShares() && (int) $validated['capital_change_ore'] < 0) {
            throw ValidationException::withMessages(['capital_change_ore' => __('Aktiekapitalet för nya aktier kan inte vara negativt.')]);
        }

        $int = fn (string $key): ?int => isset($validated[$key]) && $validated[$key] !== '' ? (int) $validated[$key] : null;
        $text = fn (string $key): ?string => isset($validated[$key]) && trim((string) $validated[$key]) !== '' ? trim((string) $validated[$key]) : null;
        $date = fn (string $key): ?string => isset($validated[$key]) && $validated[$key] !== '' ? CarbonImmutable::parse((string) $validated[$key])->toDateString() : null;

        return [
            'kind' => (string) $validated['kind'],
            'effective_date' => $date('effective_date'),
            'decided_on' => $date('decided_on'),
            'registered_on' => $date('registered_on'),
            'share_from' => $int('share_from'),
            'share_to' => $int('share_to'),
            'share_class_id' => $int('share_class_id'),
            'from_holder_id' => $int('from_holder_id'),
            'to_holder_id' => $int('to_holder_id'),
            'consideration_ore' => $int('consideration_ore'),
            'capital_change_ore' => $int('capital_change_ore'),
            'certificate_issued' => (bool) ($validated['certificate_issued'] ?? false),
            'ratio' => $int('ratio'),
            'refers_to_event_id' => $int('refers_to_event_id'),
            'note' => $text('note'),
            'document_reference' => $text('document_reference'),
            'reason' => $text('reason'),
        ];
    }

    /**
     * The class for issued and converted shares: the one given, the only one
     * there is, or a first class "Aktier" with one vote when the articles
     * have none yet. With several classes the class must be named (rule
     * sheet AB-R3).
     *
     * @param  array{share_class_id: ?int}  $data
     */
    private function classId(ShareEventKind $kind, array $data): ?int
    {
        if (! $kind->issuesShares() && $kind !== ShareEventKind::Conversion && $kind !== ShareEventKind::BonusIssue) {
            return null;
        }

        if ($data['share_class_id'] !== null) {
            return $data['share_class_id'];
        }

        if ($kind === ShareEventKind::Conversion) {
            throw ValidationException::withMessages(['share_class_id' => __('Ange vilket aktieslag aktierna omvandlas till.')]);
        }

        $classes = ShareClass::query()->pluck('id');

        if ($classes->count() > 1) {
            throw ValidationException::withMessages(['share_class_id' => __('Bolaget har flera aktieslag. Ange vilket aktierna tillhör.')]);
        }

        return $classes->first() ?? ShareClass::query()->create(['code' => 'A', 'name' => __('Aktier'), 'votes_tenths' => 10])->id;
    }
}
