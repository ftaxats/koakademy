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

it('allows Spatie developer and super admin roles to view Laralyze', function (string $role): void {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $user->assignRole(Role::findOrCreate($role, 'web'));

    expect(Gate::forUser($user)->allows('viewLaralyze'))->toBeTrue();
})->with([
    'developer' => UserRole::Developer->value,
    'super admin' => UserRole::SuperAdmin->value,
]);

it('denies Laralyze access to unrelated Spatie roles', function (): void {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $user->assignRole(Role::findOrCreate(UserRole::Registrar->value, 'web'));

    expect(Gate::forUser($user)->allows('viewLaralyze'))->toBeFalse();
});
