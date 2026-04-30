<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Simulation extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'area',
        'status',
        'started_at',
        'ended_at',
        'duration_seconds',
        'elapsed_seconds',
        'total_questions',
        'answered_questions',
        'correct_questions',
        'accuracy',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'elapsed_seconds' => 'integer',
            'total_questions' => 'integer',
            'answered_questions' => 'integer',
            'correct_questions' => 'integer',
            'accuracy' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
