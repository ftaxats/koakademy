<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\OnlineUserPresenceService;
use App\Services\UserSecurityActivityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class OnlineUserTrackingMiddleware
{
    public function __construct(
        private OnlineUserPresenceService $onlineUserPresence,
        private UserSecurityActivityService $securityActivity,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $impersonating = $request->session()->has('impersonator_id')
            || $request->attributes->get('user_security_impersonation', false);
        $pendingChallenge = $request->session()->has('auth.2fa.id');
        $login = $request->attributes->get('user_security_login');
        $logout = $request->attributes->get('user_security_logout');

        if (! $impersonating && ! $pendingChallenge && $response->getStatusCode() < 400) {
            if (is_array($login) && $request->user()?->id === $login['user']->id) {
                $this->securityActivity->signedIn($login['user'], $login['guard']);
            } elseif (is_array($logout) && ! $request->user()) {
                $this->securityActivity->record($logout['user'], 'signed_out', 'success', guard: $logout['guard']);
            }
        }

        $user = $request->user();
        if ($user instanceof User && ! $impersonating && ! $pendingChallenge) {
            $this->onlineUserPresence->recordActivity((int) $user->id);
        }

        return $response;
    }
}
