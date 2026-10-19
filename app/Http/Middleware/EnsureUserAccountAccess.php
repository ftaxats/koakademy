<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserAccountAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            // Read current DB state, including during long-running requests/test guard reuse.
            $user->refresh();
            $revoked = $request->hasSession()
                && (int) $request->session()->get('user_session_version', 0) !== $user->session_version;
            if ($user->suspended_at !== null || $revoked) {
                if ($request->hasSession()) {
                    Auth::guard('web')->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
                abort(403, 'Account access is unavailable. Please contact an administrator or sign in again.');
            }
        }

        return $next($request);
    }
}
