<?php

namespace App\Console\Commands;

use App\Support\IntakeQuestionnaireSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExportIntakeQuestionnaire extends Command
{
    protected $signature = 'intakes:export-questionnaire {file : New JSON snapshot file} {--slug=protocol-intake}';

    protected $description = 'Export the stored intake definitions and answer choices, excluding customer responses';

    public function handle(IntakeQuestionnaireSnapshot $snapshot): int
    {
        try {
            $data = DB::transaction(function () use ($snapshot) {
                if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                }

                return $snapshot->export($this->option('slug'));
            });
            $snapshot->validate($data);
            $json = $snapshot::json($data);
            $handle = @fopen($this->argument('file'), 'x');
            if ($handle === false) {
                throw new \RuntimeException('Cannot create snapshot; choose a new file in an existing directory. Existing files are never overwritten.');
            }
            try {
                if (fwrite($handle, $json) !== strlen($json)) {
                    throw new \RuntimeException('Snapshot write failed.');
                }
            } finally {
                fclose($handle);
            }
            $this->info('Exported '.count($data['sections']).' sections and '.array_sum(array_map(fn ($s) => count($s['fields']), $data['sections'])).' fields. No customer answers included.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
