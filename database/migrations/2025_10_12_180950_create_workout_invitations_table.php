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
        Schema::create('workout_invitations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lobby_id')->nullable();
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('initiator_id');
            $table->unsignedBigInteger('invited_user_id');
            $table->enum('status', ['pending', 'accepted', 'declined', 'expired'])->default('pending');
            $table->timestamp('invited_at')->useCurrent();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            // Indexes
            $table->index('lobby_id');
            $table->index('group_id');
            $table->index('initiator_id');
            $table->index('invited_user_id');
            $table->index('status');
            $table->index('expires_at');

            // Foreign key - lobby_id can be null (for group invitations without lobby)
            $table->foreign('lobby_id')
                ->references('id')
                ->on('workout_lobbies')
                ->onDelete('cascade');

            // Foreign key - group reference
            $table->foreign('group_id')
                ->references('group_id')
                ->on('groups')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workout_invitations');
    }
};
