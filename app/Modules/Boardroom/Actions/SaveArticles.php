<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Boardroom\Models\ArticlesVersion;
use App\Modules\Boardroom\Models\Register;
use App\Modules\Boardroom\Models\ShareClass;
use App\Modules\Boardroom\Models\ShareEvent;
use App\Modules\Boardroom\Support\BoardroomAccess;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The articles of association as versioned, structured data (rule sheet
 * G.11) and the share classes they define. A new version is adopted by a
 * general meeting and applies only from its registration (ABL 3 kap. 5 §):
 * the registration date is entered once, when it is known. A version is
 * never edited; a change is a new version.
 */
final class SaveArticles
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function adopt(User $user, Company $company, array $input): ArticlesVersion
    {
        $this->authorize($user, $company);

        $data = Validator::make($input, [
            'adopted_on' => ['required', 'date'],
            'registered_on' => ['nullable', 'date', 'after_or_equal:adopted_on'],
            'seat' => ['nullable', 'string', 'max:255'],
            'notice_method' => ['required', 'string', 'min:3', 'max:2000'],
            'short_notice_clause' => ['nullable', 'boolean'],
            'share_capital_min' => ['nullable', 'string', 'max:30'],
            'share_capital_max' => ['nullable', 'string', 'max:30'],
            'shares_min' => ['nullable', 'integer', 'min:1'],
            'shares_max' => ['nullable', 'integer', 'min:1'],
            'board_min' => ['nullable', 'integer', 'min:1', 'max:100'],
            'board_max' => ['nullable', 'integer', 'min:1', 'max:100'],
            'has_auditor' => ['nullable', 'boolean'],
            'pre_emption_clause' => ['nullable', 'boolean'],
            'consent_clause' => ['nullable', 'boolean'],
            'redemption_clause' => ['nullable', 'boolean'],
            'conversion_clause' => ['nullable', 'boolean'],
            'other_provisions' => ['nullable', 'string', 'max:10000'],
        ], [], [
            'adopted_on' => __('Antagen'),
            'registered_on' => __('Registrerad'),
            'notice_method' => __('Så kallas aktieägarna'),
        ])->validate();

        return CurrentCompany::run($company, fn (): ArticlesVersion => DB::transaction(function () use ($user, $company, $data): ArticlesVersion {
            Register::locked();
            $version = (int) ArticlesVersion::query()->max('version') + 1;
            $int = fn (string $key): ?int => isset($data[$key]) && $data[$key] !== '' ? (int) $data[$key] : null;
            $bool = fn (string $key): bool => (bool) ($data[$key] ?? false);

            $articles = ArticlesVersion::query()->create([
                'version' => $version,
                'adopted_on' => CarbonImmutable::parse((string) $data['adopted_on'])->toDateString(),
                'registered_on' => isset($data['registered_on']) && $data['registered_on'] !== '' ? CarbonImmutable::parse((string) $data['registered_on'])->toDateString() : null,
                'seat' => isset($data['seat']) ? trim((string) $data['seat']) : null,
                'notice_method' => trim((string) $data['notice_method']),
                'short_notice_clause' => $bool('short_notice_clause'),
                'share_capital_min_ore' => self::money($data['share_capital_min'] ?? null, 'share_capital_min'),
                'share_capital_max_ore' => self::money($data['share_capital_max'] ?? null, 'share_capital_max'),
                'shares_min' => $int('shares_min'),
                'shares_max' => $int('shares_max'),
                'board_min' => $int('board_min'),
                'board_max' => $int('board_max'),
                'has_auditor' => $bool('has_auditor'),
                'pre_emption_clause' => $bool('pre_emption_clause'),
                'consent_clause' => $bool('consent_clause'),
                'redemption_clause' => $bool('redemption_clause'),
                'conversion_clause' => $bool('conversion_clause'),
                'other_provisions' => isset($data['other_provisions']) && trim((string) $data['other_provisions']) !== '' ? trim((string) $data['other_provisions']) : null,
                'created_by' => $user->id,
            ]);

            Audit::record('boardroom.articles_adopted', $articles, ['version' => $version, 'adopted_on' => $articles->adopted_on->toDateString()], $company, $user);

            return $articles;
        }));
    }

    public function registerVersion(User $user, Company $company, ArticlesVersion $articles, string $date): ArticlesVersion
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, function () use ($user, $company, $articles, $date): ArticlesVersion {
            if ($articles->registered_on !== null) {
                throw ValidationException::withMessages(['registered_on' => __('Versionen är redan registrerad.')]);
            }

            $valid = Validator::make(['registered_on' => $date], ['registered_on' => ['required', 'date', 'after_or_equal:'.$articles->adopted_on->toDateString(), 'before_or_equal:today']], [], ['registered_on' => __('Registrerad')])->validate();
            $articles->registered_on = CarbonImmutable::parse((string) $valid['registered_on']);
            $articles->save();

            Audit::record('boardroom.articles_registered', $articles, ['version' => $articles->version, 'registered_on' => $articles->registered_on->toDateString()], $company, $user);

            return $articles;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveClass(User $user, Company $company, array $input, ?ShareClass $class = null): ShareClass
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, function () use ($user, $company, $input, $class): ShareClass {
            $data = Validator::make($input, [
                'code' => ['required', 'string', 'max:20', Rule::unique('boardroom_share_classes', 'code')->where('company_id', $company->id)->ignore($class?->id)],
                'name' => ['required', 'string', 'max:255'],
                'votes' => ['required', 'string', 'regex:/^\d{1,4}([.,]\d)?$/'],
                'restriction_text' => ['nullable', 'string', 'max:2000'],
            ], ['votes.regex' => __('Ange röster per aktie, till exempel 1, 10 eller 0,1.')], [
                'code' => __('Beteckning'),
                'name' => __('Namn'),
                'votes' => __('Röster per aktie'),
            ])->validate();

            [$whole, $fraction] = array_pad(preg_split('/[.,]/', (string) $data['votes']) ?: ['0'], 2, '0');
            $tenths = (int) $whole * 10 + (int) $fraction;

            if ($tenths < 1) {
                throw ValidationException::withMessages(['votes' => __('En aktie har minst en tiondels röst (aktiebolagslagen 4 kap. 5 §).')]);
            }

            $code = strtoupper(trim((string) $data['code']));

            if ($class !== null && ShareEvent::query()->where('share_class_id', $class->id)->exists() && ($code !== $class->code || $tenths !== $class->votes_tenths)) {
                throw ValidationException::withMessages(['votes' => __('Aktieslaget finns i aktieboken. Beteckning och röster ändras inte i efterhand: för in en ny bolagsordning med ett nytt aktieslag och en omvandling.')]);
            }

            $class ??= new ShareClass(['articles_version_id' => ArticlesVersion::query()->orderByDesc('version')->value('id')]);
            $class->fill([
                'code' => $code,
                'name' => trim((string) $data['name']),
                'votes_tenths' => $tenths,
                'restriction_text' => isset($data['restriction_text']) && trim((string) $data['restriction_text']) !== '' ? trim((string) $data['restriction_text']) : null,
            ])->save();

            Audit::record('boardroom.share_class_saved', $class, ['code' => $class->code, 'votes_tenths' => $tenths], $company, $user);

            return $class;
        });
    }

    private static function money(mixed $value, string $field): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Money::parse($value)->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Skriv beloppet i kronor, till exempel 25 000.')]);
        }
    }

    /** The articles and classes are part of the register: the same 5:9 gate as an entry. */
    private function authorize(User $user, Company $company): void
    {
        BoardroomAccess::authorizeEntry($user, $company);
    }
}
