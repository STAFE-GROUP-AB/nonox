<?php

declare(strict_types=1);

namespace App\Providers;

use App\Livewire\Company\MemberManager;
use App\Livewire\Profile\ProfileInformationForm;
use App\Support\Audit\AuditSubscriber;
use App\Support\Files\EncryptedAdapter;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\LivewireCompanyGuard;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
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

        // Uploads are read from their path, so they wait on a plain disk (config/filesystems.php).
        config(['livewire.temporary_file_upload.disk' => 'uploads']);

        $this->app->scoped(CompanyContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        // Company and person names reach mail texts; escape Markdown so a name cannot become a link.
        Markdown::withSecuredEncoding();

        // Private files are encrypted at rest (config/filesystems.php).
        Storage::extend('encrypted', function ($app, array $config): FilesystemAdapter {
            $adapter = new EncryptedAdapter(new LocalFilesystemAdapter((string) $config['root'], PortableVisibilityConverter::fromArray([], Visibility::PRIVATE)), $app['encrypter']);

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });

        Event::listen([Login::class, Logout::class], fn () => CurrentCompany::forget());
        Event::subscribe(AuditSubscriber::class);

        // Jetstream's components, extended with toasts (and a permission check
        // Jetstream lacks). App providers boot after Jetstream's, so these win.
        Livewire::component('teams.team-member-manager', MemberManager::class);
        Livewire::component('profile.update-profile-information-form', ProfileInformationForm::class);
    }
}
