<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

it('allows developers and super admins to view Laralyze', function (UserRole $role): void {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('viewLaralyze'))->toBeTrue();
})->with([
    'developer' => UserRole::Developer,
    'super admin' => UserRole::SuperAdmin,
]);

it('denies Laralyze access to other roles and guests', function (): void {
    $user = User::factory()->create(['role' => UserRole::Admin]);

    expect(Gate::forUser($user)->allows('viewLaralyze'))->toBeFalse()
        ->and(Gate::allows('viewLaralyze'))->toBeFalse();
});

it('does not let an unrelated assigned role bypass Laralyze access', function (): void {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $user->assignRole(Role::findOrCreate(UserRole::Developer->value, 'web'));

    expect(Gate::forUser($user)->allows('viewLaralyze'))->toBeFalse();
});
