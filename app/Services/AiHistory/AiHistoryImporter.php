<?php

namespace App\Services\AiHistory;

use App\Models\AiImportBatch;
use App\Models\AiMessage;
use App\Models\AiSession;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

class AiHistoryImporter
{
    public function import($path, array $options = [])
    {
        $options = array_merge([
            'source' => null,
            'dry_run' => false,
            'limit' => null,
            'reset_source' => null,
        ], $options);

        if (! File::exists($path)) {
            throw new InvalidArgumentException("Import file does not exist: {$path}");
        }

        if ($options['reset_source'] && ! $options['dry_run']) {
            $this->resetSource($options['reset_source']);
        }

        if ($options['dry_run']) {
            return $this->scan($path, $options);
        }

        $batch = AiImportBatch::create([
            'source' => $options['source'],
            'file_path' => $path,
            'status' => 'running',
            'options' => $options,
            'started_at' => now(),
        ]);

        try {
            $result = $this->writeRows($path, $options, $batch);
            $batch->update([
                'status' => $result['errors'] > 0 ? 'completed_with_errors' : 'completed',
                'session_count' => $result['sessions'],
                'message_count' => $result['messages'],
                'error_count' => $result['errors'],
                'error_message' => $result['errors'] ? implode("\n", array_slice($result['error_messages'], 0, 20)) : null,
                'finished_at' => now(),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $batch->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    private function scan($path, array $options)
    {
        $result = $this->emptyResult();

        foreach ($this->readLines($path, $options) as $lineNumber => $session) {
            if (! $this->includeSession($session, $options)) {
                continue;
            }

            $error = $this->validateSession($session);
            if ($error) {
                $result['errors']++;
                $result['error_messages'][] = "Line {$lineNumber}: {$error}";
                continue;
            }

            $result['sessions']++;
            $result['messages'] += count($session['messages']);
        }

        return $result;
    }

    private function writeRows($path, array $options, AiImportBatch $batch)
    {
        $result = $this->emptyResult();

        foreach ($this->readLines($path, $options) as $lineNumber => $session) {
            if (! $this->includeSession($session, $options)) {
                continue;
            }

            $error = $this->validateSession($session);
            if ($error) {
                $result['errors']++;
                $result['error_messages'][] = "Line {$lineNumber}: {$error}";
                continue;
            }

            DB::transaction(function () use ($session, $batch, &$result) {
                $model = AiSession::updateOrCreate(
                    [
                        'source' => $session['source'],
                        'external_id' => $session['external_id'],
                    ],
                    [
                        'import_batch_id' => $batch->id,
                        'title' => Arr::get($session, 'title'),
                        'workspace_path' => Arr::get($session, 'workspace_path'),
                        'model' => Arr::get($session, 'model'),
                        'started_at' => $this->parseDate(Arr::get($session, 'started_at')),
                        'ended_at' => $this->parseDate(Arr::get($session, 'ended_at')),
                        'message_count' => count($session['messages']),
                        'source_path' => Arr::get($session, 'source_path'),
                        'metadata' => Arr::get($session, 'metadata', []),
                    ]
                );

                foreach ($session['messages'] as $index => $message) {
                    $seq = Arr::get($message, 'seq', $index);
                    AiMessage::updateOrCreate(
                        [
                            'ai_session_id' => $model->id,
                            'seq' => $seq,
                        ],
                        [
                            'role' => Arr::get($message, 'role'),
                            'type' => Arr::get($message, 'type'),
                            'occurred_at' => $this->parseDate(Arr::get($message, 'occurred_at')),
                            'content' => Arr::get($message, 'content'),
                            'tool_name' => Arr::get($message, 'tool_name'),
                            'metadata' => Arr::get($message, 'metadata', []),
                            'raw' => Arr::get($message, 'raw', []),
                        ]
                    );
                }

                $result['sessions']++;
                $result['messages'] += count($session['messages']);
            });
        }

        return $result;
    }

    private function readLines($path, array $options)
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException("Unable to open import file: {$path}");
        }

        $lineNumber = 0;
        $yielded = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if (! is_array($decoded)) {
                    yield $lineNumber => ['_parse_error' => json_last_error_msg()];
                } else {
                    yield $lineNumber => $decoded;
                }

                $yielded++;
                if ($options['limit'] && $yielded >= (int) $options['limit']) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function validateSession(array $session)
    {
        if (isset($session['_parse_error'])) {
            return $session['_parse_error'];
        }

        foreach (['source', 'external_id', 'messages'] as $field) {
            if (! array_key_exists($field, $session)) {
                return "Missing required field [{$field}]";
            }
        }

        if (! in_array($session['source'], ['codex', 'cursor'], true)) {
            return 'Invalid source ['.$session['source'].']';
        }

        if (! is_array($session['messages'])) {
            return 'Messages must be an array';
        }

        return null;
    }

    private function includeSession(array $session, array $options)
    {
        if (! $options['source']) {
            return true;
        }

        return Arr::get($session, 'source') === $options['source'];
    }

    private function parseDate($value)
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resetSource($source)
    {
        $sessionIds = AiSession::where('source', $source)->pluck('id');
        if ($sessionIds->isNotEmpty()) {
            AiMessage::whereIn('ai_session_id', $sessionIds)->delete();
            AiSession::whereIn('id', $sessionIds)->delete();
        }
    }

    private function emptyResult()
    {
        return [
            'sessions' => 0,
            'messages' => 0,
            'errors' => 0,
            'error_messages' => [],
        ];
    }
}
