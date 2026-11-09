<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Company\ReconcileFirmLinks;
use App\Actions\Mileage\BookOwnerMileage;
use App\Domain\Closing\DeferredTax\BuildingCarryingAmounts;
use App\Domain\Closing\DeferredTax\NoBuildingCarryingAmounts;
use App\Domain\Deadlines\CompanyDeadlines;
use App\Domain\Overview\TodoProviders;
use App\Events\UserAccountDeleting;
use App\Events\VoucherPosted;
use App\Http\Middleware\SecurityHeaders;
use App\Livewire\Company\MemberManager;
use App\Livewire\Profile\ProfileInformationForm;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\AuditSubscriber;
use App\Support\Build\VersionRecorder;
use App\Support\Chat\SecureChat;
use App\Support\Database\AfterMigrate;
use App\Support\Database\DatabaseRoles;
use App\Support\Files\EncryptedAdapter;
use App\Support\Guide\ForgetUserDeadlines;
use App\Support\Instance\InstanceSettings;
use App\Support\Overview\TaskTodoProvider;
use App\Support\Status\Heartbeats;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\LivewireCompanyGuard;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use Livewire\Livewire;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Mina datum (ADR-051, stage 2): one engine per request or job, so the overview's two readers share its answer.
        $this->app->scoped(CompanyDeadlines::class);
        // Registered before Livewire boots, which binds its hooks at boot time.
        Livewire::componentHook(LivewireCompanyGuard::class);

        // Uploads are read from their path, so they wait on a plain disk (config/filesystems.php).
        config(['livewire.temporary_file_upload.disk' => 'uploads']);

        $this->app->scoped(CompanyContext::class);

        // Deferred tax reads a building's carrying amount from the component register (ADR-059, A.2.6); S1-P4 binds its own.
        $this->app->bindIf(BuildingCarryingAmounts::class, NoBuildingCarryingAmounts::class);

        // The installation's own settings from /hq (ADR-044): read once per request or job.
        $this->app->scoped(InstanceSettings::class);

        // "Att göra" on a company's Översikt includes the tasks assigned to the user (ADR-028).
        $this->app->tag([TaskTodoProvider::class], TodoProviders::TAG);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        // A bearer token counts only on the API (/api/*). Every page, modules'
        // included, needs a signed-in session, so a token can never stand in
        // for one or outlive its 12-hour limit (security review 2026-11-01).
        Sanctum::getAccessTokenFromRequestUsing(fn (Request $request): ?string => $request->is('api/*') ? $request->bearerToken() : null);

        // Company and person names reach mail texts; escape Markdown so a name cannot become a link.
        Markdown::withSecuredEncoding();

        // Private files are encrypted at rest (config/filesystems.php).
        Storage::extend('encrypted', function ($app, array $config): FilesystemAdapter {
            $adapter = new EncryptedAdapter(new LocalFilesystemAdapter((string) $config['root'], PortableVisibilityConverter::fromArray([], Visibility::PRIVATE)), $app['encrypter']);

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });

        Event::listen([Login::class, Logout::class], fn () => CurrentCompany::forget());

        // When the scheduled jobs last ran, for the public status page (ADR-078).
        Event::listen(ScheduledTaskFinished::class, Heartbeats::finished(...));
        Event::listen(ScheduledTaskFailed::class, Heartbeats::failed(...));
        // Signing out ends the user's encrypted chats, and everything in them is deleted (ADR-045).
        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user instanceof User) {
                app(SecureChat::class)->endAllFor($event->user);
            }
        });
        // The chat polls every two seconds while it is open; bounded per user, on top of its own join limit.
        RateLimiter::for('chat', fn (Request $request): Limit => Limit::perMinute(240)->by('chat:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        Event::subscribe(AuditSubscriber::class);
        Event::listen(TeamMemberRemoved::class, [ForgetUserDeadlines::class, 'memberRemoved']);
        // The körjournal: an enskild firma owner's trips are booked once the draft voucher is posted (ADR-072 §7).
        Event::listen(VoucherPosted::class, fn (VoucherPosted $event) => BookOwnerMileage::markBooked($event->companyId, $event->voucherId));
        Event::listen(UserAccountDeleting::class, [ForgetUserDeadlines::class, 'accountDeleting']);
        // Mina datum's calendar link (ADR-051, stage 2): per address and link, like the CRM's; unknown links have a stricter limit of their own.
        // Signing in with Skatteverket (ADR-054): a person starts it by hand.
        RateLimiter::for('skatteverket-sign-in', fn (Request $request): Limit => Limit::perMinute(10)->by('skatteverket:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('guide-calendar', fn (Request $request): Limit => Limit::perMinute(30)->by('guide-calendar:'.$request->ip().'|'.hash('sha256', (string) $request->route('token'))));
        Route::middleware([SecurityHeaders::class, 'throttle:guide-calendar'])->group(base_path('routes/guide-calendar.php'));
        // Record a new version of the systemdokumentation after every migrate, also when nothing was pending (#44).
        Event::listen([MigrationsEnded::class, NoPendingMigrations::class], function (MigrationsEnded|NoPendingMigrations $event): void {
            if ($event->method === 'up') {
                VersionRecorder::recordQuietly();
            }
        });
        // ADR-079 §6: after every migrate of the main database, pin the trigger functions' search path and, with
        // separate database roles, re-apply the grant decisions as the owner (also after a migrate that failed),
        // so a new table or trigger function is covered at once; refuse schema commands that do not run as the owner.
        Event::listen([MigrationsEnded::class, NoPendingMigrations::class], fn () => AfterMigrate::run());
        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            if ($event->exitCode !== 0 && in_array($event->command, DatabaseRoles::SCHEMA_COMMANDS, true)) {
                $connection = $event->input->hasOption('database') ? $event->input->getOption('database') : null;
                AfterMigrate::afterFailedMigrate(is_string($connection) ? $connection : null);
            }
        });
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $connection = $event->input->hasOption('database') ? $event->input->getOption('database') : null;
            $refusal = DatabaseRoles::refusal((string) $event->command, is_string($connection) ? $connection : null);

            if ($refusal !== null) {
                throw new RuntimeException($refusal);
            }
        });
        // Someone who leaves or is removed may have been what linked a client to its firm (ADR-030).
        Event::listen(TeamMemberRemoved::class, function (TeamMemberRemoved $event): void {
            if ($event->team instanceof Company) {
                app(ReconcileFirmLinks::class)($event->team, Auth::user() instanceof User ? Auth::user() : null);
            }
        });

        // Jetstream's components, extended with toasts (and a permission check
        // Jetstream lacks). App providers boot after Jetstream's, so these win.
        Livewire::component('teams.team-member-manager', MemberManager::class);
        Livewire::component('profile.update-profile-information-form', ProfileInformationForm::class);
    }
}
