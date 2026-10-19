<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AdministratorUserAccessService;
use App\Services\UserSecurityActivityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdministratorUserAccessController extends Controller
{
    public function __construct(
        private readonly AdministratorUserAccessService $access,
        private readonly UserSecurityActivityService $audit,
    ) {}

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request, $user);
        $data = $request->validate([
            'suspended' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($request, $user, $data): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->authorizeTarget($request, $locked);
            $locked->forceFill([
                'suspended_at' => $data['suspended'] ? now() : null,
                'suspension_reason' => $data['suspended'] ? $data['reason'] : null,
            ])->save();
            $this->access->revokeAll($locked);
            $this->audit->record($locked, $data['suspended'] ? 'account_suspended' : 'account_reactivated', 'success', $request->user(), reason: $data['reason']);
        });

        return back()->with('flash', ['type' => 'success', 'message' => 'Account access updated.']);
    }

    public function destroySessions(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request, $user);
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $user, $data): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! empty($data['reference'])) {
                $this->access->revokeSession($locked, $data['reference']);
            } else {
                $this->access->revokeAll($locked);
            }
            $this->audit->record($locked, empty($data['reference']) ? 'all_access_revoked' : 'session_revoked', 'success', $request->user());
        });

        return back()->with('flash', ['type' => 'success', 'message' => 'Access revoked.']);
    }

    public function destroyToken(Request $request, User $user, int $token): RedirectResponse
    {
        $this->authorizeTarget($request, $user);
        abort_unless($user->tokens()->whereKey($token)->delete() > 0, 404);
        $this->audit->record($user, 'api_token_revoked', 'success', $request->user());

        return back()->with('flash', ['type' => 'success', 'message' => 'API token revoked.']);
    }

    private function authorizeTarget(Request $request, User $user): void
    {
        $this->abortUnlessUserHasAnyPermission($request->user(), 'Update:User');
        abort_unless($request->user()->hasHigherAuthorityThan($user), 403);
        abort_if($request->session()->has('impersonator_id'), 403);
    }
}
