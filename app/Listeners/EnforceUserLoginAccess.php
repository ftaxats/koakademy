<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;

final class EnforceUserLoginAccess
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }
        $user = $event->user->fresh();
        if (! $user || $user->suspended_at !== null) {
            // SessionGuard dispatches Login before setting its in-memory user.
            Auth::guard($event->guard)->logout();
            abort(403, 'Account access is unavailable. Please contact an administrator.');
        }
        if (request()->hasSession()) {
            request()->session()->put('user_session_version', $user->session_version);
        }
    }
}
