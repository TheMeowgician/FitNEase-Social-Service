<?php

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
        // Step 1: Add completed_at column
        Schema::table('workout_lobbies', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('started_at');
        });

        // Step 2: Modify status enum to include 'starting' and 'in_progress'
        DB::statement("ALTER TABLE workout_lobbies MODIFY status ENUM('waiting', 'starting', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'waiting'");

        // Step 3: Add composite index for status + created_at
        Schema::table('workout_lobbies', function (Blueprint $table) {
            $table->dropIndex(['status']); // Drop old single-column index
            $table->index(['status', 'created_at']); // Add composite index
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse Step 3: Remove composite index and restore single-column index
        Schema::table('workout_lobbies', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->index('status');
        });

        // Reverse Step 2: Restore old enum values
        DB::statement("ALTER TABLE workout_lobbies MODIFY status ENUM('waiting', 'started', 'completed', 'cancelled') NOT NULL DEFAULT 'waiting'");

        // Reverse Step 1: Drop completed_at column
        Schema::table('workout_lobbies', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
