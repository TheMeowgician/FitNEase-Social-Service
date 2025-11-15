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
        // Agora video sessions (one per workout session)
        Schema::create('agora_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique(); // Links to workout_sessions.session_id
            $table->string('channel_name'); // Agora channel name
            $table->timestamp('created_at');
            $table->timestamp('expires_at');

            $table->index('session_id');
        });

        // Participants in video calls
        Schema::create('agora_participants', function (Blueprint $table) {
            $table->id();
            $table->string('session_id'); // Links to workout_sessions.session_id
            $table->unsignedBigInteger('user_id');
            $table->text('token'); // Agora token for this user
            $table->enum('role', ['publisher', 'subscriber'])->default('publisher');
            $table->boolean('video_enabled')->default(true);
            $table->boolean('audio_enabled')->default(true);
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();

            $table->index(['session_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agora_participants');
        Schema::dropIfExists('agora_sessions');
    }
};
