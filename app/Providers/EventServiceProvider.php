<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\HandleExportFailure;
use App\Listeners\RecordUserSecurityEvent;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Queue\Events\JobFailed;
use Override;

final class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    #[Override]
    protected $listen = [
        Login::class => [\App\Listeners\EnforceUserLoginAccess::class, RecordUserSecurityEvent::class],
        Logout::class => [RecordUserSecurityEvent::class],
        Failed::class => [RecordUserSecurityEvent::class],
        PasswordReset::class => [RecordUserSecurityEvent::class],
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        JobFailed::class => [
            HandleExportFailure::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
