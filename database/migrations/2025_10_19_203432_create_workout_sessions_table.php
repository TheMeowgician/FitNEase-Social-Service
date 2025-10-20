<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Server-authoritative workout session tracking
     * Stores real-time workout state for perfect synchronization across all clients
     */
    public function up(): void
    {
        Schema::create('workout_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 36)->unique(); // UUID from lobby
            $table->unsignedBigInteger('lobby_id')->nullable(); // Link to lobby
            $table->unsignedBigInteger('initiator_id'); // Who started the workout

            // Workout state (server is source of truth)
            $table->enum('status', ['running', 'paused', 'completed', 'stopped'])->default('running');
            $table->integer('time_remaining'); // Seconds remaining in current phase
            $table->enum('phase', ['prepare', 'work', 'rest', 'complete'])->default('prepare');
            $table->integer('current_exercise')->default(0);
            $table->integer('current_set')->default(0);
            $table->integer('current_round')->default(0);
            $table->decimal('calories_burned', 8, 2)->default(0);

            // Timing metadata
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('total_pause_duration')->default(0); // Total seconds paused

            // Workout data (JSON)
            $table->json('workout_data')->nullable(); // Exercise details

            $table->timestamps();

            // Indexes for fast queries
            $table->index('session_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workout_sessions');
    }
};
