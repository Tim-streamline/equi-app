<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Only a credit-priced article moves to "credits for Basic, free during EquiBoost". Plus articles
        // (included for every subscriber), free articles and any existing EquiBoost mode stay as they are:
        // an EquiBoost mode would otherwise replace their broader regular access.
        $items = DB::table('library_items')->whereNull('equiboost_access')->where('credit_cost', '>', 0)
            ->where(fn ($query) => $query->where('is_plus', false)->orWhereNull('is_plus'))->get();
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
