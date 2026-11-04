<?php

namespace Database\Seeders;

use App\Models\ProtocolTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProtocolTemplateSeeder extends Seeder
{
    /**
     * Admin-managed in Instellingen and followed live by published protocols.
     * The catalog file only supplies them for products that do not exist yet.
     */
    private const CREATE_ONLY_SUPPLEMENT_FIELDS = ['administrations_per_day', 'product_url'];

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
