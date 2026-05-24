<?php

namespace Tests\Feature;

use App\Services\AiHistory\AiHistoryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AiHistoryImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_counts_sessions_and_messages_without_writing_rows()
    {
        $path = $this->writeExportFile([
            $this->sampleSession('codex', 'codex-session-1', 'Codex Test', 2),
        ]);

        $result = app(AiHistoryImporter::class)->import($path, [
            'dry_run' => true,
        ]);

        $this->assertSame(1, $result['sessions']);
        $this->assertSame(2, $result['messages']);
        $this->assertSame(0, $result['errors']);
        $this->assertDatabaseCount('ai_import_batches', 0);
        $this->assertDatabaseCount('ai_sessions', 0);
        $this->assertDatabaseCount('ai_messages', 0);
    }

    public function test_import_is_idempotent_by_source_external_id_and_message_sequence()
    {
        $path = $this->writeExportFile([
            $this->sampleSession('codex', 'codex-session-1', 'Codex Test', 2),
        ]);

        $importer = app(AiHistoryImporter::class);
        $first = $importer->import($path);
        $second = $importer->import($path);

        $this->assertSame(1, $first['sessions']);
        $this->assertSame(2, $first['messages']);
        $this->assertSame(1, $second['sessions']);
        $this->assertSame(2, $second['messages']);

        $this->assertDatabaseCount('ai_import_batches', 2);
        $this->assertDatabaseCount('ai_sessions', 1);
        $this->assertDatabaseCount('ai_messages', 2);
        $this->assertDatabaseHas('ai_sessions', [
            'source' => 'codex',
            'external_id' => 'codex-session-1',
            'title' => 'Codex Test',
            'message_count' => 2,
        ]);
        $this->assertDatabaseHas('ai_messages', [
            'seq' => 1,
            'role' => 'assistant',
            'content' => 'Answer 1',
        ]);
    }

    private function writeExportFile(array $sessions)
    {
        $dir = storage_path('framework/testing/ai-history');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/export.jsonl';

        $lines = array_map(function (array $session) {
            return json_encode($session, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $sessions);

        File::put($path, implode("\n", $lines)."\n");

        return $path;
    }

    private function sampleSession($source, $externalId, $title, $messageCount)
    {
        return [
            'source' => $source,
            'external_id' => $externalId,
            'title' => $title,
            'workspace_path' => 'g:\\dnmp\\dnmp\\www\\aiHistory',
            'model' => 'gpt-test',
            'started_at' => '2026-05-24T10:00:00+08:00',
            'ended_at' => '2026-05-24T10:01:00+08:00',
            'source_path' => 'sample.jsonl',
            'metadata' => ['fixture' => true],
            'messages' => array_slice([
                [
                    'seq' => 0,
                    'role' => 'user',
                    'type' => 'message',
                    'occurred_at' => '2026-05-24T10:00:00+08:00',
                    'content' => 'Question 1',
                    'tool_name' => null,
                    'metadata' => [],
                    'raw' => ['role' => 'user'],
                ],
                [
                    'seq' => 1,
                    'role' => 'assistant',
                    'type' => 'message',
                    'occurred_at' => '2026-05-24T10:01:00+08:00',
                    'content' => 'Answer 1',
                    'tool_name' => null,
                    'metadata' => [],
                    'raw' => ['role' => 'assistant'],
                ],
            ], 0, $messageCount),
        ];
    }
}
