<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiSession extends Model
{
    protected $fillable = [
        'import_batch_id',
        'source',
        'external_id',
        'title',
        'workspace_path',
        'model',
        'started_at',
        'ended_at',
        'message_count',
        'source_path',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function importBatch()
    {
        return $this->belongsTo(AiImportBatch::class, 'import_batch_id');
    }

    public function messages()
    {
        return $this->hasMany(AiMessage::class, 'ai_session_id')->orderBy('seq');
    }
}
