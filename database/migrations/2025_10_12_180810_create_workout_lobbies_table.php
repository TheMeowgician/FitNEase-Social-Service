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
        Schema::create('workout_lobbies', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id')->unique();
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('initiator_id');
            $table->json('workout_data')->nullable();
            $table->enum('status', ['waiting', 'started', 'completed', 'cancelled'])->default('waiting');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            // Indexes
            $table->index('session_id');
            $table->index('group_id');
            $table->index('status');
            $table->index('expires_at');

            // Foreign key
            $table->foreign('group_id')->references('group_id')->on('groups')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workout_lobbies');
    }
};
