<?php

declare(strict_types=1);

namespace App\Providers;

use App\Livewire\Company\MemberManager;
use App\Livewire\Profile\ProfileInformationForm;
use App\Support\Audit\AuditSubscriber;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\LivewireCompanyGuard;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Registered before Livewire boots, which binds its hooks at boot time.
        Livewire::componentHook(LivewireCompanyGuard::class);

        $this->app->scoped(CompanyContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Event::listen([Login::class, Logout::class], fn () => CurrentCompany::forget());
        Event::subscribe(AuditSubscriber::class);

        // Jetstream's components, extended with toasts (and a permission check
        // Jetstream lacks). App providers boot after Jetstream's, so these win.
        Livewire::component('teams.team-member-manager', MemberManager::class);
        Livewire::component('profile.update-profile-information-form', ProfileInformationForm::class);
    }
}
