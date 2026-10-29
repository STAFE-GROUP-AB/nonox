<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounting\SieImport\SieImportStatus;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\SieImport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController
{
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Company $company */
        $company = $user->currentCompany();

        $hasDetails = filled($company->address_line1)
            && (filled($company->bankgiro) || filled($company->plusgiro));

        $hasPeople = $company->users()->exists() || $company->teamInvitations()->exists();

        $importedYears = SieImport::query()
            ->where('sie_imports.status', SieImportStatus::Completed->value)
            ->join('fiscal_years', 'fiscal_years.id', '=', 'sie_imports.fiscal_year_id')
            ->orderByDesc('fiscal_years.start_date')
            ->get(['fiscal_years.start_date', 'fiscal_years.end_date'])
            ->map(fn (SieImport $import): string => (new FiscalYear(['start_date' => $import->getAttribute('start_date'), 'end_date' => $import->getAttribute('end_date')]))->label())
            ->unique()
            ->values();

        return view('dashboard', [
            'user' => $user,
            'company' => $company,
            'canManage' => $user->can('update', $company),
            'steps' => [
                [
                    'done' => true,
                    'title' => __('Skapa företaget'),
                    'text' => __(':company finns nu i systemet.', ['company' => $company->name]),
                    'link' => null,
                    'soon' => false,
                ],
                [
                    'done' => $hasDetails,
                    'title' => __('Fyll i adress och bankgiro'),
                    'text' => __('Behövs på dina fakturor, så att kunderna vet vart de ska betala.'),
                    'link' => route('teams.show', $company->id),
                    'soon' => false,
                ],
                [
                    'done' => $hasPeople,
                    'title' => __('Bjud in din redovisningskonsult eller en kollega'),
                    'text' => __('Valfritt. De får en inbjudan via e-post och loggar in med en kod, precis som du.'),
                    'link' => route('teams.show', $company->id),
                    'soon' => false,
                ],
                [
                    'done' => $importedYears->isNotEmpty(),
                    'title' => __('Flytta in bokföringen från ditt gamla program'),
                    'text' => $importedYears->isEmpty()
                        ? __('Med en SIE-fil från ditt nuvarande bokföringsprogram får du med kontoplan, verifikationer och saldon.')
                        : __('Inläst: :years. Har du fler år läser du in dem på samma sätt.', ['years' => $importedYears->implode(', ')]),
                    'link' => route('accounting.sieImports.index'),
                    'allowed' => $user->hasTeamPermission($company, 'import:sie'),
                    'soon' => false,
                ],
                [
                    'done' => Invoice::query()->whereNotNull('number')->exists(),
                    'title' => __('Skicka din första faktura'),
                    'text' => __('Lägg upp kunden, skriv fakturan och skicka den som PDF. Den bokförs automatiskt, med OCR-nummer för betalningen.'),
                    'link' => route('invoicing.invoices.create'),
                    'allowed' => $user->hasTeamPermission($company, 'invoices:create'),
                    'soon' => false,
                ],
            ],
        ]);
    }
}
