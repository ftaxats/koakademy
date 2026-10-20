<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $directory = Permission::query()->where('name', 'ViewAny:User')->where('guard_name', 'web')->first();
        $details = Permission::findOrCreate('View:User', 'web');
        if ($directory === null) {
            return;
        }

        $permissionKey = config('permission.column_names.permission_pivot_key') ?: 'permission_id';
        foreach (['role_has_permissions', 'model_has_permissions'] as $pivot) {
            $table = config('permission.table_names.'.$pivot);
            // Copy both role-based and directly assigned directory access; preserve existing grants.
            DB::table($table)->where($permissionKey, $directory->id)->get()->each(function ($row) use ($table, $permissionKey, $details): void {
                $grant = (array) $row;
                $grant[$permissionKey] = $details->id;
                DB::table($table)->insertOrIgnore($grant);
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Grants cannot be safely distinguished from independently assigned permissions.
        // Keep access on rollback; explicit revocation belongs to permission administration.
    }
};
