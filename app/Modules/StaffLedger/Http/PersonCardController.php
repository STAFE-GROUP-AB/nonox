<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\StaffLedger\Domain\QrToken;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\Support\QrSvg;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * A person's printable QR card and their kiosk photo, for managers.
 * Another company's person answers 404.
 */
class PersonCardController
{
    public function card(int $person): View
    {
        $model = $this->person($person);

        return view('staff-ledger::card', [
            'person' => $model,
            'qr' => QrSvg::render(QrToken::for($model)),
            'company' => Company::query()->findOrFail($model->company_id),
        ]);
    }

    public function photo(int $person): Response
    {
        $model = $this->person($person);
        abort_if($model->photo_path === null || $model->photo_consent_at === null, 404);

        return response((string) Storage::disk('local')->get($model->photo_path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function person(int $id): Person
    {
        $person = Person::query()->findOrFail($id);
        /** @var User $user */
        $user = Auth::user();
        $company = Company::query()->findOrFail($person->company_id);

        abort_unless(Gate::forUser($user)->allows('manage', [Person::class, $company]), 404);

        return $person;
    }
}
