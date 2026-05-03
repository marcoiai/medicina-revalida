<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('study_category', 20)->default('revalida');
            // First day of month, used for uniqueness and fast counting.
            $table->date('period_start');
            $table->timestamp('attempted_at');
            $table->boolean('is_correct')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'question_id', 'study_category', 'period_start'], 'question_attempts_unique_month');
            $table->index(['user_id', 'study_category', 'period_start'], 'question_attempts_user_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_attempts');
    }
};

