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
        if (! Schema::hasColumn('users', 'suspended_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->timestamp('suspended_at')->nullable());
        }
        if (! Schema::hasColumn('users', 'suspension_reason')) {
            Schema::table('users', fn (Blueprint $table) => $table->string('suspension_reason', 500)->nullable());
        }
        if (! Schema::hasColumn('users', 'session_version')) {
            Schema::table('users', fn (Blueprint $table) => $table->unsignedInteger('session_version')->default(0));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Preserve adopted schema and suspension/revocation state. Forward-fix only.
    }
};
