<?php

use App\Models\ProtocolTemplate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Read a consistent snapshot without changing the source database.
$templates = DB::transaction(function (): array {
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

    $templates = ProtocolTemplate::query()
        ->with(['phases.weeks', 'phases.supplements.weeks'])
        ->orderBy('name')
        ->get();

    if ($templates->isEmpty()) {
        throw new RuntimeException('No protocol templates found. Refusing to export an empty catalog.');
    }

    foreach ($templates as $template) {
        if ($template->phases->pluck('order')->duplicates()->isNotEmpty()) {
            throw new RuntimeException("Duplicate phase orders in {$template->name}.");
        }

        foreach ($template->phases as $phase) {
            if ($phase->supplements->pluck('name')->duplicates()->isNotEmpty()) {
                throw new RuntimeException("Duplicate supplement names in {$template->name}: {$phase->name}.");
            }

            foreach ($phase->supplements as $supplement) {
                if ($supplement->weeks->pluck('id')->diff($phase->weeks->pluck('id'))->isNotEmpty()) {
                    throw new RuntimeException("Supplement {$supplement->name} has weeks outside its phase.");
                }
            }
        }
    }

    return $templates->map(fn ($template) => [
        'name' => $template->name,
        'phases' => $template->phases->map(fn ($phase) => [
            ...$phase->only(['order', 'name', 'description', 'required', 'start_after_previous_phase_weeks']),
            'weeks' => $phase->weeks->pluck('number')->all(),
            'supplements' => $phase->supplements->map(fn ($supplement) => [
                ...Arr::only($supplement->getRawOriginal(), [
                    'name', 'description', 'instructions', 'supplement_type', 'dosis_type', 'dosis',
                    'unit', 'add_by_default', 'max_aantal_in_fase', 'min_aantal_per_week', 'rust_periode_in_weken',
                ]),
                'weeks' => $supplement->weeks->pluck('number')->all(),
            ])->all(),
        ])->all(),
    ])->all();
});

$json = json_encode($templates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
$destination = database_path('data/protocol-templates.json');
$temporary = tempnam(dirname($destination), '.protocol-templates-');

if ($temporary === false) {
    throw new RuntimeException('Unable to create the export file.');
}

try {
    if (file_put_contents($temporary, $json) !== strlen($json)
        || ! chmod($temporary, 0644)
        || ! rename($temporary, $destination)) {
        throw new RuntimeException('Unable to save the protocol template export.');
    }
} finally {
    if (is_file($temporary)) {
        unlink($temporary);
    }
}

echo 'Exported '.count($templates)." protocol templates to {$destination}.".PHP_EOL;
