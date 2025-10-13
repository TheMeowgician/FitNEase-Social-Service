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
        Schema::create('workout_lobby_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('message_id')->unique();
            $table->unsignedBigInteger('lobby_id');
            $table->unsignedBigInteger('user_id')->nullable(); // Nullable for system messages
            $table->text('message');
            $table->boolean('is_system_message')->default(false);
            $table->timestamps();

            // Indexes
            $table->index('lobby_id');
            $table->index('message_id');

            // Foreign key
            $table->foreign('lobby_id')->references('id')->on('workout_lobbies')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workout_lobby_chat_messages');
    }
};
