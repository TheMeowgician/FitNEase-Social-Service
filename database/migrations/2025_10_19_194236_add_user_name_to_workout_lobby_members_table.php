<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add user_name column to cache usernames and eliminate auth service calls
     * during time-critical operations (pause/resume/stop).
     */
    public function up(): void
    {
        Schema::table('workout_lobby_members', function (Blueprint $table) {
            // Add user_name column after user_id
            // This caches the username when user joins lobby, eliminating the need
            // for auth service HTTP calls during pause/resume/stop operations
            $table->string('user_name', 255)->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workout_lobby_members', function (Blueprint $table) {
            $table->dropColumn('user_name');
        });
    }
};
