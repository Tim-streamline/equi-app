<?php

use App\Support\ProtocolTemplateExport;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Read a consistent snapshot without changing the source database.
$templates = DB::transaction(function (): array {
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

    return app(ProtocolTemplateExport::class)->catalog();
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
