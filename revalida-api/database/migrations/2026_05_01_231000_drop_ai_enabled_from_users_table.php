<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'ai_enabled')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ai_enabled');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'ai_enabled')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(true)->after('monthly_question_limit');
        });
    }
};
