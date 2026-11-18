<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocol_evaluation_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('label');
            $table->unsignedInteger('order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        foreach (\App\Support\ProtocolEvaluation::QUESTIONS as $key => $label) {
            \Illuminate\Support\Facades\DB::table('protocol_evaluation_questions')->insert([
                'id' => $key, 'label' => $label, 'order' => array_search($key, array_keys(\App\Support\ProtocolEvaluation::QUESTIONS)),
                'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Schema::create('protocol_evaluations', function (Blueprint $table) {
            $table->uuid('protocol_id')->primary();
            $table->foreign('protocol_id')->references('id')->on('protocols')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('answers');
            $table->json('questions');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_evaluations');
        Schema::dropIfExists('protocol_evaluation_questions');
    }
};
