<?php

declare(strict_types=1);

use App\Domain\Signing\SigningToken;
use App\Modules\Portal\Http\Controllers\AccountController;
use App\Modules\Portal\Http\Controllers\DocumentsController;
use App\Modules\Portal\Http\Controllers\QuestionsController;
use App\Modules\Portal\Http\Controllers\SignInController;
use App\Modules\Portal\Http\Middleware\AuthenticatePortal;
use App\Modules\Portal\Http\Middleware\ResolvePortal;
use App\Modules\Portal\Models\PortalSettings;
use Illuminate\Support\Facades\Route;

/*
 * The customer portal (ADR-075 §10): Swedish paths under /kundportal, route
 * names under portal., its own guard and session cookie. Loaded by
 * PortalServiceProvider inside the web group and PortalHeaders.
 */

Route::get('/kundportal', [SignInController::class, 'show'])->name('portal.login');
Route::post('/kundportal', [SignInController::class, 'send'])->middleware('throttle:portal-sign-in')->name('portal.send');
Route::get('/kundportal/kod', [SignInController::class, 'code'])->name('portal.code');
Route::post('/kundportal/kod', [SignInController::class, 'verify'])->middleware('throttle:portal-sign-in')->name('portal.verify');

Route::middleware('throttle:portal-invitation')->group(function (): void {
    Route::get('/kundportal/inbjudan/{token}', [SignInController::class, 'invitation'])->where('token', '[^/]{1,100}')->name('portal.invitation');
    Route::post('/kundportal/inbjudan/{token}', [SignInController::class, 'sendInvitationCode'])->where('token', SigningToken::PATTERN)->name('portal.invitation.send');
    Route::post('/kundportal/inbjudan/{token}/kod', [SignInController::class, 'acceptInvitation'])->where('token', SigningToken::PATTERN)->name('portal.invitation.accept');
});

Route::get('/kundportal/{portalKey}/integritet', [AccountController::class, 'privacy'])->where('portalKey', PortalSettings::KEY_PATTERN)->name('portal.privacy');

Route::middleware([AuthenticatePortal::class, 'throttle:portal-pages'])->group(function (): void {
    Route::get('/kundportal/valj', [AccountController::class, 'choose'])->name('portal.choose');
    Route::post('/kundportal/valj', [AccountController::class, 'enter'])->name('portal.enter');
    Route::get('/kundportal/mina-uppgifter', [AccountController::class, 'mine'])->name('portal.mine');
    Route::post('/kundportal/mina-uppgifter', [AccountController::class, 'rename'])->name('portal.mine.rename');
    Route::get('/kundportal/mina-uppgifter/export', [AccountController::class, 'export'])->middleware('throttle:portal-downloads')->name('portal.mine.export');
    Route::post('/kundportal/mina-uppgifter/ta-bort', [AccountController::class, 'remove'])->name('portal.mine.remove');
    Route::post('/kundportal/logga-ut', [SignInController::class, 'logout'])->name('portal.logout');

    Route::prefix('/kundportal/{portalKey}')->where(['portalKey' => PortalSettings::KEY_PATTERN])->middleware(ResolvePortal::class)->group(function (): void {
        Route::get('/', [DocumentsController::class, 'home'])->name('portal.home');
        Route::get('/fakturor', [DocumentsController::class, 'invoices'])->name('portal.invoices');
        Route::get('/fakturor/{number}', [DocumentsController::class, 'invoice'])->whereNumber('number')->name('portal.invoices.show');
        Route::get('/fakturor/{number}/pdf', [DocumentsController::class, 'invoicePdf'])->whereNumber('number')->middleware('throttle:portal-downloads')->name('portal.invoices.pdf');
        Route::post('/fakturor/{number}/betald', [DocumentsController::class, 'markPaid'])->whereNumber('number')->middleware('throttle:portal-messages')->name('portal.invoices.paid');
        Route::get('/kontoutdrag', [DocumentsController::class, 'statement'])->name('portal.statement');
        Route::get('/kontoutdrag/pdf', [DocumentsController::class, 'statementPdf'])->middleware('throttle:portal-downloads')->name('portal.statement.pdf');
        Route::get('/offerter', [DocumentsController::class, 'quotes'])->name('portal.quotes');
        Route::get('/offerter/{document}', [DocumentsController::class, 'quote'])->whereUuid('document')->name('portal.quotes.show');
        Route::get('/offerter/{document}/pdf', [DocumentsController::class, 'quotePdf'])->whereUuid('document')->middleware('throttle:portal-downloads')->name('portal.quotes.pdf');
        Route::post('/offerter/{document}', [DocumentsController::class, 'decideQuote'])->whereUuid('document')->middleware('throttle:portal-messages')->name('portal.quotes.decide');
        Route::get('/fragor', [QuestionsController::class, 'index'])->name('portal.questions');
        Route::get('/fragor/ny', [QuestionsController::class, 'create'])->name('portal.questions.create');
        Route::post('/fragor', [QuestionsController::class, 'store'])->middleware('throttle:portal-messages')->name('portal.questions.store');
        Route::get('/fragor/{thread}', [QuestionsController::class, 'show'])->whereNumber('thread')->name('portal.questions.show');
        Route::post('/fragor/{thread}', [QuestionsController::class, 'reply'])->whereNumber('thread')->middleware('throttle:portal-messages')->name('portal.questions.reply');
        Route::get('/fragor/{thread}/bilagor/{attachment}', [QuestionsController::class, 'attachment'])->whereNumber('thread')->whereNumber('attachment')->middleware('throttle:portal-downloads')->name('portal.questions.attachment');
    });
});
