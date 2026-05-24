<?php

namespace App\Console\Commands;

use App\Models\AiMessage;
use App\Models\AiSession;
use Illuminate\Console\Command;

class CleanAiHistory extends Command
{
    protected $signature = 'ai-history:clean
        {--cursor-empty : Delete cursor sessions with zero messages}
        {--dry-run : Count rows without deleting}';

    protected $description = 'Clean low-value AI history rows';

    public function handle()
    {
        if (! $this->option('cursor-empty')) {
            $this->warn('Nothing selected. Use --cursor-empty.');
            return 0;
        }

        $sessionIds = AiSession::where('source', 'cursor')
            ->where('message_count', 0)
            ->pluck('id');

        $messageCount = AiMessage::whereIn('ai_session_id', $sessionIds)->count();

        $this->info('Cursor empty sessions: '.$sessionIds->count());
        $this->info('Related messages: '.$messageCount);

        if ($this->option('dry-run')) {
            return 0;
        }

        AiMessage::whereIn('ai_session_id', $sessionIds)->delete();
        AiSession::whereIn('id', $sessionIds)->delete();

        $this->info('Deleted cursor empty sessions.');

        return 0;
    }
}
