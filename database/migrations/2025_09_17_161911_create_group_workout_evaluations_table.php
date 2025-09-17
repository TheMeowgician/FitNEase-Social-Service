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
        Schema::create('group_workout_evaluations', function (Blueprint $table) {
            $table->id('evaluation_id');
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('workout_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('evaluation_type', ['like', 'unlike']);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'workout_id', 'user_id']);
            $table->index(['group_id', 'workout_id']);
            $table->index(['workout_id', 'evaluation_type']);

            $table->foreign('group_id')->references('group_id')->on('groups')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_workout_evaluations');
    }
};
