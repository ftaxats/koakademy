<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['last_login_at', 'last_active_at'] as $column) {
            if (! Schema::hasColumn('users', $column)) {
                Schema::table('users', fn (Blueprint $table) => $table->timestamp($column)->nullable());
            }
        }

        // PostgreSQL calls indexes relations too. Check independently after partial upgrades.
        if (! Schema::hasIndex('users', 'users_last_active_at_index')) {
            Schema::table('users', fn (Blueprint $table) => $table->index('last_active_at'));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Existing installations may already own these columns and their history.
        // Do not drop adopted schema or authentication data during rollback. Forward-fix only.
    }
};
