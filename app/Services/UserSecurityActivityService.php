<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Jenssegers\Agent\Agent;

final class UserSecurityActivityService
{
    public function record(User $user, string $event, string $outcome, ?User $actor = null, ?string $guard = null, ?string $reason = null): void
    {
        $request = request();
        $agent = new Agent;
        $agent->setUserAgent((string) $request->userAgent());

        // Explicit allowlist: never copy credentials, tokens, session IDs or request payloads.
        activity('user-security')
            ->performedOn($user)
            ->causedBy($actor ?? $user)
            ->event($event)
            ->withProperties([
                'outcome' => $outcome,
                'reason' => $reason,
                'guard' => $guard,
                'browser' => $agent->browser() ?: 'Unknown',
                'platform' => $agent->platform() ?: 'Unknown',
            ])
            ->log(str_replace('_', ' ', $event));
    }

    public function signedIn(User $user, ?string $guard = null): void
    {
        DB::table('users')->where('id', $user->id)->update([
            'last_login_at' => now(),
        ]);
        $this->record($user, 'signed_in', 'success', guard: $guard);
    }
}
