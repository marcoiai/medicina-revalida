<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('comment_source')->nullable()->after('comentario');
        });

        DB::table('questions')
            ->where('question_type', 'discursive')
            ->whereNotNull('official_answer')
            ->update(['comment_source' => 'official']);

        DB::table('questions')
            ->where('question_type', 'multiple_choice')
            ->where('origin', 'official_verbatim')
            ->whereNotNull('comentario')
            ->whereRaw('LOWER(comentario) NOT LIKE ?', ['gabarito definitivo oficial do inep:%'])
            ->update(['comment_source' => 'ai']);
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('comment_source');
        });
    }
};
