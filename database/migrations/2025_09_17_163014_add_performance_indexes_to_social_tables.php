<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additional indexes for Groups table (some already added in main migration)
        Schema::table('groups', function (Blueprint $table) {
            // Group discovery index (already added in main migration)
            // $table->index(['is_private', 'is_active', 'created_at'], 'idx_groups_public_active');

            // Group creator tracking index (already added in main migration)
            // $table->index(['created_by', 'is_active'], 'idx_groups_creator');
        });

        // Additional indexes for GroupMembers table (some already added in main migration)
        Schema::table('group_members', function (Blueprint $table) {
            // Group activity queries index (already added in main migration)
            // $table->index(['group_id', 'is_active'], 'idx_group_members_active');

            // User group membership index (already added in main migration)
            // $table->index(['user_id', 'is_active'], 'idx_group_members_user');

            // Member role management index (already added in main migration)
            // $table->index(['group_id', 'member_role', 'is_active'], 'idx_group_members_role');
        });

        // Additional indexes for GroupWorkoutEvaluations table (some already added in main migration)
        Schema::table('group_workout_evaluations', function (Blueprint $table) {
            // Workout evaluation lookup index (already added in main migration)
            // $table->index(['group_id', 'workout_id'], 'idx_evaluations_group_workout');

            // Popular workout analysis index (already added in main migration)
            // $table->index(['workout_id', 'evaluation_type'], 'idx_evaluations_workout_type');

            // Additional indexes for specific query patterns
            $table->index(['user_id', 'created_at'], 'idx_evaluations_user_time');
            $table->index(['group_id', 'created_at'], 'idx_evaluations_group_time');
            $table->index(['evaluation_type', 'created_at'], 'idx_evaluations_type_time');
            $table->index(['group_id', 'user_id', 'created_at'], 'idx_evaluations_group_user_time');
        });
    }

    public function down(): void
    {
        Schema::table('group_workout_evaluations', function (Blueprint $table) {
            $table->dropIndex('idx_evaluations_user_time');
            $table->dropIndex('idx_evaluations_group_time');
            $table->dropIndex('idx_evaluations_type_time');
            $table->dropIndex('idx_evaluations_group_user_time');
        });
    }
};