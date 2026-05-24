<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiImportBatch extends Model
{
    protected $fillable = [
        'source',
        'file_path',
        'status',
        'session_count',
        'message_count',
        'error_count',
        'options',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'options' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function sessions()
    {
        return $this->hasMany(AiSession::class, 'import_batch_id');
    }
}
