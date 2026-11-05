<?php

namespace Database\Seeders;

use App\Models\DoseBuildup;
use App\Models\ProtocolTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProtocolTemplateSeeder extends Seeder
{
    /**
     * Preserve admin-managed settings when reseeding existing products.
     * Frequency and links are live; buildup is copied into protocol selections.
     * The catalog file only supplies these fields for new products.
     */
    private const CREATE_ONLY_SUPPLEMENT_FIELDS = ['administrations_per_day', 'product_url', 'dose_buildup_id'];

    public function run(): void
    {
        $this->import(json_decode(
            file_get_contents(database_path('data/protocol-templates.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    public function import(array $templates): void
    {
        DB::transaction(function () use ($templates): void {
            foreach ($templates as $templateData) {
                $template = ProtocolTemplate::query()->firstOrCreate(['name' => $templateData['name']]);

                foreach ($templateData['phases'] as $phaseData) {
                    $phase = $template->phases()->updateOrCreate(
                        ['order' => $phaseData['order']],
                        Arr::except($phaseData, ['weeks', 'supplements']),
                    );

                    $weekIds = [];
                    foreach ($phaseData['weeks'] as $number) {
                        $weekIds[$number] = $phase->weeks()->firstOrCreate(['number' => $number])->id;
                    }

                    foreach ($phaseData['supplements'] as $supplementData) {
                        if (isset($supplementData['dose_buildup'])) {
                            $definition = $supplementData['dose_buildup'];
                            $buildup = DoseBuildup::firstOrCreate(['id' => $definition['id']], Arr::except($definition, ['id']));
                            if (! $buildup->matchesDosingSettings($definition)) {
                                throw new RuntimeException("Dose buildup {$definition['id']} has conflicting dosing settings. Import cancelled; create a new definition with a new ID for the changed schedule.");
                            }
                            $supplementData['dose_buildup_id'] = $buildup->id;
                        }
                        unset($supplementData['dose_buildup']);
                        $supplement = $phase->supplements()->firstOrNew(['name' => $supplementData['name']]);
                        $supplement->fill(Arr::except($supplementData, $supplement->exists
                            ? ['weeks', ...self::CREATE_ONLY_SUPPLEMENT_FIELDS]
                            : ['weeks']))->save();

                        $supplement->weeks()->sync(array_map(
                            fn (int $number): string => $weekIds[$number],
                            $supplementData['weeks'],
                        ));
                    }
                }
            }
        });
    }
}
