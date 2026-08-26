<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_questionnaires', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('disclaimer_short')->nullable();
            $table->text('disclaimer_long')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('intake_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('questionnaire_id')->constrained('intake_questionnaires')->cascadeOnDelete();
            $table->string('key', 64);
            $table->unsignedInteger('order')->default(0);
            $table->string('title');
            $table->text('intro')->nullable();
            $table->unsignedInteger('minutes')->default(0);
            $table->string('icon', 32)->nullable();
            $table->string('subtitle')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['questionnaire_id', 'key']);
            $table->index(['questionnaire_id', 'active', 'order']);
        });

        Schema::create('intake_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('section_id')->constrained('intake_sections')->cascadeOnDelete();
            $table->string('key', 64);
            $table->unsignedInteger('order')->default(0);
            $table->string('label');
            $table->string('type', 32);
            $table->text('hint')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('optional')->default(false);
            $table->string('unit', 32)->nullable();
            $table->double('step')->nullable();
            $table->boolean('tall')->default(false);
            $table->unsignedSmallInteger('lines')->nullable();
            $table->text('placeholder')->nullable();
            $table->jsonb('link')->nullable();
            $table->jsonb('options')->nullable();
            $table->jsonb('show_if')->nullable();
            $table->jsonb('flag_if')->nullable();
            $table->jsonb('critical_if')->nullable();
            $table->jsonb('protocol_if')->nullable();
            $table->jsonb('repeater_sub')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['section_id', 'key']);
            $table->index(['section_id', 'active', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_fields');
        Schema::dropIfExists('intake_sections');
        Schema::dropIfExists('intake_questionnaires');
    }
};
