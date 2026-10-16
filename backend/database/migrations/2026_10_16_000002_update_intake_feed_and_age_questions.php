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
        if (! Schema::hasColumn('intake_fields', 'age_config')) {
            Schema::table('intake_fields', fn (Blueprint $table) => $table->json('age_config')->nullable());
        }
        $changes = json_decode(file_get_contents(__DIR__.'/support/intake-feed-age-opt133-opt135.json'), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($changes): void {
            $sections = DB::table('intake_sections')->whereIn('key', ['voer', 'paard'])
                ->whereIn('questionnaire_id', DB::table('intake_questionnaires')->where('slug', 'protocol-intake')->select('id'))->get();
            foreach ($sections as $section) {
                foreach ($changes as $key => $change) {
                    $update = ['updated_at' => now()];
                    foreach ($change as $column => $value) {
                        $column = ['ageConfig' => 'age_config', 'sub' => 'repeater_sub'][$column] ?? $column;
                        $update[$column] = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
                    }
                    DB::table('intake_fields')->where('section_id', $section->id)->where('key', $key)->update($update);
                }
                DB::table('intake_fields')->where('section_id', $section->id)
                    ->whereIn('key', $section->key === 'voer' ? ['balancer', 'balancer-nu'] : ['geboortedatum'])
                    ->update(['active' => false, 'updated_at' => now()]);
            }
            // Preserve the original answers and an audit snapshot. A marker makes
            // replay safe and prevents the nutrition extractor counting both copies.
            DB::table('intake_answers')->where('section_id', 'voer')->whereIn('field_id', ['balancer', 'balancer-nu'])
                ->orderBy('id')->chunk(100, function ($answers): void {
                    foreach ($answers as $answer) {
                        $query = DB::table('intake_answers')->where('response_id', $answer->response_id)->where('section_id', 'voer');
                        $values = (clone $query)->lockForUpdate()->get()->keyBy('field_id');
                        if ($values->has('__bag_feed_migration')) {
                            continue;
                        }
                        $decode = fn ($key) => json_decode($values->get($key)?->value ?? 'null', true);
                        $write = function ($key, $value) use ($query, $answer): void {
                            $existing = (clone $query)->where('field_id', $key)->first();
                            $data = ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_at' => now()];
                            if ($existing) {
                                DB::table('intake_answers')->where('id', $existing->id)->update($data);
                            } else {
                                DB::table('intake_answers')->insert($data + ['id' => (string) Str::uuid(),
                                    'response_id' => $answer->response_id, 'section_id' => 'voer', 'field_id' => $key, 'created_at' => now()]);
                            }
                        };
                        $write('__bag_feed_migration', ['feed' => $decode('huidige-bijvoeding'), 'gate' => $decode('bijvoeding-nu')]);
                        $rows = $decode('balancer') ?? [];
                        if ($decode('balancer-nu') === 'Nee' || ! is_array($rows)) {
                            continue;
                        }
                        $feed = $decode('huidige-bijvoeding');
                        $feed = is_array($feed) ? $feed : (is_string($feed) && trim($feed) !== '' ? [['product' => $feed]] : []);
                        if (in_array($decode('bijvoeding-nu'), ['Nee', 'Weet ik niet'], true)) {
                            $feed = [];
                        }
                        foreach ($rows as $row) {
                            if (is_array($row) && count(array_filter($row, fn ($value) => is_string($value) && trim($value) !== '')) > 0) {
                                $feed[] = $row + ['_source' => 'balancer'];
                            }
                        }
                        if ($feed || $decode('balancer-nu') === 'Ja') {
                            $write('huidige-bijvoeding', $feed);
                            $write('bijvoeding-nu', 'Ja');
                        }
                    }
                });
        });
    }

    public function down(): void
    {
        // Data migration: keep saved answers/configuration. Restore a backup for rollback.
    }
};
