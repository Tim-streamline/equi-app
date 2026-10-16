<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_filter_groups', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->string('slug')->unique();
            $table->string('label');
            $table->unsignedInteger('order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        $definitions = [
            'klachten' => ['Klachten', ['Darmen', 'Jeuk', 'Gedrag', 'Hoeven', 'Stofwisseling', 'Artrose & gewrichten', 'Metabool', 'Insuline resistentie', 'Pezen', 'Luchtwegen']],
            'onderwerp' => ['Onderwerp', ['Voeding', 'Huisvesting', 'Kruiden', 'Supplementen', 'Wildplukken', 'Beweging', 'Diagnostiek']],
            'seizoen' => ['Seizoen', ['Lente', 'Zomer', 'Herfst', 'Winter']],
        ];
        $groups = [];
        foreach ($definitions as $slug => [$label]) {
            $groups[$slug] = (string) Str::uuid();
            DB::table('library_filter_groups')->insert(['id' => $groups[$slug], 'slug' => $slug, 'label' => $label, 'order' => count($groups) - 1, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::table('library_categories', function (Blueprint $table) use ($groups) {
            // Default keeps existing importers and older admin clients compatible.
            $table->foreignUuid('group_id')->default($groups['onderwerp'])->constrained('library_filter_groups')->restrictOnDelete();
            $table->boolean('active')->default(true);
        });
        $aliases = ['darmen' => ['darm', 'darmen', 'darmgezondheid', 'voor-darmen'], 'jeuk' => ['jeuk', 'voor-jeuk']];
        foreach ($definitions as $slug => [$label, $values]) {
            foreach ($values as $order => $value) {
                $key = Str::slug($value);
                $matches = DB::table('library_categories')->orderBy('created_at')->get()->filter(fn ($row) => in_array(Str::slug($row->label), $aliases[$key] ?? [$key], true) || in_array($row->slug, $aliases[$key] ?? [$key], true));
                $canonical = $matches->firstWhere('slug', $key) ?? $matches->first();
                $id = $canonical?->id ?? (string) Str::uuid();
                // Move links first, then archive duplicate names. Never lose item assignments.
                foreach ($matches as $duplicate) {
                    if ($duplicate->id === $id) {
                        continue;
                    }
                    foreach (DB::table('library_item_categories')->where('category_id', $duplicate->id)->get() as $link) {
                        if (DB::table('library_item_categories')->where('category_id', $id)->where('item_id', $link->item_id)->exists()) {
                            DB::table('library_item_categories')->where('id', $link->id)->delete();
                        } else {
                            DB::table('library_item_categories')->where('id', $link->id)->update(['category_id' => $id, 'updated_at' => now()]);
                        }
                    }
                    DB::table('library_categories')->where('id', $duplicate->id)->update(['active' => false, 'group_id' => $groups[$slug], 'updated_at' => now()]);
                }
                DB::table('library_categories')->updateOrInsert(['id' => $id], [
                    'slug' => $key, 'label' => $value, 'group_id' => $groups[$slug], 'order' => $order,
                    'active' => true, 'is_default' => $canonical?->is_default ?? false,
                    'created_at' => $canonical?->created_at ?? now(), 'updated_at' => now(),
                ]);
            }
        }
        // Group names stay unique regardless of case or surrounding whitespace.
        DB::statement('CREATE UNIQUE INDEX library_filter_group_label_unique ON library_filter_groups (lower(trim(label)))');
        // Existing custom categories are retained, including unrelated labels.
    }

    public function down(): void
    {
        // Keep groups, archived synonyms and linked items. Restore a backup for rollback.
    }
};
