<?php

namespace Database\Seeders;

use App\Models\ProtocolTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProtocolTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = json_decode(
            file_get_contents(database_path('data/protocol-templates.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

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
                        $supplement = $phase->supplements()->updateOrCreate(
                            ['name' => $supplementData['name']],
                            Arr::except($supplementData, ['weeks']),
                        );

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
