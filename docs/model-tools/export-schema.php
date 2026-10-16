<?php
// Run from backend with: php ../docs/model-tools/export-schema.php > /tmp/equi-schema.json
// Metadata only. It never reads application rows or mutates the database.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$result = [];
foreach (Illuminate\Support\Facades\Schema::getTables() as $table) {
    $name = $table['name'];
    $result[$name] = [
        'columns' => Illuminate\Support\Facades\Schema::getColumns($name),
        'foreignKeys' => Illuminate\Support\Facades\Schema::getForeignKeys($name),
        'indexes' => Illuminate\Support\Facades\Schema::getIndexes($name),
    ];
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
