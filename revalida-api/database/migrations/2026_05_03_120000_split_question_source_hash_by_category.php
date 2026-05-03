<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropUnique('questions_source_hash_unique');
            $table->unique(['source_hash', 'study_category'], 'questions_source_hash_study_category_unique');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropUnique('questions_source_hash_study_category_unique');
            $table->unique('source_hash', 'questions_source_hash_unique');
        });
    }
};
