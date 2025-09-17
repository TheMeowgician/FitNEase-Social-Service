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
        Schema::create('group_members', function (Blueprint $table) {
            $table->id('group_member_id');
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('member_role', ['admin', 'moderator', 'member'])->default('member');
            $table->timestamp('joined_at')->useCurrent();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['group_id', 'user_id']);
            $table->index(['group_id', 'is_active']);
            $table->index(['user_id', 'is_active']);
            $table->index(['group_id', 'member_role', 'is_active']);

            $table->foreign('group_id')->references('group_id')->on('groups')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_members');
    }
};
