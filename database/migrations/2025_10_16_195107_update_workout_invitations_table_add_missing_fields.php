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
        // Step 1: Add response_reason column
        Schema::table('workout_invitations', function (Blueprint $table) {
            $table->string('response_reason')->nullable()->after('responded_at');
        });

        // Step 2: Modify status enum to include 'cancelled'
        DB::statement("ALTER TABLE workout_invitations MODIFY status ENUM('pending', 'accepted', 'declined', 'expired', 'cancelled') NOT NULL DEFAULT 'pending'");

        // Step 3: Change int columns to unsignedBigInteger for consistency
        DB::statement("ALTER TABLE workout_invitations MODIFY group_id BIGINT UNSIGNED NOT NULL");
        DB::statement("ALTER TABLE workout_invitations MODIFY initiator_id BIGINT UNSIGNED NOT NULL");
        DB::statement("ALTER TABLE workout_invitations MODIFY invited_user_id BIGINT UNSIGNED NOT NULL");

        // Step 4: Add composite index for invited_user_id + status (if not exists)
        $compositeIndexExists = DB::select("SHOW INDEXES FROM workout_invitations WHERE Key_name = 'workout_invitations_invited_user_id_status_index'");
        if (empty($compositeIndexExists)) {
            Schema::table('workout_invitations', function (Blueprint $table) {
                $table->index(['invited_user_id', 'status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse Step 4: Drop composite index
        Schema::table('workout_invitations', function (Blueprint $table) {
            $table->dropIndex(['invited_user_id', 'status']);
        });

        // Reverse Step 3: Revert to int columns
        DB::statement("ALTER TABLE workout_invitations MODIFY group_id INT NOT NULL");
        DB::statement("ALTER TABLE workout_invitations MODIFY initiator_id INT NOT NULL");
        DB::statement("ALTER TABLE workout_invitations MODIFY invited_user_id INT NOT NULL");

        // Reverse Step 2: Restore old enum values
        DB::statement("ALTER TABLE workout_invitations MODIFY status ENUM('pending', 'accepted', 'declined', 'expired') NOT NULL DEFAULT 'pending'");

        // Reverse Step 1: Drop response_reason column
        Schema::table('workout_invitations', function (Blueprint $table) {
            $table->dropColumn('response_reason');
        });
    }
};
