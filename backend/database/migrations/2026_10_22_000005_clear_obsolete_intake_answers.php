<?php

use App\Support\IntakeObsoleteAnswers;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(IntakeObsoleteAnswers::class)->run();
    }

    public function down(): void
    {
        // Removed choices cannot be reconstructed; never overwrite new answers.
    }
};
