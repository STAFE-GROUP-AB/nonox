<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Models\Company;
use App\Modules\Crm\Actions\SubmitForm;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\Form;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * A company's web form at /formular/{token} (ADR-048), on its own or in an
 * iframe on the sites the company listed.
 *
 * The page runs outside the web middleware: no session, no cookie, no
 * CSRF token (a form on another site could not carry one), and it loads
 * nothing from anywhere else. Spam is kept out without third-party
 * scripts: a honeypot field, a signed time stamp (a form sent within three
 * seconds or after a day is not taken), and limits per address and form,
 * per form and per company. A submission caught by these gets the same
 * thank-you page as a real one and nothing is stored.
 */
class PublicFormController
{
    public const int MIN_SECONDS = 3;

    public const int MAX_SECONDS = 86_400;

    public function show(Request $request, string $token, ModuleManager $modules): Response
    {
        $form = $this->form($token, $modules);

        return $this->page($form, ['values' => [], 'errors' => [], 'stamp' => self::stamp($form)]);
    }

    public function submit(Request $request, string $token, ModuleManager $modules, SubmitForm $submit): Response
    {
        $form = $this->form($token, $modules);

        if (! $this->looksHuman($request, $form) || ! $this->withinLimits($request, $form)) {
            return $this->page($form, ['done' => true]);
        }

        $input = $request->only(['name', 'email', 'phone', 'company', 'org_number', 'message', 'consent']);
        $errors = $submit($form, $input);

        if ($errors !== []) {
            return $this->page($form, ['values' => $input, 'errors' => $errors, 'stamp' => self::stamp($form)], 422);
        }

        return $this->page($form, ['done' => true]);
    }

    public static function stamp(Form $form, ?int $time = null): string
    {
        $time ??= now()->getTimestamp();

        return $time.'.'.substr(hash_hmac('sha256', "crm-form:{$form->token}:{$time}", (string) config('app.key')), 0, 32);
    }

    private function form(string $token, ModuleManager $modules): Form
    {
        abort_unless(preg_match('/^[a-z0-9]{32}$/', $token) === 1, 404);

        // A public page names no company: the token finds the form, which then decides the company.
        $form = CompanyScope::bypass(fn (): ?Form => Form::query()->where('token', $token)->first());
        $company = $form === null ? null : Company::query()->find($form->company_id);

        abort_unless($form instanceof Form && $company instanceof Company && ! $company->isArchived() && $modules->isEnabled(CrmModule::KEY, $company), 404);

        return $form;
    }

    private function looksHuman(Request $request, Form $form): bool
    {
        if (filled($request->input('webbplats'))) {
            return false;
        }

        [$time, $signature] = array_pad(explode('.', (string) $request->input('t', ''), 2), 2, '');

        if (! ctype_digit($time) || ! hash_equals(self::stamp($form, (int) $time), "{$time}.{$signature}")) {
            return false;
        }

        $age = now()->getTimestamp() - (int) $time;

        return $age >= self::MIN_SECONDS && $age <= self::MAX_SECONDS;
    }

    private function withinLimits(Request $request, Form $form): bool
    {
        $keys = [
            'crm-form-ip:'.$form->id.':'.hash('sha256', (string) $request->ip()) => [5, 600],
            'crm-form:'.$form->id => [60, 3600],
            'crm-form-company:'.$form->company_id => [200, 3600],
        ];

        foreach ($keys as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return false;
            }
        }

        foreach ($keys as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }

        return true;
    }

    /** @param  array<string, mixed>  $data */
    private function page(Form $form, array $data, int $status = 200): Response
    {
        $nonce = Str::random(24);
        $company = Company::query()->find($form->company_id);
        $origins = $form->embed_origins ?? [];

        return response()->view('crm::public.form', [...$data, 'form' => $form, 'company' => $company, 'nonce' => $nonce, 'done' => $data['done'] ?? false], $status)
            ->header('Content-Security-Policy', implode('; ', [
                "default-src 'none'",
                "style-src 'unsafe-inline'",
                "script-src 'nonce-{$nonce}'",
                "form-action 'self'",
                "base-uri 'none'",
                'frame-ancestors '.($origins === [] ? "'none'" : implode(' ', $origins)),
            ]))
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store');
    }
}
