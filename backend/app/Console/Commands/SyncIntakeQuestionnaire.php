<?php

namespace App\Console\Commands;

use App\Support\IntakeQuestionnaireSnapshot;
use Illuminate\Console\Command;
use Throwable;

class SyncIntakeQuestionnaire extends Command
{
    protected $signature = 'intakes:sync-questionnaire
        {--file= : Snapshot JSON; defaults to database/data/intake-questionnaire-local.json}
        {--apply : Apply the previewed changes atomically, with an automatic definition backup}';

    protected $description = 'Match a local questionnaire snapshot while preserving identities, saved responses and uploads; preview by default';

    public function handle(IntakeQuestionnaireSnapshot $snapshot): int
    {
        try {
            $file = $this->option('file') ?: database_path('data/intake-questionnaire-local.json');
            if (! is_file($file) || ! is_readable($file)) {
                throw new \RuntimeException('Snapshot file is not readable: '.$file);
            }
            $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new \RuntimeException('Invalid snapshot object.');
            }
            $result = $snapshot->sync($data, (bool) $this->option('apply'));
            $this->table(['Action', 'Definition', 'Changed settings'], array_map(fn ($c) => [$c['action'], $c['name'], implode(', ', $c['settings'])], $result['changes']));
            foreach ($result['warnings'] as $warning) {
                $this->warn($warning);
            }
            if ($result['backup']) {
                $this->info('Definition backup: '.$result['backup']);
            }
            $this->info(count($result['changes']).' change(s). '.($this->option('apply') ? 'Applied and verified.' : 'Preview only; use --apply to write.'));
            $this->line('Customer answers, photo metadata, bookings and uploaded files are never rewritten or deleted.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
