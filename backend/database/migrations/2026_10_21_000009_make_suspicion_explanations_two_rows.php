<?php

use App\Models\IntakeQuestionnaire;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $section = IntakeQuestionnaire::where('slug', 'protocol-intake')->first()
            ?->sections()->where('key', 'medisch')->first();

        $section?->fields()->whereIn('key', [
            'ir-vermoeden-toelichting',
            'ems-vermoeden-toelichting',
            'kpu-vermoeden-toelichting',
        ])->update(['type' => 'textarea', 'lines' => 2, 'tall' => false]);
    }

    public function down(): void
    {
        $section = IntakeQuestionnaire::where('slug', 'protocol-intake')->first()
            ?->sections()->where('key', 'medisch')->first();

        $section?->fields()->whereIn('key', [
            'ir-vermoeden-toelichting',
            'ems-vermoeden-toelichting',
            'kpu-vermoeden-toelichting',
        ])->update(['type' => 'text', 'lines' => null]);
    }
};
