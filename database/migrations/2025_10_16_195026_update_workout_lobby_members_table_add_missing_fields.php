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
        // Step 0: Clean up duplicate entries before adding unique constraint
        // Keep the most recent entry (highest id) for each lobby_id + user_id pair
        DB::statement("
            DELETE wlm1 FROM workout_lobby_members wlm1
            INNER JOIN workout_lobby_members wlm2
            WHERE wlm1.lobby_id = wlm2.lobby_id
              AND wlm1.user_id = wlm2.user_id
              AND wlm1.id < wlm2.id
        ");

        // Step 1: Add left_reason column (if not exists)
        if (!Schema::hasColumn('workout_lobby_members', 'left_reason')) {
            Schema::table('workout_lobby_members', function (Blueprint $table) {
                $table->string('left_reason')->nullable()->after('left_at');
            });
        }

        // Step 2: Modify status enum to include 'left' and 'kicked'
        DB::statement("ALTER TABLE workout_lobby_members MODIFY status ENUM('waiting', 'ready', 'left', 'kicked') NOT NULL DEFAULT 'waiting'");

        // Step 3: Add unique constraint for lobby_id + user_id (if not exists)
        // Check if unique constraint already exists
        $indexExists = DB::select("SHOW INDEXES FROM workout_lobby_members WHERE Key_name = 'workout_lobby_members_lobby_id_user_id_unique'");
        if (empty($indexExists)) {
            Schema::table('workout_lobby_members', function (Blueprint $table) {
                $table->unique(['lobby_id', 'user_id']);
            });
        }

        // Step 4: Add composite index for lobby_id + status (if not exists)
        $compositeIndexExists = DB::select("SHOW INDEXES FROM workout_lobby_members WHERE Key_name = 'workout_lobby_members_lobby_id_status_index'");
        if (empty($compositeIndexExists)) {
            Schema::table('workout_lobby_members', function (Blueprint $table) {
                $table->index(['lobby_id', 'status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse Step 4: Drop composite index
        Schema::table('workout_lobby_members', function (Blueprint $table) {
            $table->dropIndex(['lobby_id', 'status']);
        });

        // Reverse Step 3: Drop unique constraint
        Schema::table('workout_lobby_members', function (Blueprint $table) {
            $table->dropUnique(['lobby_id', 'user_id']);
        });

        // Reverse Step 2: Restore old enum values
        DB::statement("ALTER TABLE workout_lobby_members MODIFY status ENUM('waiting', 'ready') NOT NULL DEFAULT 'waiting'");

        // Reverse Step 1: Drop left_reason column
        Schema::table('workout_lobby_members', function (Blueprint $table) {
            $table->dropColumn('left_reason');
        });
    }
};
