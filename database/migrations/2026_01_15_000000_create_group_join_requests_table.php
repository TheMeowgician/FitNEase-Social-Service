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
        Schema::create('group_join_requests', function (Blueprint $table) {
            $table->id('request_id');
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('user_id'); // User requesting to join
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('message')->nullable(); // Optional message from requester
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('responded_by')->nullable(); // User who approved/rejected
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            // Foreign key to groups table
            $table->foreign('group_id')
                ->references('group_id')
                ->on('groups')
                ->onDelete('cascade');

            // Prevent duplicate pending requests
            $table->unique(['group_id', 'user_id', 'status'], 'unique_pending_request');

            // Indexes for common queries
            $table->index(['group_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_join_requests');
    }
};
