<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use Astro\Trail\TrailApplicationServiceProvider;
use Illuminate\Support\Facades\Gate;

final class TrailServiceProvider extends TrailApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Register the viewTrail gate.
     *
     * This gate determines who can access Trail outside the local environment.
     */
    protected function gate(): void
    {
        Gate::define('viewTrail', function (mixed $user = null): bool {
            if (! $user instanceof User) {
                return false;
            }

            if (in_array($user->role, [UserRole::SuperAdmin, UserRole::Developer], true)) {
                return true;
            }

            $superAdminRoleName = (string) config(
                'filament-shield.super_admin.name',
                UserRole::SuperAdmin->value,
            );

            return $user->hasAnyRole([$superAdminRoleName, UserRole::Developer->value]);
        });
    }
}
