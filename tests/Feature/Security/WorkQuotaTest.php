<?php

declare(strict_types=1);

use App\Actions\Invoicing\ImportBankFile;
use App\Support\WorkQuota;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

it('stops a company that repeats heavy work too often within an hour, without touching other companies', function () {
    [$owner, $company] = Bookkeeping::company();
    [, $other] = Bookkeeping::company();

    foreach (range(1, WorkQuota::LIMITS['bank_file']) as $ignored) {
        WorkQuota::take($company, 'bank_file', 'file');
    }

    expect(fn () => app(ImportBankFile::class)($owner, $company, UploadedFile::fake()->createWithContent('bank.xml', '<x/>')))
        ->toThrow(ValidationException::class, 'Det här har gjorts många gånger den senaste timmen.');

    WorkQuota::take($other, 'bank_file', 'file');

    RateLimiter::clear("work-quota:bank_file:{$company->id}");
    RateLimiter::clear("work-quota:bank_file:{$other->id}");
});
