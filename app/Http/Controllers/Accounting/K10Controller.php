<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Actions\IncomeTax\K10Worksheets;
use App\Models\Company;
use App\Models\K10Owner;
use App\Models\User;
use App\Support\Toast;
use App\Support\WorkQuota;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * One owner's K10 worksheet as a page, a PDF and an SRU file (ADR-062). The
 * owner is resolved within the current company, so another company's owner
 * answers 404; without fiscal-years:manage the answer is 403.
 */
class K10Controller
{
    public function show(Request $request, K10Owner $k10Owner, K10Worksheets $worksheets): Response
    {
        [$user, $company] = $this->resolve($request, $k10Owner);

        return response($this->run(fn (): string => $worksheets->html($user, $company, $k10Owner)), 200, [
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'self'; base-uri 'none'; form-action 'none'",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function pdf(Request $request, K10Owner $k10Owner, K10Worksheets $worksheets): Response|RedirectResponse
    {
        [$user, $company] = $this->resolve($request, $k10Owner);

        if (! WorkQuota::attempt($company, 'pdf')) {
            Toast::flash(WorkQuota::message(), 'error');

            return back();
        }

        return response($this->run(fn (): string => $worksheets->pdf($user, $company, $k10Owner)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="K10-underlag-'.$k10Owner->income_year.'-'.$k10Owner->id.'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function sru(Request $request, K10Owner $k10Owner, K10Worksheets $worksheets): Response|RedirectResponse
    {
        [$user, $company] = $this->resolve($request, $k10Owner);

        try {
            $zip = $this->run(fn (): string => $worksheets->sruZip($user, $company, $k10Owner));
        } catch (ValidationException $exception) {
            Toast::flash((string) collect($exception->errors())->flatten()->first(), 'error');

            return redirect()->route('accounting.k10', ['ar' => $k10Owner->income_year]);
        }

        return response($zip, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="SRU-K10-'.$k10Owner->income_year.'-'.$k10Owner->id.'.zip"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @return array{0: User, 1: Company} */
    private function resolve(Request $request, K10Owner $owner): array
    {
        /** @var User $user */
        $user = $request->user();
        $company = Company::query()->findOrFail($owner->company_id);

        abort_unless($user->hasTeamPermission($company, 'fiscal-years:manage'), 403);

        return [$user, $company];
    }

    /**
     * @param  callable(): string  $make
     */
    private function run(callable $make): string
    {
        try {
            return $make();
        } catch (AuthorizationException) {
            abort(403);
        }
    }
}
