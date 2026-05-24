<?php

namespace App\Console\Commands;

use App\Services\AiHistory\AiHistoryImporter;
use Illuminate\Console\Command;

class ImportAiHistory extends Command
{
    protected $signature = 'ai-history:import
        {path : Unified AI history JSONL file}
        {--source= : Import only codex or cursor sessions}
        {--dry-run : Count records without writing to database}
        {--limit= : Limit number of JSONL lines read}
        {--reset-source= : Delete existing sessions for codex or cursor before import}';

    protected $description = 'Import normalized Codex and Cursor AI history JSONL into MySQL';

    public function handle(AiHistoryImporter $importer)
    {
        $result = $importer->import($this->argument('path'), [
            'source' => $this->option('source') ?: null,
            'dry_run' => (bool) $this->option('dry-run'),
            'limit' => $this->option('limit') ?: null,
            'reset_source' => $this->option('reset-source') ?: null,
        ]);

        $this->info('Sessions: '.$result['sessions']);
        $this->info('Messages: '.$result['messages']);
        $this->info('Errors: '.$result['errors']);

        foreach (array_slice($result['error_messages'], 0, 10) as $message) {
            $this->warn($message);
        }

        return $result['errors'] > 0 ? 1 : 0;
    }
}
