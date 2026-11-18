<?php

declare(strict_types=1);

namespace App\Modules\Signing\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\SigningModule;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The first step of the send wizard (ADR-046 amendment, gap 10, package S7):
 * an ordinary form post of the PDF, so the limit is the 20 MB of owner
 * decision 22 and not the 12 MB that Livewire's temporary uploads allow every
 * component. The file is checked by what it is (StoreSigningDocument), kept
 * as a standalone document and the wizard continues with it. A file that PHP
 * itself refused (too large for the server's settings) never arrives; the
 * message says so.
 */
final class UploadController
{
    public function __invoke(Request $request, StoreSigningDocument $store): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Company $company */
        $company = $user->currentCompany();
        abort_unless($user->hasTeamPermission($company, SigningModule::SEND), 403);

        $file = $request->file('file');

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return back()->withInput($request->except('file'))->withErrors(['file' => __('Filen kom inte fram. Den kan vara för stor eller så avbröts uppladdningen. Välj en PDF på högst 20 MB.')]);
        }

        $title = (string) $request->input('title', '');
        $title = trim($title) === '' ? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) : $title;

        try {
            $document = $store(
                $user,
                $company,
                (string) $file->getRealPath(),
                $file->getClientOriginalName(),
                $title,
                $this->id($request->input('customer_id')),
                $this->id($request->input('supplier_id')),
            );
        } catch (ValidationException $exception) {
            return back()->withInput($request->except('file'))->withErrors($exception->errors());
        }

        Toast::flash(__('Dokumentet är uppladdat. Välj nu vilka som ska signera.'));

        return redirect()->route('signing.new', ['typ' => 'DOCUMENT', 'dokument' => $document->id]);
    }

    private function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
