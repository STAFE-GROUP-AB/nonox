<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Signing\SignableTypes;
use App\Domain\Signing\SigningHooks;
use App\Domain\Signing\SigningToken;
use App\Http\Controllers\Signing\SigningPageController;
use App\Http\Middleware\WithoutAuditRequest;
use App\Livewire\Signing\SigningPanel;
use App\Models\SalesDocument;
use App\Models\SigningRequest;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Signing at a link (ADR-046), core since ADR-075 §7: the registry of
 * signable document types (the quote is the core's own; CRM adds the
 * agreement, a module its own), the follow-up hooks, the "Skicka för
 * signering" panel and the public signing page /signera/{token}.
 *
 * The page has no login: the token is the only key, and the page itself
 * refuses a link whose document's module is off or whose company is
 * archived. Rate-limited per IP address, and harder for the form posts.
 */
class SigningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SignableTypes::class, function (): SignableTypes {
            $types = new SignableTypes;
            $types->register(SigningRequest::QUOTE, 'sales_document_id', function (string $key, bool $lock): ?SalesDocument {
                $query = SalesDocument::query()->where('kind', SalesDocument::QUOTE)->whereKey($key);

                return ($lock ? $query->lockForUpdate() : $query)->first();
            });

            return $types;
        });

        $this->app->singleton(SigningHooks::class);
    }

    public function boot(): void
    {
        Livewire::component(SigningPanel::NAME, SigningPanel::class);

        RateLimiter::for('crm-signing', fn (Request $request): Limit => Limit::perMinute(60)->by('crm-signing:'.$request->ip()));
        RateLimiter::for('crm-signing-submit', fn (Request $request): Limit => Limit::perMinute(10)->by('crm-signing-submit:'.$request->ip()));

        Route::middleware(['web', 'throttle:crm-signing', WithoutAuditRequest::class])->group(function (): void {
            Route::get('/signera/{token}', [SigningPageController::class, 'show'])->where('token', '[^/]{1,100}')->name('signing.show');
            Route::get('/signera/{token}/dokument', [SigningPageController::class, 'document'])->where('token', SigningToken::PATTERN)->name('signing.document');
            Route::post('/signera/{token}', [SigningPageController::class, 'sign'])->where('token', SigningToken::PATTERN)->middleware('throttle:crm-signing-submit')->name('signing.sign');
            Route::post('/signera/{token}/avboj', [SigningPageController::class, 'decline'])->where('token', SigningToken::PATTERN)->middleware('throttle:crm-signing-submit')->name('signing.decline');
        });
    }
}
