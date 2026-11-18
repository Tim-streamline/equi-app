<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Keep already-free articles free for everyone; retain existing EquiBoost modes.
        $items = DB::table('library_items')->whereNull('equiboost_access')
            ->where(fn ($query) => $query->where('credit_cost', '>', 0)->orWhere('is_plus', true))->get();
        foreach ($items as $item) {
            if (Str::slug($item->title) === 'drinkwater-onder-de-loep-wat-krijgt-je-paard-binnen') {
                DB::table('library_items')->where('id', $item->id)->update(['equiboost_access' => 'included', 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Retain access and any later admin edits.
    }
};
