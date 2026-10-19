<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\UserSecurityActivityService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

final class RecordUserSecurityEvent
{
    public function __construct(private readonly UserSecurityActivityService $activity) {}

    public function handle(Login|Logout|Failed|PasswordReset $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        if ($event instanceof Login || $event instanceof Logout) {
            // Wait until the response: password acceptance may still lead to an MFA challenge.
            request()->attributes->set('user_security_'.($event instanceof Login ? 'login' : 'logout'), [
                'user' => $event->user,
                'guard' => $event->guard,
            ]);

            return;
        }

        $this->activity->record(
            $event->user,
            $event instanceof Failed ? 'sign_in_failed' : 'password_reset',
            $event instanceof Failed ? 'failure' : 'success',
        );
    }
}
