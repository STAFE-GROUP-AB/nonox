<?php

declare(strict_types=1);

namespace App\Modules\Planning;

use App\Http\Middleware\EnsureUserHasCompany;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\ModuleRegistry;
use App\Modules\Planning\Livewire\AttestPage;
use App\Modules\Planning\Livewire\MySchedule;
use App\Modules\Planning\Livewire\PlanningSettingsForm;
use App\Modules\Planning\Livewire\RequestsPage;
use App\Modules\Planning\Livewire\SchedulePage;
use App\Modules\Planning\Livewire\SetupPage;
use App\Modules\Planning\Livewire\StaffingPage;
use App\Modules\Planning\Livewire\TemplatesPage;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Attestation;
use App\Modules\Planning\Models\Availability;
use App\Modules\Planning\Models\IcalToken;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\ShiftRequest;
use App\Modules\Planning\Models\Skill;
use App\Modules\Planning\Models\Template;
use App\Modules\Planning\Policies\PlanningPolicy;
use App\Modules\Planning\Policies\SelfServicePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers Planering (PLANNING_MODULE.md). Its pages carry module:planning
 * and answer 404 while it is off.
 */
class PlanningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new PlanningModule);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'planning');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        foreach ([Shift::class, Area::class, Skill::class, Template::class, Attestation::class] as $model) {
            Gate::policy($model, PlanningPolicy::class);
        }

        foreach ([Availability::class, ShiftRequest::class, IcalToken::class] as $model) {
            Gate::policy($model, SelfServicePolicy::class);
        }

        Livewire::component(PlanningSettingsForm::NAME, PlanningSettingsForm::class);
        Livewire::component('planning.schedule', SchedulePage::class);
        Livewire::component('planning.templates', TemplatesPage::class);
        Livewire::component('planning.setup', SetupPage::class);
        Livewire::component('planning.my-schedule', MySchedule::class);
        Livewire::component('planning.requests', RequestsPage::class);
        Livewire::component('planning.staffing', StaffingPage::class);
        Livewire::component('planning.attest', AttestPage::class);

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.PlanningModule::KEY])
            ->group(__DIR__.'/routes/web.php');

        RateLimiter::for('planning-ical', fn (Request $request): Limit => Limit::perMinute(30)->by('planning-ical:'.$request->ip()));
        Route::middleware([SecurityHeaders::class, 'throttle:planning-ical'])->group(__DIR__.'/routes/ical.php');
    }
}
