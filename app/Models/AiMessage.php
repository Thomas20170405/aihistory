<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMessage extends Model
{
    protected $fillable = [
        'ai_session_id',
        'seq',
        'role',
        'type',
        'occurred_at',
        'content',
        'tool_name',
        'metadata',
        'raw',
    ];

    protected $casts = [
        'metadata' => 'array',
        'raw' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(AiSession::class, 'ai_session_id');
    }
}
