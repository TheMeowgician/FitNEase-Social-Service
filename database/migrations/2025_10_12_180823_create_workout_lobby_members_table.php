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
        Schema::create('workout_lobby_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lobby_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('status', ['waiting', 'ready'])->default('waiting');
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Indexes
            $table->index('lobby_id');
            $table->index('user_id');
            $table->index('is_active');

            // Foreign key
            $table->foreign('lobby_id')->references('id')->on('workout_lobbies')->onDelete('cascade');

            // Unique constraint: one active membership per user per lobby
            $table->unique(['lobby_id', 'user_id', 'is_active'], 'unique_active_member');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workout_lobby_members');
    }
};
