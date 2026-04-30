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
        Schema::table('questions', function (Blueprint $table) {
        $table->foreignId('question_source_id')->nullable()->constrained()->nullOnDelete();
    	$table->string('status')->default('draft'); // draft, reviewed, published
    	$table->string('origin')->default('original'); // official_based, original, imported
    	$table->json('tags')->nullable();
    	$table->text('reference')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            //
        });
    }
};
