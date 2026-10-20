<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('upgrades fresh partial and existing user management schema without losing data', function (string $state): void {
    config(['database.connections.user_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    $connection = DB::connection('user_upgrade');
    $schema = $connection->getSchemaBuilder();
    $original = Schema::getFacadeRoot();
    Schema::swap($schema);
    try {
        $schema->create('users', function (Blueprint $table) use ($state): void {
            $table->id();
            if ($state !== 'fresh') {
                $table->timestamp('last_login_at')->nullable();
                $table->timestamp('suspended_at')->nullable();
            }
            if ($state === 'existing') {
                $table->timestamp('last_active_at')->nullable()->index();
                $table->string('suspension_reason', 500)->nullable();
                $table->unsignedInteger('session_version')->default(0);
            }
        });
        $data = ['id' => 1];
        if ($state !== 'fresh') {
            $data['last_login_at'] = '2026-10-01 12:00:00';
            $data['suspended_at'] = '2026-10-02 12:00:00';
        }
        if ($state === 'existing') {
            $data['session_version'] = 7;
            $data['suspension_reason'] = 'Existing security hold';
        }
        $connection->table('users')->insert($data);
        $activity = require database_path('migrations/2026_10_19_190342_add_activity_timestamps_to_users_table.php');
        $access = require database_path('migrations/2026_10_19_193015_add_administrator_access_controls_to_users_table.php');
        // Repeated up() models schema already present but migration ledger not updated.
        foreach ([$activity, $access, $activity, $access] as $migration) {
            $migration->up();
        }
        expect($schema->hasColumns('users', ['last_login_at', 'last_active_at', 'suspended_at', 'suspension_reason', 'session_version']))->toBeTrue()
            ->and($schema->hasIndex('users', 'users_last_active_at_index'))->toBeTrue();
        $row = (array) $connection->table('users')->find(1);
        foreach ($data as $key => $value) {
            expect($row[$key])->toBe($value);
        }
        $access->down();
        $activity->down();
        expect((array) $connection->table('users')->find(1))->toBe($row);
    } finally {
        Schema::swap($original);
        DB::purge('user_upgrade');
    }
})->with(['fresh', 'partial', 'existing']);
