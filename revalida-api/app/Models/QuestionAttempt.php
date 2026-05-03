<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionAttempt extends Model
{
    protected $fillable = [
        'user_id',
        'question_id',
        'study_category',
        'period_start',
        'attempted_at',
        'is_correct',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'attempted_at' => 'datetime',
            'is_correct' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}

